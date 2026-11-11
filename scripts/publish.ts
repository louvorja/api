/**
 * Publica o catálogo a partir do banco: o que o painel alterou no D1 vira os
 * JSON de /json_db, o manifesto, o pacote ZIP e a camada REST que os apps leem.
 *
 * Substitui o trio gate + ingest + ingest-rest, que copiava isso da API de
 * origem. Sem `--write` só mostra o que mudaria.
 *
 *   npx wrangler d1 export louvorja --remote --output=replica.sql
 *   node --env-file=.env --experimental-strip-types scripts/publish.ts --export=replica.sql [--write]
 *
 * A Bíblia é estática e fica como está no bucket: os capítulos publicados são
 * preservados, inclusive quando os pacotes ganham novos manifestos. Só se alguém carregar os
 * versículos no D1 (`seed-d1.ts --bible`, fora do plano) eles passam a sair
 * da réplica junto com o resto.
 */
import { execFileSync } from 'node:child_process'
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  rmSync,
  unlinkSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { DatabaseSync } from 'node:sqlite'
import { BUNDLE_KEY } from '../src/lib/media-urls.ts'
import { type BundleArtifact, buildBundleArtifacts } from './lib/bundle-variants.ts'
import { generateBible, generateCatalog, inspectBiblePublication } from './lib/catalog.ts'
import { assertCutoverAdmin } from './lib/cutover.ts'
import { localD1 } from './lib/d1-local.ts'
import { pool } from './lib/legacy.ts'
import {
  type Config,
  catalogVersionChanged,
  isBibleTable,
  type ManifestEntry,
  md5,
  nextConfig,
  type Plan,
  PUBLISHED_LOG_KEY,
  planJsonDb,
  planRest,
} from './lib/publication.ts'
import * as r2 from './lib/r2.ts'
import { generateRest } from './lib/rest.ts'
import { reconcileRestMedia } from './lib/rest-media.ts'

const args = new Map(
  process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=') as [string, string]),
)
const EXPORT = args.get('export')
const WRITE = args.has('write')
const API = args.get('api') ?? 'https://api.louvorja.workers.dev'
const DATABASE = args.get('database') ?? 'louvorja'
const STATE_KEY = 'state/publish.json'
const BIBLE_STATE_KEY = 'state/bible-publish.json'
const BIBLE_BUNDLE_KEY = 'meta/bible-bundle.zip'
/** As chaves de `configs` que uma publicação reescreve, com o tipo de cada uma. */
const VERSION_KEYS: Record<string, string> = {
  version_number: 'number',
  version: 'number',
  latest_updated: 'datetime',
  date: 'date',
  time: 'time',
  datetime: 'datetime',
}
const VERSIONED_TABLES = [
  'languages',
  'files',
  'albums',
  'categories',
  'musics',
  'lyrics',
  'albums_musics',
  'categories_albums',
  'bible_book',
  'bible_version',
  'bible_verse',
  'online_videos_channels',
  'online_videos_playlists',
  'online_videos',
] as const
if (!EXPORT) throw new Error('informe --export=<arquivo do wrangler d1 export>')

const decoder = new TextDecoder()
const text = async (key: string) => {
  const body = await r2.get(key)
  return body === null ? null : decoder.decode(body)
}

// A réplica fica em memória: a nova versão é gravada nela antes de gerar, e o
// arquivo exportado não é tocado.
const db = new DatabaseSync(':memory:')
db.exec(readFileSync(EXPORT, 'utf8'))
const cutoverAdmin = assertCutoverAdmin(db)
console.log(
  `Preflight administrativo: ${cutoverAdmin.supportedAdminCount} administrador(es) com PBKDF2 válido.`,
)

async function rest() {
  const current = await r2.mediaSizes()
  const out = new Map<string, string>()
  for await (const [key, json] of generateRest(localD1(db), API)) {
    const currentJson = reconcileRestMedia(json, current)
    out.set(key, currentJson)
  }
  return out
}

console.log('Lendo o que está publicado...')
const manifest = JSON.parse((await text('meta/manifest.json')) ?? '[]') as ManifestEntry[]
const state = JSON.parse((await text(STATE_KEY)) ?? '{}') as Record<string, string>
// Os artefatos antigos ficam preservados, mas não fazem parte da publicação atual.
for (const key of Object.keys(state)) {
  if (/^(pt|es)_files_legacy$/.test(key)) delete state[key]
}
const firstRun = Object.keys(state).length === 0

const workdir = mkdtempSync(join(tmpdir(), 'louvorja-publish-'))
const bundleDir = join(workdir, 'json')
mkdirSync(bundleDir)
const bundle = await r2.get(BUNDLE_KEY)
if (bundle !== null) {
  writeFileSync(join(workdir, 'bundle.zip'), bundle)
  execFileSync('unzip', ['-qq', '-o', join(workdir, 'bundle.zip'), '-d', bundleDir])
}
const published = (table: string) => {
  const path = join(bundleDir, `${table}.json`)
  return existsSync(path) ? readFileSync(path, 'utf8') : null
}

const bibleState = inspectBiblePublication(db)
let bibleCatalog: Map<string, string>
if (bibleState.source === 'd1') {
  bibleCatalog = new Map(generateBible(db))
  if (bibleCatalog.size !== bibleState.expectedChapters) {
    throw new Error(
      `generateBible produziu ${bibleCatalog.size} capítulos; ` +
        `o gate validou ${bibleState.expectedChapters}`,
    )
  }
  console.log(
    `Bíblia D1 validada: ${bibleState.verses} versículos em ${bibleState.chapters} capítulos.`,
  )
} else {
  bibleCatalog = new Map(
    readdirSync(bundleDir)
      .filter((file) => /^bible_\d+_\d+_\d+\.json$/.test(file))
      .sort()
      .map((file) => [file.slice(0, -5), readFileSync(join(bundleDir, file), 'utf8')]),
  )
  const missing = manifest
    .filter((entry) => isBibleTable(entry.table) && !bibleCatalog.has(entry.table))
    .map((entry) => entry.table)
  if (bibleCatalog.size === 0 || missing.length > 0) {
    throw new Error(
      'bible_seed_complete ausente e o bootstrap bíblico publicado está incompleto' +
        (missing.length > 0 ? `: ${missing.slice(0, 6).join(', ')}` : ''),
    )
  }
  console.log(
    `Bíblia: preservando os ${bibleCatalog.size} capítulos já publicados no R2` +
      (bibleState.verses > 0 ? ` e ignorando ${bibleState.verses} versículos parciais` : '') +
      '.',
  )
}

const catalog = () =>
  new Map([
    ...generateCatalog(db),
    ...(bibleState.source === 'd1' ? bibleCatalog : new Map<string, string>()),
  ])
const preserveBootstrapBible = bibleState.source === 'bootstrap'

// A versão só sobe quando algo além dela mesma mudou; `config` carrega a versão,
// então fica de fora da pergunta.
const draft = planJsonDb(catalog(), published, manifest, {
  preserveBible: preserveBootstrapBible,
})
const currentConfig: Config = {}
for (const row of db.prepare('SELECT key, type, value FROM configs').all()) {
  currentConfig[String(row.key)] = row.type === 'number' ? Number(row.value) : String(row.value)
}
const latest = db
  .prepare(
    'SELECT max(updated_at) AS latest FROM (' +
      VERSIONED_TABLES.filter((table) => table !== 'bible_verse' || bibleState.source === 'd1')
        .map((table) => `SELECT max(updated_at) AS updated_at FROM ${table}`)
        .join(' UNION ALL ') +
      ')',
  )
  .get()?.latest
const latestUpdated = String(latest ?? currentConfig.latest_updated ?? '')
const generatedContentChanged =
  draft.added.length > 0 ||
  draft.removed.length > 0 ||
  draft.changed.some((table) => table !== 'config')
const contentChanged =
  generatedContentChanged || catalogVersionChanged(currentConfig.version, latestUpdated)

let config: Config | null = null
if (contentChanged) {
  // Mesma regra da origem: qualquer tabela pública, incluindo vídeos online,
  // participa do relógio que decide a versão do catálogo.
  config = nextConfig(currentConfig, latestUpdated, new Date())

  const upsert = db.prepare(
    'INSERT INTO configs (key, type, value) VALUES (?, ?, ?) ' +
      'ON CONFLICT (key) DO UPDATE SET value = excluded.value',
  )
  for (const [key, kind] of Object.entries(VERSION_KEYS)) {
    upsert.run(key, kind, String(config[key]))
  }
}

const jsonDb = contentChanged
  ? planJsonDb(catalog(), published, manifest, { preserveBible: preserveBootstrapBible })
  : draft
const restPlan = planRest(await rest(), state)

const bibleFingerprint = md5(
  JSON.stringify(
    [...bibleCatalog]
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([table, json]) => [table, md5(json)]),
  ),
)
let publishedBibleFingerprint: string | null = null
try {
  const biblePublishState = JSON.parse((await text(BIBLE_STATE_KEY)) ?? '{}') as Record<
    string,
    unknown
  >
  if (typeof biblePublishState.snapshot_hash === 'string') {
    publishedBibleFingerprint = biblePublishState.snapshot_hash
  }
} catch {
  // Estado ausente/corrompido força a reconstrução do bundle bíblico.
}
// A Bíblia publicada é estática. Só quando ela passa a vir do D1 é que o
// pacote é regerado; no modo normal a publicação não toca nele.
const publishBibleBundle =
  bibleState.source === 'd1' &&
  (publishedBibleFingerprint !== bibleFingerprint || !(await r2.exists(BIBLE_BUNDLE_KEY)))
const targetVersion = Number(config?.version_number ?? currentConfig.version_number)
if (!Number.isSafeInteger(targetVersion) || targetVersion < 0) {
  throw new Error(`version_number inválida: ${String(targetVersion)}`)
}
const sample = (list: string[]) =>
  list.length === 0 ? '' : ` — ${list.slice(0, 6).join(', ')}${list.length > 6 ? '…' : ''}`
function describe(title: string, plan: Plan) {
  console.log(`\n${title}`)
  console.log(`  alterados: ${plan.changed.length}${sample(plan.changed)}`)
  console.log(`  novos:     ${plan.added.length}${sample(plan.added)}`)
  console.log(`  removidos: ${plan.removed.length}${sample(plan.removed)}`)
}
describe('/json_db', jsonDb)
describe(
  firstRun ? 'camada REST (primeira publicação: tudo é enviado uma vez)' : 'camada REST',
  restPlan,
)
if (config) {
  console.log(
    `\nNova versão do catálogo: ${config.version_number} (${config.datetime}, horário de Brasília)`,
  )
} else {
  console.log('\nCatálogo sem alteração: a versão não muda.')
}
if (publishBibleBundle) {
  console.log(`Bundle bíblico será publicado com ${bibleCatalog.size} capítulos.`)
}

const nothing =
  jsonDb.puts.length === 0 &&
  jsonDb.deletes.length === 0 &&
  !jsonDb.manifestChanged &&
  restPlan.puts.length === 0 &&
  restPlan.deletes.length === 0 &&
  !publishBibleBundle

// A rotina agendada pergunta por esta marca antes de exportar o banco de novo:
// ela diz até que ponto da auditoria o que está no ar já reflete.
const auditHead = Number(
  db.prepare('SELECT coalesce(max(id_log), 0) AS head FROM logs').get()?.head,
)
const markPublished = () =>
  r2.put(PUBLISHED_LOG_KEY, JSON.stringify({ id_log: auditHead }), 'application/json')

if (!WRITE || nothing) {
  console.log(
    nothing ? '\nNada a publicar.' : '\nSimulação: nada foi gravado. Use --write para publicar.',
  )
  if (WRITE) await markPublished()
  rmSync(workdir, { recursive: true, force: true })
  process.exit(0)
}

console.log('\nPublicando...')
const upload = (key: string, body: string | Uint8Array, type = 'application/json') =>
  r2.put(key, body, type)

// Atualiza primeiro o diretório de trabalho: o ZIP sai do mesmo retrato que
// será enviado ao R2.
let bundleVariants: BundleArtifact[] = []
if (
  jsonDb.puts.length > 0 ||
  jsonDb.deletes.length > 0 ||
  jsonDb.manifestChanged ||
  publishBibleBundle
) {
  for (const put of jsonDb.puts) writeFileSync(join(workdir, 'json', put.key.slice(8)), put.body)
  for (const key of jsonDb.deletes) {
    const path = join(workdir, 'json', key.slice(8))
    if (existsSync(path)) unlinkSync(path)
  }
  // Os pacotes com endereço de mídia saem do mesmo retrato. Se ficassem para
  // trás, a API entregaria o catálogo anterior a quem instala pelo pacote.
  bundleVariants = buildBundleArtifacts(bundleDir, workdir, false)
}

await pool([...jsonDb.puts, ...restPlan.puts], 8, (put) => upload(put.key, put.body))
await pool([...jsonDb.deletes, ...restPlan.deletes], 8, (key) => r2.del(key))

for (const variant of bundleVariants) {
  await upload(variant.key, readFileSync(variant.path), variant.contentType)
}
if (publishBibleBundle) {
  await upload(
    BIBLE_STATE_KEY,
    JSON.stringify({
      schema_version: 1,
      snapshot_hash: bibleFingerprint,
      chapters: bibleCatalog.size,
      source: bibleState.source,
      published_at: new Date().toISOString(),
    }),
  )
}

// scripts/delphi-db.ts atualiza `https_db_version` e `db_version` em /params
// depois de publicar os bancos SQLite. Os apps que leem o catálogo JSON
// encontram `version_number` no `config` que acabou de ser publicado.

if (config) {
  // Por último: só depois de tudo no bucket a nova versão vira verdade no banco.
  const quote = (value: string | number) => `'${String(value).replace(/'/g, "''")}'`
  const sql = Object.entries(VERSION_KEYS)
    .map(
      ([key, kind]) =>
        `INSERT INTO configs (key, type, value) VALUES (${quote(key)}, ${quote(kind)}, ` +
        `${quote(config[key] as string | number)}) ` +
        'ON CONFLICT (key) DO UPDATE SET value = excluded.value;',
    )
    .join(' ')
  execFileSync('npx', ['wrangler', 'd1', 'execute', DATABASE, '--remote', '--command', sql], {
    stdio: 'inherit',
  })
}

await upload(STATE_KEY, JSON.stringify(restPlan.state))
await markPublished()
rmSync(workdir, { recursive: true, force: true })

console.log(
  `\n${jsonDb.puts.length + restPlan.puts.length} objetos enviados, ` +
    `${jsonDb.deletes.length + restPlan.deletes.length} removidos.`,
)
