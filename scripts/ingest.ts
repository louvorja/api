/**
 * Ingestão: espelha o banco JSON de api.louvorja.com.br no bucket R2, sob
 * json_db/, reescrevendo as URLs de mídia para as chaves que existem no acervo.
 *
 * A lista de tabelas vem de /db/manifest, não de derivação a partir das
 * categorias. Derivar deixava de fora tudo que não estivesse referenciado —
 * 15 álbuns fora de categoria e a tabela pt_hymnal_1996 do hinário antigo, que
 * o violin-app usa. O manifesto ainda traz o MD5 de cada arquivo, então uma
 * reexecução só baixa o que mudou.
 *
 * O conteúdo vem do /db/bundle — um ZIP único com as 18.060 tabelas — em vez
 * de uma requisição por tabela. São 28MB numa tacada contra 18.060 idas ao
 * servidor de origem, que é instável.
 *
 *   node --env-file=.env --experimental-strip-types scripts/ingest.ts
 *     [--force] [--only=<regex>] [--concurrency=8]
 */
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { buildBundleArtifacts } from './lib/bundle-variants.ts'
import { fetchLegacy, pool } from './lib/legacy.ts'
import { mediaPath, normalizedMediaPath } from './lib/media.ts'
import { publicParams } from '../src/lib/public-params.ts'
import { publicApiUrl } from './lib/public-api.ts'
import * as r2 from './lib/r2.ts'
import * as pipeline from './lib/state.ts'

const args = new Map(
  process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=') as [string, string]),
)
const CONCURRENCY = Number(args.get('concurrency') ?? 8)
const FORCE = args.has('force')
const ONLY = args.get('only') ? new RegExp(args.get('only') as string) : null

const MISSING_FILE = 'missing-media.txt'
const REPORT_FILE = 'ingest-report.json'
const NEW_API = publicApiUrl(process.env.PUBLIC_API_URL)

const encoder = new TextEncoder()
const missingMedia = new Set<string>()
let bucketKeys = new Set<string>()

// ---------------------------------------------------------------- reescrita

const URL_FIELDS = new Set(['url_music', 'url_instrumental_music', 'url_image'])

/**
 * O acervo é padronizado em .opus e .jpg; a origem devolve .mp3 e .bmp. A URL
 * só é reescrita quando o alvo existe de fato no bucket — apontar para um
 * arquivo ausente faria o JSON mentir. Se nem o normalizado nem o original
 * existem, o alvo entra no relatório para o sync:media criá-lo.
 */
function rewriteUrl(url: unknown): unknown {
  if (typeof url !== 'string' || url === '') return url

  const original = mediaPath(url)
  const normalized = normalizedMediaPath(url)
  if (!original || !normalized) return url
  if (bucketKeys.has(normalized)) return `/${normalized}`
  if (bucketKeys.has(original)) return `/${original}`

  missingMedia.add(normalized)
  return `/${normalized}`
}

function rewrite(node: unknown): unknown {
  if (Array.isArray(node)) return node.map(rewrite)
  if (node === null || typeof node !== 'object') return node
  return Object.fromEntries(
    Object.entries(node).map(([k, v]) => [k, URL_FIELDS.has(k) ? rewriteUrl(v) : rewrite(v)]),
  )
}

// -------------------------------------------------------------------- meta

async function ingestMeta() {
  console.log('\n[meta] params, version_log, onlinevideos')

  const res = await fetchLegacy('/params?type=json')
  if (res.status === 200) {
    const raw = JSON.parse(new TextDecoder().decode(res.body)) as Record<string, unknown>
    const rest = publicParams(raw)
    // Só string é reapontada. Coagir tudo com String() transformava valores
    // numéricos como db_version=185 em "185", e o cliente compara com ===.
    const params = Object.fromEntries(
      Object.entries(rest).map(([k, v]) => [
        k,
        typeof v === 'string' ? v.replace(/https?:\/\/api\.louvorja\.com\.br/g, NEW_API) : v,
      ]),
    )
    await r2.put('meta/params.json', encoder.encode(JSON.stringify(params)), 'application/json')
    console.log(`  params: ${Object.keys(params).length} chaves`)
  }

  for (const [path, key, type] of [
    ['/version_log', 'meta/version_log.html', 'text/html; charset=UTF-8'],
    ['/onlinevideos', 'meta/onlinevideos.txt', 'text/html; charset=UTF-8'],
  ] as const) {
    const r = await fetchLegacy(path)
    if (r.status !== 200) {
      console.warn(`  ${path} -> HTTP ${r.status}, pulado`)
      continue
    }
    await r2.put(key, r.body, type)
    console.log(`  ${path}: ${r.body.length} bytes`)
  }
}

// --------------------------------------------------------------------- main

type Entry = { file: string; table: string; path: string; hash: string }

console.log('Lendo /db/manifest da origem...')
const manifestRes = await fetchLegacy('/db/manifest')
if (manifestRes.status !== 200) throw new Error(`manifest -> HTTP ${manifestRes.status}`)
const manifest = JSON.parse(new TextDecoder().decode(manifestRes.body)) as Entry[]
console.log(`  ${manifest.length} tabelas no manifesto`)

console.log('Lendo chaves atuais do bucket para validar as mídias referenciadas...')
bucketKeys = new Set(await r2.list())
console.log(`  ${bucketKeys.size} objetos no bucket`)

const estado = await pipeline.load()
// --force reprocessa, mas não esquece: zerar o mapa aqui faria um
// `--force --only=X` apagar o hash das outras 18.059 tabelas ao salvar no fim.
const state: Record<string, string> = { ...estado.tables }

await ingestMeta()

const selected = manifest.filter((e) => !ONLY || ONLY.test(e.table))
const todo = selected.filter((e) => FORCE || state[e.table] !== e.hash)
console.log(
  `\n[db] ${todo.length} tabelas a gravar` +
    (ONLY ? ` | ${manifest.length - selected.length} fora do filtro` : '') +
    (selected.length - todo.length > 0 ? ` | ${selected.length - todo.length} já em dia` : ''),
)

/**
 * Reescrever metade do catálogo de uma vez não é atualização: ou o estado se
 * perdeu, ou a origem re-hasheou tudo. Nos dois casos o certo é alguém olhar
 * antes, porque a alternativa é publicar 18.060 tabelas sem revisão.
 */
if (!FORCE && !ONLY && todo.length > selected.length * 0.5 && Object.keys(state).length > 0) {
  throw new Error(
    `${todo.length} de ${selected.length} tabelas mudariam — grande demais para uma execução ` +
      'automática. Rode com --force se for mesmo o caso.',
  )
}

let saved = 0
let failed = 0
const workdir = mkdtempSync(join(tmpdir(), 'louvorja-db-'))

// O app depende deste ZIP. Sem ele no bucket (primeira execução depois de
// publicar a rota), esperar uma tabela mudar deixaria /db/bible-bundle em 404.
// Os capítulos não têm URL de mídia, então o conteúdo da origem já é o final.
const bibleBundleMissing = !bucketKeys.has('meta/bible-bundle.zip')

if (todo.length > 0 || bibleBundleMissing) {
  console.log('  baixando /db/bundle...')
  const bundle = await fetchLegacy('/db/bundle')
  if (bundle.status !== 200) throw new Error(`bundle -> HTTP ${bundle.status}`)
  const zipPath = join(workdir, 'bundle.zip')
  writeFileSync(zipPath, bundle.body)
  console.log(`  ${(bundle.body.length / 1048576).toFixed(1)}MB, extraindo...`)
  execFileSync('unzip', ['-qq', '-o', zipPath, '-d', join(workdir, 'json')])

  await pool(todo, CONCURRENCY, async (entry) => {
    try {
      const path = join(workdir, 'json', entry.file)
      const body = JSON.stringify(rewrite(JSON.parse(readFileSync(path, 'utf8'))))
      // Grava de volta: é este conteúdo, e não o da origem, que entra no bundle.
      writeFileSync(path, body)
      await r2.put(`json_db/${entry.table}.json`, encoder.encode(body), 'application/json')
      state[entry.table] = entry.hash
      if (++saved % 2000 === 0) console.log(`  ... ${saved}/${todo.length}`)
    } catch (e) {
      console.warn(`  FALHA ${entry.table}: ${(e as Error).message}`)
      failed++
    }
  })

  if (failed > 0)
    throw new Error(`Ingestão incompleta: ${failed} tabelas falharam; pacotes não publicados`)
  // Unchanged entries must come from R2 too: the origin ZIP can have different
  // serialization or media paths. A subset (--only) must not publish other changes.
  const changed = new Set(todo.map((entry) => entry.table))
  await pool(
    manifest.filter((entry) => !changed.has(entry.table)),
    CONCURRENCY,
    async (entry) => {
      const body = await r2.get(`json_db/${entry.table}.json`)
      if (body === null) throw new Error(`Snapshot incompleto: ${entry.table}`)
      writeFileSync(join(workdir, 'json', entry.file), body)
    },
  )
  for (const artifact of buildBundleArtifacts(join(workdir, 'json'), workdir)) {
    await r2.put(artifact.key, readFileSync(artifact.path), artifact.contentType)
  }
}

await pipeline.save({ ...estado, tables: state })
rmSync(workdir, { recursive: true, force: true })

console.log(`\n${saved} tabelas gravadas, ${failed} falharam`)

// Gravado sempre, inclusive vazio: sobrar a lista da execução anterior faria o
// sync-media perseguir mídia que já entrou.
writeFileSync(MISSING_FILE, missingMedia.size > 0 ? `${[...missingMedia].sort().join('\n')}\n` : '')

writeFileSync(
  REPORT_FILE,
  JSON.stringify({
    changed: todo.map((e) => e.table),
    saved,
    failed,
    missing: [...missingMedia].sort(),
  }),
)

if (missingMedia.size > 0) {
  console.warn(
    `\n${missingMedia.size} mídias referenciadas não existem no bucket.` +
      `\nLista em ${MISSING_FILE} — "npm run sync:media" baixa e transcodifica.`,
  )
} else {
  console.log('\nTodas as mídias referenciadas existem no bucket.')
}

export {}
