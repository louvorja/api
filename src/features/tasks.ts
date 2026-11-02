import { Hono, type MiddlewareHandler } from 'hono'
import { requireAuth } from '../lib/auth/session.ts'
import type { App } from '../types.ts'

export const TASK_NAMES = [
  'refresh_configs',
  'export_database',
  'refresh_files_size',
  'refresh_files_duration',
  'refresh_online_videos',
  'import_slides',
  'export_database_json',
  'generate_static_jsons',
] as const

type TaskName = (typeof TASK_NAMES)[number]

/**
 * A publicação substitui quatro etapas da API PHP por uma única geração dos
 * snapshots JSON. A geração dos bancos SQLite ocorre no workflow de manutenção.
 */
export const WORKFLOW_TASK: Record<TaskName, string> = {
  refresh_configs: 'publish',
  export_database: 'publish',
  refresh_files_size: 'refresh_files_size',
  refresh_files_duration: 'refresh_files_duration',
  refresh_online_videos: 'refresh_online_videos',
  import_slides: 'import_slides',
  export_database_json: 'publish',
  generate_static_jsons: 'publish',
}

const DEFAULT_REPO = 'louvorja/api-workers'
const DEFAULT_REF = 'main'
const WORKFLOW_FILE = 'maintenance.yml'
const DISPATCH_TIMEOUT_MS = 10_000
const REPO_RE = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/

const requireAdmin: MiddlewareHandler<App> = async (c, next) => {
  if (!c.var.user.is_admin) {
    return c.json({ error: 'Acesso restrito ao administrador.' }, 403)
  }
  await next()
}

const isTaskName = (value: string): value is TaskName => TASK_NAMES.some((task) => task === value)

const configured = (value: string | undefined) => {
  const normalized = value?.trim()
  return normalized ? normalized : undefined
}

export const tasks = new Hono<App>()

// O middleware explícito nos dois padrões protege tanto /tasks quanto os filhos.
tasks.use('/tasks', requireAuth, requireAdmin)
tasks.use('/tasks/*', requireAuth, requireAdmin)

tasks.get('/tasks', (c) => c.json(TASK_NAMES))

tasks.get('/tasks/:task', async (c) => {
  const task = c.req.param('task')
  if (!isTaskName(task)) return c.json({ error: 'Tarefa não encontrada.' }, 404)

  const token = configured(c.env.GITHUB_TASK_TOKEN)
  const repo = configured(c.env.GITHUB_TASK_REPO) ?? DEFAULT_REPO
  const ref = configured(c.env.GITHUB_TASK_REF) ?? DEFAULT_REF
  if (!token || !REPO_RE.test(repo)) {
    console.error('Configuração do dispatcher de tarefas ausente ou inválida')
    return c.json({ error: 'Dispatcher de tarefas não configurado.' }, 503)
  }

  const [owner, repository] = repo.split('/') as [string, string]
  const workflowTask = WORKFLOW_TASK[task]
  const url =
    `https://api.github.com/repos/${encodeURIComponent(owner)}/${encodeURIComponent(repository)}` +
    `/actions/workflows/${WORKFLOW_FILE}/dispatches`

  let response: Response
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/vnd.github+json',
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        'User-Agent': 'api-louvorja',
        'X-GitHub-Api-Version': '2022-11-28',
      },
      body: JSON.stringify({ ref, inputs: { task: workflowTask } }),
      signal: AbortSignal.timeout(DISPATCH_TIMEOUT_MS),
    })
  } catch (error) {
    const timedOut = error instanceof DOMException && error.name === 'TimeoutError'
    console.error('Falha de rede ao despachar tarefa no GitHub Actions', error)
    return c.json(
      {
        error: timedOut
          ? 'Tempo esgotado ao agendar tarefa.'
          : 'Falha ao acessar o dispatcher de tarefas.',
      },
      timedOut ? 504 : 502,
    )
  }

  if (response.status !== 204) {
    const detail = (await response.text()).slice(0, 500)
    console.error('GitHub Actions recusou o despacho da tarefa', {
      status: response.status,
      detail,
    })
    return c.json({ error: 'Dispatcher de tarefas recusou a solicitação.' }, 502)
  }

  return c.json(
    {
      status: 'accepted',
      task,
      workflow_task: workflowTask,
    },
    202,
  )
})
