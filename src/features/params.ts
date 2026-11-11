import { Hono } from 'hono'
import { COMMON_HEADERS, notFound } from '../lib/http.ts'
import type { App } from '../types.ts'
import { publicParams } from '../lib/public-params.ts'

/**
 * O instalador Delphi consome INI; o app Electron consome JSON. Mesmo conteúdo.
 * As quebras são CRLF porque é o que a origem emite e o que o parser Pascal
 * espera.
 */
function toIni(params: Record<string, unknown>): string {
  return `${Object.entries(params)
    .map(([k, v]) => `${k}=${v}`)
    .join('\r\n')}\r\n`
}

const WORKERS_DEV_ORIGIN = 'https://api.louvorja.workers.dev'
const PUBLIC_API_ORIGIN = 'https://api.louvorja.com.br'

export const params = new Hono<App>()
// É por aqui que o app descobre que há catálogo novo (`https_db_version`). Uma hora,
// como o manifesto: é o atraso máximo para perceber uma publicação.
const PARAMS_CACHE = 'public, max-age=3600, stale-while-revalidate=86400'

params.on(['GET', 'HEAD'], '/params', async (c) => {
  const object = await c.env.FILES.get('meta/params.json')
  if (object === null) return notFound(c)

  const data = publicParams(await object.json<Record<string, unknown>>())
  if ('https_db_version' in data) data.db_version = data.https_db_version
  else delete data.db_version

  // O Delphi chama os endereços que lê daqui (vídeos online, por exemplo), e a
  // Cloudflare recusa a identificação dele no workers.dev. Tudo que aponta para
  // esta API sai com o domínio antigo, que os apps novos também alcançam.
  for (const [key, value] of Object.entries(data)) {
    if (typeof value === 'string' && value.startsWith(`${WORKERS_DEV_ORIGIN}/`)) {
      data[key] = PUBLIC_API_ORIGIN + value.slice(WORKERS_DEV_ORIGIN.length)
    }
  }
  const headers = new Headers(COMMON_HEADERS)
  headers.set('Cache-Control', PARAMS_CACHE)

  if (c.req.query('type') === 'env') {
    headers.set('Content-Type', 'text/plain; charset=UTF-8')
    return new Response(toIni(data), { headers })
  }
  headers.set('Content-Type', 'application/json')
  return new Response(JSON.stringify(data), { headers })
})
