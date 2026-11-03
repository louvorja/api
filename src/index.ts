import { Hono } from 'hono'
import { admin } from './features/admin.ts'
import { auth } from './features/auth.ts'
import { db } from './features/db.ts'
import { files } from './features/files.ts'
import { jsonDb } from './features/json-db.ts'
import { legacy } from './features/legacy.ts'
import { onlineVideos } from './features/online-videos.ts'
import { params } from './features/params.ts'
import { rest } from './features/rest.ts'
import { tasks } from './features/tasks.ts'
import { requireSecret } from './lib/auth/session.ts'
import { COMMON_HEADERS, isPrivatePath, PRIVATE_HEADERS, routeNotFound } from './lib/http.ts'
import { applyRateLimit } from './lib/rate-limit.ts'
import { blockScannerPaths } from './lib/scanner-paths.ts'
import type { App } from './types.ts'

// O wrangler exige que a classe do Durable Object saia do módulo principal.
export { WindowCounter } from './lib/window-counter.ts'

const app = new Hono<App>()

app.use('*', async (c, next) => {
  await next()
  const headers = isPrivatePath(c.req.path) ? PRIVATE_HEADERS : COMMON_HEADERS
  for (const [k, v] of Object.entries(headers)) c.header(k, v)
})

app.use('*', blockScannerPaths)
app.use('*', applyRateLimit)

app.options('*', (c) => c.body(null, 204))

app.get('/', async (c) => {
  const object = await c.env.FILES.get('meta/params.json')
  const current = object === null ? {} : await object.json<Record<string, unknown>>()
  c.header('Cache-Control', 'public, max-age=300')
  return c.json({
    name: 'api-louvorja',
    endpoints: [
      '/json_db/:key',
      '/db/manifest',
      '/db/bundle',
      '/db/catalog-bundle',
      '/db/bible-bundle',
      '/db/:key',
      '/file/* (descontinuado: use o endereço completo do catálogo)',
      '/params',
      '/version_log',
      '/onlinevideos',
      '/health',
      '/version',
      '/metadata',
      '/{lang}/albums/category/{slug}',
      '/{lang}/collections/online',
      '/{lang}/download',
      '/openapi.json',
    ],
    // Quem quer o acervo inteiro baixa um arquivo, em vez de um pedido por item.
    bulk: {
      '/db/bible-bundle':
        'ZIP com todos os capítulos da Bíblia, um arquivo bible_{versão}_{livro}_{capítulo}.json ' +
        'por capítulo. Para baixar a Bíblia inteira, use este arquivo em vez de pedir ' +
        '/json_db/bible_... capítulo por capítulo.',
      '/db/catalog-bundle': 'ZIP com o catálogo sem capítulos bíblicos, para a instalação inicial.',
      '/db/bundle': 'ZIP com todas as tabelas do catálogo (álbuns, músicas, hinário e Bíblia).',
    },
    // A versão atual do catálogo é anunciada por https_db_version.
    db_version: current.https_db_version ?? null,
  })
})

app.route('/', jsonDb)
app.route('/', db)
app.route('/', files)
app.route('/', params)
app.route('/', onlineVideos)
app.route('/', legacy)
// Antes do rest: /auth/me, /admin/musics e /tasks/* casariam com /:lang/:collection.
app.use('/auth/*', requireSecret)
app.use('/admin/*', requireSecret)
app.use('/tasks', requireSecret)
app.use('/tasks/*', requireSecret)
app.route('/', auth)
app.route('/', admin)
app.route('/', tasks)
// Por último: a rota genérica /:lang/:collection casaria com /json_db, /db e
// /file se viesse antes das rotas específicas.
app.route('/', rest)

app.notFound(routeNotFound)

app.onError((err, c) => {
  console.error('unhandled', err)
  c.header('Cache-Control', 'no-store')
  return c.json({ error: 'Erro interno' }, 500)
})

export default app
