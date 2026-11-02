import { type Context, Hono, type MiddlewareHandler } from 'hono'
import { COMMON_HEADERS, notFound } from '../lib/http.ts'
import { type MediaVariant, withMediaUrls } from '../lib/media-urls.ts'
import { paginate } from '../lib/paginate.ts'
import type { App } from '../types.ts'

const LANGS = new Set(['pt', 'es'])
// Uma hora no cliente e na borda. Cada resposta sem cache é uma requisição a
// mais na cota diária do plano gratuito, e o catálogo muda poucas vezes por dia.
const CACHE = 'public, max-age=3600, stale-while-revalidate=86400'
/** Teto de registros de download por dia: mantém a escrita no D1 previsível. */
const DOWNLOAD_LOGS_PER_DAY = 500

export const rest = new Hono<App>()

function json(body: unknown, cache = CACHE, variant?: MediaVariant): Response {
  const headers = new Headers(COMMON_HEADERS)
  headers.set('Content-Type', 'application/json')
  headers.set('Cache-Control', cache)
  const text = JSON.stringify(body)
  return new Response(variant ? withMediaUrls(text, variant) : text, { headers })
}

/** A paginação depende da origem pedida: não pode usar o cache compartilhado. */
function catalogJson(body: unknown): Response {
  return json(body, 'private, max-age=3600', 'current')
}

/** Lê uma coleção do snapshot REST em rest/. */
async function load<T>(c: Context<App>, key: string): Promise<T | null> {
  const object = await c.env.FILES.get(`rest/${key}.json`)
  return object === null ? null : await object.json<T>()
}

type Row = Record<string, unknown>

rest.get('/health', async (c) => {
  const [database, storage] = await Promise.allSettled([
    c.env.DB.prepare('SELECT 1 AS ok').first(),
    c.env.FILES.head('meta/params.json'),
  ])
  const databaseOk = database.status === 'fulfilled' && database.value !== null
  const storageOk = storage.status === 'fulfilled' && storage.value !== null
  const healthy = databaseOk && storageOk
  return c.json(
    {
      status: healthy ? 'ok' : 'error',
      timestamp: new Date().toISOString(),
      version: '1.0.0',
      services: {
        database: databaseOk ? 'ok' : 'error',
        storage: storageOk ? 'ok' : 'error',
      },
    },
    healthy ? 200 : 503,
    { 'Cache-Control': 'no-store' },
  )
})

rest.get('/version', async (c) => {
  const object = await c.env.FILES.get('meta/params.json')
  const params = object === null ? {} : await object.json<Record<string, unknown>>()
  return json({
    api_version: '1.0.0',
    min_client_version: '1.0.0',
    runtime: 'Cloudflare Workers',
    // A versão atual do catálogo é anunciada por https_db_version.
    db_version: params.https_db_version ?? null,
  })
})

rest.get('/metadata', async (c) => {
  const data = await load<unknown>(c, 'metadata')
  return data === null ? notFound(c) : json(data)
})

/**
 * A origem interpola o parâmetro direto no atributo do iframe, então um valor
 * com aspas escapa do HTML. Aqui o id é restrito ao alfabeto de vídeo do
 * YouTube antes de entrar na página.
 */
const YT_ID_RE = /^[A-Za-z0-9_-]{1,32}$/

rest.get('/player', (c) => {
  const v = c.req.query('v') ?? ''
  const id = YT_ID_RE.test(v) ? v : ''
  const headers = new Headers(COMMON_HEADERS)
  headers.set('Content-Type', 'text/html; charset=UTF-8')
  headers.set('Cache-Control', 'public, max-age=3600')
  return new Response(
    `<html>
<head>
<title>Player</title>
<style>
html,body{margin:0;padding:0;background:#000;}
iframe{position:absolute;width:100%;height:100%;top:0;left:0;border:0;}
</style>
</head>
<body>
<iframe src="https://www.youtube.com/embed/${id}" title="Player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen>
</iframe>
</body>
</html>`,
    { headers },
  )
})

const SLUG_RE = /^[a-zA-Z0-9_-]+$/

/**
 * DESCONTINUADAS: as duas rotas abaixo têm chave equivalente no json_db
 * (`{lang}_doxology_albums`, `{lang}_children_albums`, `{lang}_collections_online`).
 * Seguem no ar só para versões antigas do violin-app.
 */
const markDeprecated: MiddlewareHandler<App> = async (c, next) => {
  await next()
  c.header('Deprecation', 'true')
}
rest.use('/:lang/albums/category/:slug', markDeprecated)
rest.use('/:lang/collections/online', markDeprecated)

rest.get('/:lang/albums/category/:slug', async (c) => {
  const lang = c.req.param('lang') as string
  const slug = c.req.param('slug') as string
  if (!LANGS.has(lang) || !SLUG_RE.test(slug)) return notFound(c)
  const rows = await load<Row[]>(c, `${lang}_categoryslug_${slug}_albums`)
  return rows === null ? notFound(c) : catalogJson(paginate(rows, new URL(c.req.url)))
})

rest.get('/:lang/collections/online', async (c) => {
  const lang = c.req.param('lang') as string
  if (!LANGS.has(lang)) return notFound(c)
  const data = await load<unknown>(c, `${lang}_collections_online`)
  return data === null ? notFound(c) : json(data)
})

for (const path of ['/download', '/:lang/download']) {
  rest.get(path, async (c) => {
    const object = await c.env.FILES.get('meta/params.json')
    const params = object === null ? null : await object.json<Record<string, string>>()
    const pathLang = c.req.param('lang')?.toLowerCase()
    if (pathLang && !LANGS.has(pathLang)) return notFound(c)
    const queryLang = c.req.query('lang')?.toLowerCase()
    const lang = pathLang ?? (queryLang && LANGS.has(queryLang) ? queryLang : 'pt')
    const download = params?.[`${lang}_download`] ?? params?.pt_download
    if (!download) return notFound(c)

    // Rota sem autenticação que grava no banco: um contador por dia limita os
    // registros, e depois do teto a chamada não escreve mais nada. Sem a tabela
    // do contador (migration ainda não aplicada) o registro simplesmente falha.
    const forwarded = c.req.header('X-Forwarded-For') ?? null
    const logDownload = async () => {
      await c.env.DB.batch([
        c.env.DB.prepare(
          "INSERT INTO public_write_budget (day, name, used) VALUES (date('now'), 'download', 1) " +
            'ON CONFLICT (day, name) DO UPDATE SET used = used + 1 WHERE used <= ?',
        ).bind(DOWNLOAD_LOGS_PER_DAY),
        c.env.DB.prepare(
          'INSERT INTO download_logs ' +
            '(version, ip, http_client_ip, http_x_forwarded_for, remote_addr, browser, id_language, created_at, updated_at) ' +
            "SELECT ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now') " +
            "WHERE (SELECT used FROM public_write_budget WHERE day = date('now') AND name = 'download') <= ?",
        ).bind(
          params?.[`${lang}_version`] ?? '',
          c.req.header('CF-Connecting-IP') ?? forwarded?.split(',')[0]?.trim() ?? null,
          c.req.header('Client-IP') ?? null,
          forwarded,
          c.req.header('Remote-Addr') ?? null,
          c.req.header('User-Agent') ?? null,
          lang,
          DOWNLOAD_LOGS_PER_DAY,
        ),
      ])
    }
    c.executionCtx.waitUntil(
      logDownload().catch((reason) => console.error('download_log_failed', reason)),
    )
    return c.redirect(download, 302)
  })
}
