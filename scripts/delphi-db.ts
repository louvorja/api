/**
 * Gera e publica os bancos SQLite do Delphi a partir do D1.
 *
 *   npx wrangler d1 export louvorja --remote --output=replica.sql
 *   npm run delphi:db -- --export=replica.sql            # simula Opus/MP3 físico + BMP
 *   npm run delphi:db -- --export=replica.sql --write    # publica
 *   npm run delphi:db -- --export=replica.sql --force    # regera mesmo sem versão nova
 *   npm run delphi:db -- --export=replica.sql --audio=legacy # MP3 para clientes anteriores
 *
 * A Bíblia não está no D1: os versículos são preservados do banco anterior,
 * que por isso é baixado antes de gerar o novo.
 *
 * Atualiza `https_db_version` após publicar os dois bancos. `db_version`
 * é um valor legado e não é alterado por este script.
 */
import { createHash } from 'node:crypto'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { DatabaseSync } from 'node:sqlite'
import { exportLegacyDatabases } from './export-legacy-sqlite.ts'
import {
  DELPHI_LANGUAGES,
  DELPHI_PARAMS_KEY,
  delphiDbUrl,
  previousDelphiDatabase,
  publishDelphiDatabases,
} from './lib/delphi-storage.ts'
import * as r2 from './lib/r2.ts'

const args = new Map(
  process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=') as [string, string]),
)
const EXPORT = args.get('export')
const WRITE = args.has('write')
const FORCE = args.has('force')
const AUDIO = args.get('audio') ?? 'stored'
if (AUDIO !== 'legacy' && AUDIO !== 'stored')
  throw new Error('use --audio=legacy ou --audio=stored')
const REPORT_FILE = 'delphi-db-report.json'
/** O PHP gravava os carimbos no horário de Brasília; o D1 guarda UTC. */
const TIMESTAMP_OFFSET_HOURS = -3
/** Consultas do Delphi que não podem encolher de uma versão para a outra sem motivo. */
const GUARDED_VIEWS = ['MUSICAS', 'LISTA_MUSICAS', 'ALBUM', 'BIBLIA', 'ARQUIVOS_SISTEMA'] as const

function count(path: string, object: string): number {
  const db = new DatabaseSync(path, { readOnly: true })
  try {
    const row = db.prepare(`SELECT count(*) AS total FROM "${object}"`).get() as { total: number }
    return Number(row.total)
  } finally {
    db.close()
  }
}

function embeddedVersion(path: string): number {
  const db = new DatabaseSync(path, { readOnly: true })
  try {
    const row = db.prepare('SELECT VERSAO_BD AS version FROM VERSAO').get() as { version: number }
    return Number(row.version)
  } finally {
    db.close()
  }
}

/** `version_number` gravado no export do D1. */
function exportedVersion(exportPath: string): number {
  const db = new DatabaseSync(':memory:')
  try {
    db.exec(readFileSync(exportPath, 'utf8'))
    const row = db.prepare("SELECT value FROM configs WHERE key = 'version_number'").get() as
      | { value: unknown }
      | undefined
    return Number(row?.value ?? 0)
  } finally {
    db.close()
  }
}

if (!EXPORT) throw new Error('informe --export=<arquivo do wrangler d1 export>')

const paramsText = await r2.get(DELPHI_PARAMS_KEY)
if (paramsText === null) throw new Error(`${DELPHI_PARAMS_KEY} não existe no bucket`)
const params = JSON.parse(new TextDecoder().decode(paramsText)) as Record<string, unknown>
const published = Number(params.https_db_version ?? 0)

// A publicação grava a versão nova no `config` publicado antes de gravá-la no
// D1, e o export usado aqui foi tirado antes disso: a versão que vale é a maior.
const configText = await r2.get('json_db/config.json')
const catalogVersion =
  configText === null
    ? 0
    : Number(
        (JSON.parse(new TextDecoder().decode(configText)) as { version_number?: unknown })
          .version_number ?? 0,
      )

const targetVersion = Math.max(catalogVersion, exportedVersion(EXPORT))
// Sem versão nova não há o que gerar: evita baixar o banco anterior à toa.
if (targetVersion <= published && !FORCE) {
  console.log(
    `versão publicada por HTTPS: ${published}; versão do catálogo: ${targetVersion}. ` +
      'O banco publicado já está na versão do catálogo: nada a fazer.',
  )
  writeFileSync(
    REPORT_FILE,
    JSON.stringify({ version: targetVersion, published, written: false, skipped: true }, null, 1),
  )
  process.exit(0)
}

const workdir = mkdtempSync(join(tmpdir(), 'louvorja-delphi-db-'))
try {
  const previousDir = join(workdir, 'anterior')
  const outDir = join(workdir, 'novo')
  mkdirSync(previousDir)
  mkdirSync(outDir)
  for (const language of DELPHI_LANGUAGES) {
    const body = await previousDelphiDatabase(language, r2)
    writeFileSync(join(previousDir, `${language}_database.db`), body)
  }

  const publishedFiles = await r2.listWithSizes()

  const results = exportLegacyDatabases({
    exportSql: EXPORT,
    outDir,
    bibleSource: previousDir,
    audio: AUDIO,
    materializeMusicLists: AUDIO === 'stored',
    ...(AUDIO === 'stored' ? { publishedFiles } : { originalFiles: publishedFiles }),
    timestampOffsetHours: TIMESTAMP_OFFSET_HOURS,
    versionOverride: targetVersion,
  })
  const version = results[0]?.version ?? 0
  const missingOriginals = results[0]?.missingOriginals ?? []

  const report = results.map((result) => {
    const previous = join(previousDir, `${result.language}_database.db`)
    if (embeddedVersion(result.path) !== version) {
      throw new Error(`banco ${result.language} saiu com a versão errada`)
    }
    const views = Object.fromEntries(
      GUARDED_VIEWS.map((view) => {
        const before = count(previous, view)
        const after = count(result.path, view)
        if (after === 0 || after < before * 0.9) {
          throw new Error(
            `banco ${result.language}: ${view} caiu de ${before} para ${after} linhas; ` +
              'publicação do banco do Delphi cancelada',
          )
        }
        return [view, { before, after }]
      }),
    )
    const body = readFileSync(result.path)
    return {
      language: result.language,
      version,
      bytes: statSync(result.path).size,
      sha256: createHash('sha256').update(body).digest('hex'),
      bible_verses: result.bibleVerses,
      views,
      url: delphiDbUrl(result.language),
    }
  })

  for (const item of report) {
    const megabytes = (item.bytes / 1e6).toFixed(1)
    console.log(`${item.language}: versão ${item.version}, ${megabytes} MB, ${item.url}`)
  }
  console.log(
    `versão publicada por HTTPS: ${published || 'nenhuma'}; versão do catálogo: ${version}`,
  )
  if (missingOriginals.length > 0) {
    console.log(
      `ATENÇÃO: ${missingOriginals.length} arquivo(s) do catálogo sem original em ` +
        'files; o Delphi não vai conseguir baixá-los:',
    )
    for (const key of missingOriginals.slice(0, 20)) console.log(`  ${key}`)
  }
  writeFileSync(
    REPORT_FILE,
    JSON.stringify(
      { version, published, audio: AUDIO, written: false, missingOriginals, databases: report },
      null,
      1,
    ),
  )

  if (!WRITE) {
    console.log('Simulação: nada foi enviado (use --write).')
  } else {
    if (missingOriginals.length > 0) {
      throw new Error('publicação cancelada: arquivos do Delphi ausentes em files')
    }
    await publishDelphiDatabases(
      {
        pt: readFileSync(join(outDir, 'pt_database.db')),
        es: readFileSync(join(outDir, 'es_database.db')),
      },
      version,
      params,
      r2,
      AUDIO === 'legacy',
    )
    writeFileSync(
      REPORT_FILE,
      JSON.stringify(
        { version, published, audio: AUDIO, written: true, missingOriginals, databases: report },
        null,
        1,
      ),
    )
    console.log(`Publicado: bancos na versão ${version} e https_db_version atualizado.`)
  }
} finally {
  rmSync(workdir, { recursive: true, force: true })
}
