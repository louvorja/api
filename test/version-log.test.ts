import { createExecutionContext, env, waitOnExecutionContext } from 'cloudflare:test'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import worker from '../src/index.ts'

const fetchMock = vi.fn<typeof fetch>()

async function call(path: string, init?: RequestInit) {
  const context = createExecutionContext()
  const response = await worker.fetch(new Request(`https://api.test${path}`, init), env, context)
  await waitOnExecutionContext(context)
  return response
}

beforeEach(async () => {
  vi.stubGlobal('fetch', fetchMock)
  fetchMock.mockReset()
  const notes = await env.FILES.list({ prefix: 'meta/version_log' })
  for (const object of notes.objects) await env.FILES.delete(object.key)
  await env.FILES.put(
    'meta/params.json',
    JSON.stringify({ pt_version: '26.11.0', es_version: '26.10.2' }),
  )
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('/version_log', () => {
  it('busca a release correspondente e escapa as notas antes de montar o HTML', async () => {
    fetchMock.mockResolvedValue(
      Response.json({ body: 'Correção importante\n<script>alert(1)</script>' }),
    )

    const response = await call('/version_log?lang=pt')
    const html = await response.text()

    expect(response.status).toBe(200)
    expect(fetchMock).toHaveBeenCalledWith(
      'https://api.github.com/repos/louvorja/desktop/releases/tags/v26.11',
      expect.objectContaining({ signal: expect.any(AbortSignal) }),
    )
    expect(html).toContain('<h1>26.11.0</h1>')
    expect(html).toContain('Correção importante<br>&lt;script&gt;alert(1)&lt;/script&gt;')
    expect(html).not.toContain('<script>alert(1)</script>')
  })

  it('aceita os nomes version e versao e devolve mensagem útil para release ausente', async () => {
    fetchMock.mockResolvedValue(Response.json({ message: 'Not Found' }, { status: 404 }))

    const response = await call('/version_log?versao=25.9.7&lang=es')
    const html = await response.text()

    expect(fetchMock).toHaveBeenCalledWith(
      'https://api.github.com/repos/louvorja/desktop/releases/tags/v25.9',
      expect.any(Object),
    )
    expect(html).toContain('<h1>25.9.7</h1>')
    expect(html).toContain('Não foi possivel encontrar informações sobre a versão 25.9.7!')
  })

  it('não consulta a rede para uma versão inválida', async () => {
    const response = await call('/version_log?version=%3Cscript%3E')
    expect(response.status).toBe(200)
    expect(fetchMock).not.toHaveBeenCalled()
    expect(await response.text()).toContain('&lt;script&gt;')
  })

  it('reutiliza as notas da mesma release entre idiomas e versões do catálogo', async () => {
    fetchMock.mockResolvedValue(Response.json({ body: 'Notas da versão 27.0' }))
    const first = await call('/version_log?lang=PT&version=27.0.186')
    expect(await first.text()).toContain('<h1>27.0.186</h1>Notas da versão 27.0')
    expect(await (await env.FILES.get('meta/version_log/v27.0.txt'))?.text()).toBe(
      'Notas da versão 27.0',
    )

    fetchMock.mockRejectedValue(new Error('GitHub indisponível'))
    const next = await call('/version_log?lang=ES&versao=27.0.187')
    expect(await next.text()).toContain('<h1>27.0.187</h1>Notas da versão 27.0')
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('serve notas publicadas pela manutenção sem depender do GitHub e mantém o escape HTML', async () => {
    await env.FILES.put(
      'meta/version_log/v26.11.txt',
      'Salvo <script>alert(1)</script>\nNova linha',
    )
    const response = await call('/version_log?lang=pt')
    expect(response.status).toBe(200)
    expect(await response.text()).toContain(
      'Salvo &lt;script&gt;alert(1)&lt;/script&gt;<br>Nova linha',
    )
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it.each([403, 429, 500])(
    'não entrega notas de outra versão nem guarda a falha HTTP %s em cache',
    async (status) => {
      const log = vi.spyOn(console, 'error').mockImplementation(() => {})
      await env.FILES.put('meta/version_log.html', '<h1>26.10</h1>Notas antigas')
      await env.FILES.put('meta/version_log/v26.10.txt', 'Notas antigas')
      fetchMock.mockResolvedValue(new Response('erro', { status }))

      const response = await call('/version_log?lang=PT&version=27.0.186')
      const html = await response.text()
      expect(response.status).toBe(200)
      expect(response.headers.get('Cache-Control')).toBe('no-store')
      expect(html).toContain('Não foi possível carregar as informações da versão 27.0.186')
      expect(html).not.toContain('26.10')
      expect(html).not.toContain('Notas antigas')
      expect(log).toHaveBeenCalledWith('version_log_fetch_failed', {
        softwareVersion: '27.0',
        upstreamStatus: status,
        reason: `GitHub respondeu ${status}`,
      })
      expect(await env.FILES.get('meta/version_log/v27.0.txt')).toBeNull()

      fetchMock.mockResolvedValue(Response.json({ body: 'Notas recuperadas' }))
      expect(await (await call('/version_log?version=27.0.186')).text()).toContain(
        'Notas recuperadas',
      )
    },
  )

  it('registra o motivo de uma falha de rede e permite tentar novamente', async () => {
    const log = vi.spyOn(console, 'error').mockImplementation(() => {})
    fetchMock.mockRejectedValue(new DOMException('Tempo esgotado', 'TimeoutError'))
    const response = await call('/version_log?version=27.0.186')
    expect(response.headers.get('Cache-Control')).toBe('no-store')
    expect(log).toHaveBeenCalledWith('version_log_fetch_failed', {
      softwareVersion: '27.0',
      upstreamStatus: undefined,
      reason: 'Tempo esgotado',
    })
  })

  it('não persiste uma resposta inválida do GitHub', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    fetchMock.mockResolvedValue(Response.json({ body: 123 }))
    const response = await call('/version_log?version=27.0.186')
    expect(response.headers.get('Cache-Control')).toBe('no-store')
    expect(await env.FILES.get('meta/version_log/v27.0.txt')).toBeNull()
  })

  it('aceita release sem descrição e não repete a consulta ao GitHub', async () => {
    const log = vi.spyOn(console, 'error').mockImplementation(() => {})
    fetchMock.mockResolvedValue(Response.json({ body: null }))
    const first = await call('/version_log?version=27.0.186')
    expect(await first.text()).toContain('<h1>27.0.186</h1></body>')
    await call('/version_log?version=27.0.187')
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(log).not.toHaveBeenCalled()
  })

  it('mantém as notas recebidas quando somente a gravação no R2 falha', async () => {
    const log = vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(env.FILES, 'put').mockRejectedValue(new Error('R2 indisponível'))
    fetchMock.mockResolvedValue(Response.json({ body: 'Notas recebidas' }))
    const response = await call('/version_log?version=27.0.186')
    expect(await response.text()).toContain('Notas recebidas')
    expect(log).toHaveBeenCalledWith('version_log_store_failed', {
      softwareVersion: '27.0',
      reason: 'R2 indisponível',
    })
    expect(log).toHaveBeenCalledTimes(1)
  })

  it('responde HEAD sem corpo usando as notas salvas', async () => {
    await env.FILES.put('meta/version_log/v27.0.txt', 'Notas salvas')
    const response = await call('/version_log?version=27.0.186', { method: 'HEAD' })
    expect(response.status).toBe(200)
    expect(response.headers.get('Content-Type')).toBe('text/html; charset=UTF-8')
    expect(await response.text()).toBe('')
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
