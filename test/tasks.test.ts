import {
  applyD1Migrations,
  createExecutionContext,
  env,
  waitOnExecutionContext,
} from 'cloudflare:test'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { TASK_NAMES, WORKFLOW_TASK } from '../src/features/tasks.ts'
import worker from '../src/index.ts'
import { hashPassword } from '../src/lib/auth/password.ts'
import type { Bindings } from '../src/types.ts'

type CallInit = RequestInit & {
  token?: string
  bindings?: Partial<Bindings>
  json?: unknown
}

const workerBindings = (overrides: Partial<Bindings> = {}): Bindings => ({
  FILES: env.FILES,
  FILES_ORIGINALS: env.FILES_ORIGINALS,
  DB: env.DB,
  RATE_LIMIT_FILES: env.RATE_LIMIT_FILES,
  RATE_LIMIT_METADATA: env.RATE_LIMIT_METADATA,
  RATE_LIMIT_GENERAL: env.RATE_LIMIT_GENERAL,
  BIBLE_LIMITER: env.BIBLE_LIMITER,
  JWT_SECRET: env.JWT_SECRET,
  GITHUB_TASK_TOKEN: 'github-token-test',
  GITHUB_TASK_REF: 'release-test',
  GITHUB_TASK_REPO: 'louvorja/tasks-test',
  ...overrides,
})

const call = async (path: string, init: CallInit = {}) => {
  const { bindings, json, token, ...requestInit } = init
  const headers = new Headers(requestInit.headers)
  if (token) headers.set('Authorization', `Bearer ${token}`)
  if (json !== undefined) headers.set('Content-Type', 'application/json')
  const ctx = createExecutionContext()
  const response = await worker.fetch(
    new Request(`https://api.test${path}`, {
      ...requestInit,
      headers,
      body: json === undefined ? requestInit.body : JSON.stringify(json),
    }),
    workerBindings(bindings),
    ctx,
  )
  await waitOnExecutionContext(ctx)
  return response
}

const login = async (username: string) => {
  const response = await call('/auth/login', {
    method: 'POST',
    json: { username, password: 'Senha@123' },
  })
  const body = (await response.json()) as { token: string }
  return body.token
}

const fetchMock = vi.fn<typeof fetch>()

let adminToken: string
let editorToken: string

beforeAll(async () => {
  await applyD1Migrations(env.DB, env.TEST_MIGRATIONS)
  const password = await hashPassword('Senha@123')
  const now = '2026-10-10 12:00:00'
  await env.DB.prepare(
    'INSERT INTO users ' +
      '(id, name, username, email, password, is_temporary_password, is_admin, permissions, created_at, updated_at) ' +
      'VALUES (901, ?1, ?2, ?3, ?4, 0, 1, NULL, ?5, ?5), ' +
      "(902, ?6, ?7, ?8, ?4, 0, 0, '[]', ?5, ?5)",
  )
    .bind(
      'Admin Tasks',
      'tasks-admin',
      'tasks-admin@louvorja.test',
      password,
      now,
      'Editor Tasks',
      'tasks-editor',
      'tasks-editor@louvorja.test',
    )
    .run()

  adminToken = await login('tasks-admin')
  editorToken = await login('tasks-editor')
})

beforeEach(() => {
  fetchMock.mockReset()
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('tasks', () => {
  it('aceita somente a sessão JWT de um administrador', async () => {
    const anonymous = await call('/tasks', {
      headers: { 'Api-Token': 'token-publico-delphi' },
    })
    expect(anonymous.status).toBe(401)

    const editor = await call('/tasks', { token: editorToken })
    expect(editor.status).toBe(403)
    expect(await editor.json()).toEqual({ error: 'Acesso restrito ao administrador.' })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('lista as oito tarefas legadas sem despachar workflow', async () => {
    const response = await call('/tasks', { token: adminToken })
    expect(response.status).toBe(200)
    expect(response.headers.get('cache-control')).toBe('no-store')
    expect(await response.json()).toEqual(TASK_NAMES)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('despacha cada tarefa e converge os quatro aliases em publish', async () => {
    fetchMock.mockImplementation(async () => new Response(null, { status: 204 }))

    for (const task of TASK_NAMES) {
      const response = await call(`/tasks/${task}`, { token: adminToken })
      expect(response.status).toBe(202)
      expect(await response.json()).toEqual({
        status: 'accepted',
        task,
        workflow_task: WORKFLOW_TASK[task],
      })
    }

    expect(fetchMock).toHaveBeenCalledTimes(TASK_NAMES.length)
    for (const [index, task] of TASK_NAMES.entries()) {
      const [url, init] = fetchMock.mock.calls[index] ?? []
      expect(url).toBe(
        'https://api.github.com/repos/louvorja/tasks-test/actions/workflows/maintenance.yml/dispatches',
      )
      expect(init?.method).toBe('POST')
      expect(init?.headers).toMatchObject({
        Authorization: 'Bearer github-token-test',
        'X-GitHub-Api-Version': '2022-11-28',
      })
      expect(init?.signal).toBeInstanceOf(AbortSignal)
      expect(JSON.parse(String(init?.body))).toEqual({
        ref: 'release-test',
        inputs: { task: WORKFLOW_TASK[task] },
      })
    }
  })

  it('usa repositório e ref padrão quando os bindings opcionais não existem', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))
    const response = await call('/tasks/export_database', {
      token: adminToken,
      bindings: { GITHUB_TASK_REF: undefined, GITHUB_TASK_REPO: undefined },
    })

    expect(response.status).toBe(202)
    const [url, init] = fetchMock.mock.calls[0] ?? []
    expect(url).toBe(
      'https://api.github.com/repos/louvorja/api-workers/actions/workflows/maintenance.yml/dispatches',
    )
    expect(JSON.parse(String(init?.body))).toEqual({
      ref: 'main',
      inputs: { task: 'publish' },
    })
  })

  it('responde 503 explícito quando o token do dispatcher está ausente', async () => {
    const response = await call('/tasks/refresh_configs', {
      token: adminToken,
      bindings: { GITHUB_TASK_TOKEN: undefined },
    })
    expect(response.status).toBe(503)
    expect(await response.json()).toEqual({ error: 'Dispatcher de tarefas não configurado.' })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('valida a resposta do GitHub e converte falha upstream em 502', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ message: 'workflow not found' }), { status: 404 }),
    )
    const response = await call('/tasks/import_slides', { token: adminToken })
    expect(response.status).toBe(502)
    expect(await response.json()).toEqual({
      error: 'Dispatcher de tarefas recusou a solicitação.',
    })
  })

  it('distingue timeout de outras falhas de rede', async () => {
    fetchMock.mockRejectedValueOnce(new DOMException('demorou', 'TimeoutError'))
    expect((await call('/tasks/refresh_files_size', { token: adminToken })).status).toBe(504)

    fetchMock.mockRejectedValueOnce(new Error('rede indisponível'))
    expect((await call('/tasks/refresh_files_size', { token: adminToken })).status).toBe(502)
  })

  it('não despacha nomes fora da lista', async () => {
    const response = await call('/tasks/unknown_task', { token: adminToken })
    expect(response.status).toBe(404)
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
