/**
 * Gera os bancos SQLite consumidos pelo LouvorJA Delphi a partir de um export
 * SQL do D1. O resultado reproduz as tabelas e views de retrocompatibilidade
 * criadas por `app/Helpers/DataBase.php` na API PHP.
 *
 *   npx wrangler d1 export louvorja --remote --output=replica.sql
 *   node --experimental-strip-types scripts/export-legacy-sqlite.ts \
 *     --export=replica.sql --out=legacy-db --audio=legacy
 *
 * `--audio=legacy` (padrão) mantém os nomes `.mp3` da API PHP. O modo
 * `--audio=stored` grava o `file_name` realmente armazenado para áudio
 * (`.opus`/`.mp3`). Imagens e capas usam os BMP do Delphi tanto em files
 * quanto nas views; nomes e tamanhos precisam descrever o mesmo download.
 * Gere cada modo em um `--out` diferente para manter os artefatos separados.
 *
 * O seed normal do D1 não contém `bible_verse`. Nesse caso o gerador nunca
 * publica um banco sem Bíblia: ele preserva as linhas do `<lang>_database.db`
 * que já existe em `--out`, ou exige `--bible-source`. A fonte pode ser:
 *
 * - um SQLite com todos os idiomas;
 * - um diretório com `pt_database.db` e `es_database.db`;
 * - um caminho-modelo, por exemplo `/backup/db_{lang}_186.db`.
 *
 * A fonte precisa conter as tabelas-base `bible_version`, `bible_book` e
 * `bible_verse`, como os bancos que a API PHP gerava. Ela pode ser reconstruída
 * do snapshot JSON com `npm run bible:sqlite -- --source=snapshot`.
 */
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  renameSync,
  rmSync,
  statSync,
} from 'node:fs'
import { join, resolve } from 'node:path'
import { DatabaseSync, type SQLInputValue } from 'node:sqlite'
import { pathToFileURL } from 'node:url'
import { materializeMusicLists } from './lib/delphi-search.ts'

type Row = Record<string, unknown>

export type LegacyAudioMode = 'legacy' | 'stored'

export const LEGACY_TABLES = [
  'ALBUM_MUSICAS',
  'TIPOS_ALBUM',
  'ARQUIVOS_ADICIONAIS',
  'IMAGEM_POSICAO',
  'VERSAO',
  '_ALBUM_IGNORAR',
  '_COLETANEAS_PERSONALIZADAS',
  'ONL_CANAIS',
  'ONL_PLAYLISTS',
  'ONL_VIDEOS',
  'ARQUIVOS_SISTEMA',
  'MUSICAS_SLIDE',
] as const

export const LEGACY_VIEWS = [
  'ALBUM',
  'ALBUM_TIPO',
  'MUSICAS',
  'MUSICAS_LETRA',
  'HINARIO_ADVENTISTA',
  'HINARIO_ADVENTISTA_1996',
  'LISTA_MUSICAS',
  'LISTA_MUSICAS_ONL',
  'LISTA_MUSICAS_PERSO',
  'LISTA_MUSICAS_TODAS',
  'LISTA_COLETANEAS',
  'DOXOLOGIA_ALBUNS',
  'MUSICAS_INFANTIS',
  'BIBLIA',
  'LIVRO',
  'VERSAO_BIBLICA',
] as const

const REQUIRED_TABLES = [
  'languages',
  'files',
  'albums',
  'categories',
  'musics',
  'lyrics',
  'albums_musics',
  'categories_albums',
  'configs',
  'bible_book',
  'bible_version',
  'bible_verse',
  'online_videos_channels',
  'online_videos_playlists',
  'online_videos',
] as const

const LANGUAGE_TABLES = [
  'online_videos',
  'online_videos_playlists',
  'online_videos_channels',
  'bible_verse',
  'bible_book',
  'bible_version',
  'lyrics',
  'albums_musics',
  'categories_albums',
  'musics',
  'albums',
  'categories',
  'languages',
] as const

const SYSTEM_TABLES = [
  'migrations',
  'd1_migrations',
  'configs',
  'users',
  'download_logs',
  'logs',
  'jwt_blacklist',
  'row_counts',
  'slide_imports',
  'public_write_budget',
] as const

/**
 * Tudo que o banco gerado pelo PHP tinha. Tabela nova do D1 que não esteja em
 * SYSTEM_TABLES não pode vazar para o banco do Delphi: a geração para.
 */
const LEGACY_OBJECTS: ReadonlySet<string> = new Set([
  'ALBUM',
  'ALBUM_MUSICAS',
  'ALBUM_TIPO',
  'ARQUIVOS_ADICIONAIS',
  'ARQUIVOS_SISTEMA',
  'BIBLIA',
  'DOXOLOGIA_ALBUNS',
  'HINARIO_ADVENTISTA',
  'HINARIO_ADVENTISTA_1996',
  'IMAGEM_POSICAO',
  'LISTA_COLETANEAS',
  'LISTA_MUSICAS',
  'LISTA_MUSICAS_ONL',
  'LISTA_MUSICAS_PERSO',
  'LISTA_MUSICAS_TODAS',
  'LIVRO',
  'MUSICAS',
  'MUSICAS_INFANTIS',
  'MUSICAS_LETRA',
  'MUSICAS_SLIDE',
  'ONL_CANAIS',
  'ONL_PLAYLISTS',
  'ONL_VIDEOS',
  'TIPOS_ALBUM',
  'VERSAO',
  'VERSAO_BIBLICA',
  '_ALBUM_IGNORAR',
  '_COLETANEAS_PERSONALIZADAS',
  'albums',
  'albums_musics',
  'bible_book',
  'bible_verse',
  'bible_version',
  'categories',
  'categories_albums',
  'files',
  'languages',
  'lyrics',
  'musics',
  'online_videos',
  'online_videos_channels',
  'online_videos_playlists',
])

const BIBLE_COLUMNS = {
  bible_version: [
    'id_bible_version',
    'name',
    'abbreviation',
    'id_language',
    'created_at',
    'updated_at',
  ],
  bible_book: [
    'id_bible_book',
    'book_number',
    'name',
    'chapters',
    'testament',
    'keywords',
    'abbreviation',
    'color',
    'id_language',
    'created_at',
    'updated_at',
  ],
  bible_verse: [
    'id_bible_verse',
    'id_bible_version',
    'id_bible_book',
    'chapter',
    'verse',
    'text',
    'id_language',
    'created_at',
    'updated_at',
  ],
} as const

export interface LegacyExportOptions {
  exportSql: string
  outDir?: string
  languages?: readonly string[]
  bibleSource?: string
  audio?: LegacyAudioMode
  /** Materializa as listas de busca apenas no banco distribuído por HTTPS. */
  materializeMusicLists?: boolean
  /** Versão já calculada pela publicação atômica; não altera o export SQL. */
  versionOverride?: number
  /**
   * Tamanhos efetivos do objeto servido ao Delphi, indexados pela chave física
   * `dir/file_name` do D1. No modo legacy inclui áudio e imagens materializados;
   * no stored inclui os BMP compartilhados com legacy. O Delphi compara este
   * número antes de aceitar o download.
   */
  legacyFileSizes?: ReadonlyMap<string, number>
  /**
   * Inventário dos arquivos originais (MP3, BMP, JPEG) que o Delphi baixa,
   * indexado por `dir/name`. O D1 guarda o nome e o tamanho do arquivo
   * convertido que os apps novos usam; o Delphi confere o tamanho do original,
   * e sem esta troca ele marcaria como danificado tudo que virou Opus.
   */
  originalFiles?: ReadonlyMap<string, number>
  /**
   * Tamanhos publicados por chave: dir/file_name para áudio, dir/name para
   * imagens e capas. No modo stored, arquivos ausentes impedem a geração.
   */
  publishedFiles?: ReadonlyMap<string, number>
  /** Horas a somar aos carimbos do D1 (UTC) para chegar ao horário local que o PHP gravava. */
  timestampOffsetHours?: number
}

export interface LegacyExportResult {
  language: string
  path: string
  version: number
  bibleVerses: number
  bibleSource: 'd1' | string
  audio: LegacyAudioMode
  /** Arquivos do catálogo sem original no inventário: o Delphi não vai conseguir baixá-los. */
  missingOriginals: string[]
}

const quoteIdentifier = (value: string) => `"${value.replaceAll('"', '""')}"`

function row(db: DatabaseSync, sql: string, ...values: SQLInputValue[]): Row | undefined {
  return db.prepare(sql).get(...values) as Row | undefined
}

function tableExists(db: DatabaseSync, table: string, schema = 'main'): boolean {
  return Boolean(
    row(
      db,
      `SELECT 1 AS found FROM ${quoteIdentifier(schema)}.sqlite_master ` +
        "WHERE type = 'table' AND lower(name) = lower(?)",
      table,
    ),
  )
}

function columns(db: DatabaseSync, table: string, schema = 'main'): Set<string> {
  const records = db
    .prepare(`PRAGMA ${quoteIdentifier(schema)}.table_info(${quoteIdentifier(table)})`)
    .all()
  return new Set(records.map((record) => String((record as Row).name)))
}

function assertRequiredSchema(db: DatabaseSync) {
  const missing = REQUIRED_TABLES.filter((table) => !tableExists(db, table))
  if (missing.length > 0) {
    throw new Error(`export D1 sem as tabelas obrigatórias: ${missing.join(', ')}`)
  }
}

function databaseVersion(db: DatabaseSync): number {
  const value = row(db, "SELECT value FROM configs WHERE key = 'version_number'")?.value
  const version = Number(value)
  if (!Number.isSafeInteger(version) || version < 0) {
    throw new Error('configs.version_number ausente ou inválido no export D1')
  }
  return version
}

function applyFileSizeOverrides(db: DatabaseSync, overrides: ReadonlyMap<string, number>) {
  const find = db.prepare("SELECT id_file FROM files WHERE trim(dir, '/') || '/' || file_name = ?")
  const update = db.prepare('UPDATE files SET size = ? WHERE id_file = ?')
  for (const [key, size] of overrides) {
    if (!key || key.startsWith('/') || key.includes('\\') || key.split('/').includes('..')) {
      throw new Error(`chave de tamanho legado inválida: ${JSON.stringify(key)}`)
    }
    if (!Number.isSafeInteger(size) || size < 0) {
      throw new Error(`tamanho legado inválido para ${key}: ${String(size)}`)
    }
    const record = find.get(key) as Row | undefined
    if (!record) throw new Error(`tamanho legado não corresponde a files.dir/file_name: ${key}`)
    update.run(size, record.id_file as SQLInputValue)
  }
}

/** Devolve as chaves do catálogo que não têm arquivo original. */
function applyOriginalFiles(db: DatabaseSync, originals: ReadonlyMap<string, number>): string[] {
  const rows = db
    .prepare("SELECT id_file, trim(dir, '/') || '/' || name AS key FROM files ORDER BY id_file")
    .all() as Row[]
  const update = db.prepare('UPDATE files SET size = ?, file_name = name WHERE id_file = ?')
  const missing: string[] = []
  for (const record of rows) {
    const key = String(record.key)
    const size = originals.get(key)
    if (size === undefined) missing.push(key)
    else update.run(size, record.id_file as SQLInputValue)
  }
  return missing
}

function applyPublishedFiles(db: DatabaseSync, published: ReadonlyMap<string, number>) {
  const files = db
    .prepare(
      "SELECT id_file, trim(dir, '/') || '/' || " +
        "CASE WHEN type = 'music' THEN file_name ELSE name END AS key FROM files ORDER BY id_file",
    )
    .all() as Row[]
  const update = db.prepare('UPDATE files SET size = ? WHERE id_file = ?')
  const missing: string[] = []
  for (const file of files) {
    const key = String(file.key)
    validateDownloadKey(key)
    const size = published.get(key)
    if (size === undefined) {
      missing.push(key)
    } else {
      if (!Number.isSafeInteger(size) || size <= 0) {
        throw new Error(`tamanho publicado inválido para ${key}: ${String(size)}`)
      }
      update.run(size, file.id_file as SQLInputValue)
    }
  }
  if (missing.length > 0) {
    throw new Error(
      `${missing.length} arquivo(s) do Delphi ausente(s) no armazenamento publicado: ` +
        missing.slice(0, 20).join(', '),
    )
  }
}

function validateDownloadKey(value: unknown): asserts value is string {
  if (
    typeof value !== 'string' ||
    !/^(musics|images|covers|system)\//.test(value) ||
    /[\\\x00-\x1f\x7f]/.test(value) ||
    value.split('/').some((segment) => segment === '' || segment === '.' || segment === '..')
  ) {
    throw new Error(`caminho de download inválido: ${JSON.stringify(value)}`)
  }
}

function validateDownloads(db: DatabaseSync, published?: ReadonlyMap<string, number>) {
  const destinations = new Map<string, string>()
  for (const table of ['ARQUIVOS_ADICIONAIS', 'ARQUIVOS_SISTEMA']) {
    const update = db.prepare(`UPDATE ${table} SET TAMANHO = ? WHERE rowid = ?`)
    for (const record of db.prepare(`SELECT rowid, URL, URL_DOWNLOAD FROM ${table}`).all()) {
      const key = record.URL_DOWNLOAD
      validateDownloadKey(key)
      const local = String(record.URL)
      const previous = destinations.get(local)
      if (previous !== undefined && previous !== key) {
        throw new Error(`download ambíguo para ${local}: ${previous}, ${key}`)
      }
      destinations.set(local, key)
      if (published) {
        const size = published.get(key)
        if (size === undefined) {
          throw new Error(`arquivo de download ausente no armazenamento publicado: ${key}`)
        }
        if (!Number.isSafeInteger(size) || size <= 0) {
          throw new Error(`tamanho publicado inválido para ${key}: ${String(size)}`)
        }
        update.run(size, record.rowid as SQLInputValue)
      }
    }
  }
}

/** O PHP gravava horário local; o D1 guarda UTC. */
function shiftTimestamps(db: DatabaseSync, hours: number) {
  if (!Number.isFinite(hours) || hours === 0) return
  const tables = db
    .prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
    .all() as Row[]
  const modifier = `${hours > 0 ? '+' : ''}${hours} hours`
  for (const table of tables) {
    const name = String(table.name)
    const available = columns(db, name)
    for (const column of ['created_at', 'updated_at']) {
      if (!available.has(column)) continue
      db.prepare(
        `UPDATE ${quoteIdentifier(name)} SET ${column} = datetime(${column}, ?) ` +
          `WHERE ${column} IS NOT NULL AND datetime(${column}) IS NOT NULL`,
      ).run(modifier)
    }
  }
}

function validateLanguage(language: string): string {
  const normalized = language.trim().toLowerCase()
  if (!/^[a-z][a-z0-9_-]{1,7}$/.test(normalized)) {
    throw new Error(`idioma inválido: ${language}`)
  }
  return normalized
}

function validateAudioMode(audio: string | undefined): LegacyAudioMode {
  const mode = audio ?? 'legacy'
  if (mode !== 'legacy' && mode !== 'stored') {
    throw new Error(`modo de áudio inválido: ${mode}; use legacy ou stored`)
  }
  return mode
}

function withoutAccents(field: string): string {
  const replacements = [
    ['á', 'a'],
    ['ã', 'a'],
    ['â', 'a'],
    ['à', 'a'],
    ['é', 'e'],
    ['ê', 'e'],
    ['í', 'i'],
    ['ó', 'o'],
    ['õ', 'o'],
    ['ô', 'o'],
    ['ú', 'u'],
    ['ç', 'c'],
    ['É', 'e'],
    ['Ê', 'e'],
    ['Ó', 'o'],
  ] as const
  let expression = `LOWER(${field})`
  for (const [from, to] of replacements) {
    expression = `REPLACE(${expression}, '${from}', '${to}')`
  }
  return `LOWER(${expression})`
}

function dropD1OnlyObjects(db: DatabaseSync) {
  const triggers = db
    .prepare("SELECT name FROM sqlite_master WHERE type = 'trigger' ORDER BY name")
    .all() as Row[]
  for (const trigger of triggers) {
    db.exec(`DROP TRIGGER ${quoteIdentifier(String(trigger.name))}`)
  }
  for (const table of SYSTEM_TABLES) {
    db.exec(`DROP TABLE IF EXISTS ${quoteIdentifier(table)}`)
  }
}

/**
 * Referência direta de coluna numa view herda o tipo declarado na tabela, e o
 * FireDAC lê TEXT como WideMemo, que os controles de tela exibem como
 * "(WIDEMEMO)". Por isso as colunas abaixo saem tipadas no banco exportado.
 */
const DISPLAY_TEXT_COLUMNS = {
  // MUSICAS.NOME
  musics: ['name'],
  // ALBUM.NOME
  albums: ['name'],
  // LIVRO.LIVRO, LIVRO.SIGLA e LIVRO.PALAVRACHAVE
  bible_book: ['name', 'abbreviation', 'keywords'],
} as const

/** Preserva as views, mas fornece ao FireDAC um nome de tipo string na origem. */
function typeDisplayColumns(db: DatabaseSync, table: string, names: readonly string[]) {
  const original = String(
    row(db, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", table)?.sql ?? '',
  )
  let typed = original
  for (const name of names) {
    // O comprimento em bytes cobre também strings Unicode no buffer UTF-16 do Classic.
    const size = Math.max(
      512,
      Number(
        row(
          db,
          `SELECT max(length(CAST(${quoteIdentifier(name)} AS BLOB))) AS size ` +
            `FROM ${quoteIdentifier(table)}`,
        )?.size ?? 0,
      ),
    )
    const next = typed.replace(
      new RegExp(`([,(]\\s*(?:"${name}"|\`${name}\`|\\[${name}\\]|${name})\\s+)TEXT\\b`, 'i'),
      `$1NVARCHAR(${size})`,
    )
    if (next === typed) throw new Error(`${table}.${name} não corresponde ao schema TEXT do D1`)
    typed = next
  }

  const indexes = db
    .prepare(
      "SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL",
    )
    .all(table)
  const sequence = row(db, 'SELECT seq FROM sqlite_sequence WHERE name = ?', table)?.seq ?? 0
  const quoted = quoteIdentifier(table)
  // Só no banco temporário da exportação, com FKs desligadas e dentro da transação.
  // Reusa o DDL para preservar colunas, constraints e AUTOINCREMENT; não renomeia
  // a tabela original, pois isso reescreveria as referências de outras tabelas.
  db.exec(`
    CREATE TEMP TABLE delphi_typed_rows AS SELECT * FROM ${quoted};
    DROP TABLE ${quoted};
    ${typed};
    INSERT INTO ${quoted} SELECT * FROM temp.delphi_typed_rows;
    DROP TABLE temp.delphi_typed_rows;
  `)
  for (const index of indexes) db.exec(String(index.sql))
  db.prepare('UPDATE sqlite_sequence SET seq = max(seq, ?) WHERE name = ?').run(
    sequence as SQLInputValue,
    table,
  )
}

function keepLanguage(db: DatabaseSync, language: string) {
  for (const table of LANGUAGE_TABLES) {
    db.prepare(`DELETE FROM ${quoteIdentifier(table)} WHERE id_language <> ?`).run(language)
  }
}

function resolveBibleSource(
  requested: string | undefined,
  language: string,
  existingOutput: string,
): string | null {
  if (!requested) return existsSync(existingOutput) ? existingOutput : null

  const expanded = resolve(requested.replaceAll('{lang}', language))
  if (!existsSync(expanded)) return expanded
  if (statSync(expanded).isDirectory()) return join(expanded, `${language}_database.db`)
  return expanded
}

function assertBibleSource(db: DatabaseSync, sourcePath: string, language: string) {
  for (const [table, requiredColumns] of Object.entries(BIBLE_COLUMNS)) {
    if (!tableExists(db, table, 'bible_source')) {
      throw new Error(`${sourcePath}: tabela ${table} ausente na fonte da Bíblia`)
    }
    const actual = columns(db, table, 'bible_source')
    const missing = requiredColumns.filter((column) => !actual.has(column))
    if (missing.length > 0) {
      throw new Error(`${sourcePath}: ${table} sem as colunas ${missing.join(', ')}`)
    }
  }

  const verses = Number(
    row(
      db,
      'SELECT count(*) AS total FROM bible_source.bible_verse WHERE id_language = ?',
      language,
    )?.total ?? 0,
  )
  if (verses === 0) {
    throw new Error(`${sourcePath}: não contém versículos para ${language}`)
  }
}

function copyBible(db: DatabaseSync, sourcePath: string, language: string) {
  if (!existsSync(sourcePath) || !statSync(sourcePath).isFile()) {
    throw new Error(`fonte da Bíblia não encontrada: ${sourcePath}`)
  }

  // Só leitura: o `PRAGMA optimize` do fim gravaria estatísticas também no
  // banco de origem, que é o que está publicado.
  db.prepare('ATTACH DATABASE ? AS bible_source').run(`${pathToFileURL(sourcePath).href}?mode=ro`)
  assertBibleSource(db, sourcePath, language)

  db.exec('DELETE FROM bible_verse; DELETE FROM bible_book; DELETE FROM bible_version;')
  for (const [table, tableColumns] of Object.entries(BIBLE_COLUMNS)) {
    const list = tableColumns.map(quoteIdentifier).join(', ')
    db.prepare(
      `INSERT INTO ${quoteIdentifier(table)} (${list}) ` +
        `SELECT ${list} FROM bible_source.${quoteIdentifier(table)} WHERE id_language = ?`,
    ).run(language)
  }
}

function createLegacyObjects(
  db: DatabaseSync,
  language: string,
  version: number,
  audio: LegacyAudioMode,
) {
  const audioColumn = audio === 'stored' ? 'file_name' : 'name'
  const imageColumn = 'name'
  const hymnalName =
    "M.NOME || (CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'HASD') THEN ' (Hino nº ' || SUBSTR('00' || AM.FAIXA, -3, 3) || ') ' ELSE '' END)"
  const albumName = `A.NOME || COALESCE(
    (SELECT ' (' || ALBUM_TIPO.SUBTITULO || ')'
       FROM ALBUM_TIPO
      WHERE ALBUM_TIPO.ID_ALBUM = A.ID
        AND ALBUM_TIPO.TIPO = 'JA_ANO'), '')`

  db.exec(String.raw`
    CREATE VIEW ALBUM AS
      SELECT albums.id_album ID,
             albums.name NOME,
             files.${imageColumn} IMAGEM,
             CASE WHEN categories.slug = 'hymnal' THEN 'N' ELSE 'S' END AS PERMITE_DESATIVAR
        FROM albums
        LEFT JOIN files ON files.id_file = albums.id_file_image
        LEFT JOIN categories_albums ON categories_albums.id_album = albums.id_album
        LEFT JOIN categories ON categories.id_category = categories_albums.id_category
       WHERE albums.id_language = '${language}';

    CREATE TABLE ALBUM_MUSICAS AS
      SELECT albums_musics.id_album ID_ALBUM,
             albums_musics.id_music ID_MUSICA,
             albums_musics.track FAIXA
        FROM albums_musics
       WHERE albums_musics.id_language = '${language}';

    CREATE VIEW ALBUM_TIPO AS
      SELECT categories_albums.id_album ID_ALBUM,
             CASE
               WHEN categories.slug = 'misc' THEN 'DIV'
               WHEN categories.slug = 'doxology' THEN 'DOX'
               WHEN categories.slug = 'hymnal' THEN 'HASD'
               WHEN categories.slug = 'hymnal_1996' THEN 'HASD_1996'
               WHEN categories.slug = 'children' THEN 'INF'
               WHEN categories.slug = 'aym' THEN 'JA_ANO'
               ELSE 'DIV'
             END AS TIPO,
             categories_albums.name SUBTITULO,
             categories_albums."order" ORDEM
        FROM categories_albums
        LEFT JOIN categories ON categories.id_category = categories_albums.id_category
       WHERE categories_albums.id_language = '${language}';

    CREATE TABLE TIPOS_ALBUM AS
      SELECT 'DIV' ID, 'Diversas' TIPO
      UNION SELECT 'DOX', 'Doxologia'
      UNION SELECT 'HASD', 'Hinário Adventista'
      UNION SELECT 'HASD_1996', 'Hinário Adventista 1996'
      UNION SELECT 'INF', 'Infantis'
      UNION SELECT 'JA_ANO', 'CDs Oficiais/Ano';

    CREATE TABLE ARQUIVOS_ADICIONAIS AS
      SELECT '1' AS ID, '1minuto_escsb.mp3' AS ARQUIVO, 'config\1minuto_escsb.mp3' AS URL
      UNION ALL SELECT '2', '5minutos_escsb.mp3', 'config\5minutos_escsb.mp3'
      UNION ALL SELECT '3', 'abertura_escsb.mp3', 'config\abertura_escsb.mp3'
      UNION ALL SELECT '4', 'din-condensed-bold.ttf', 'config\fontes\din-condensed-bold.ttf'
      UNION ALL SELECT '6', 'bass.dll', 'bass.dll'
      UNION ALL SELECT '7', 'borlndmm.dll', 'borlndmm.dll'
      UNION ALL SELECT '8', 'midas.dll', 'midas.dll'
      UNION ALL SELECT '11', 'ssleay32.dll', 'ssleay32.dll'
      UNION ALL SELECT '12', 'libeay32.dll', 'libeay32.dll'
      UNION ALL SELECT '13', 'louvorja_slja.ico', 'config\ico\louvorja_slja.ico'
      UNION ALL SELECT '14', 'bassopus.dll', 'bassopus.dll'
      UNION ALL SELECT '15', '404.html', 'config\server\404.html'
      UNION ALL SELECT '16', 'api-v2.html', 'config\server\api-v2.html'
      UNION ALL SELECT '17', 'index.html', 'config\server\index.html'
      UNION ALL SELECT '18', 'mirror.html', 'config\server\mirror.html';

    ALTER TABLE ARQUIVOS_ADICIONAIS ADD COLUMN URL_DOWNLOAD TEXT;
    ALTER TABLE ARQUIVOS_ADICIONAIS ADD COLUMN TAMANHO INTEGER NOT NULL DEFAULT 0;
    UPDATE ARQUIVOS_ADICIONAIS SET URL_DOWNLOAD = 'system/' || replace(URL, '\', '/');

    CREATE TABLE IMAGEM_POSICAO AS
      SELECT ${imageColumn} IMAGEM, image_position POSICAO
        FROM files
       WHERE image_position IS NOT NULL;

    CREATE VIEW MUSICAS AS
      SELECT musics.id_music ID,
             substr(files_url.dir, 12, 100) ALBUM,
             musics.name NOME,
             files_image.${imageColumn} IMAGEM,
             files_url.${audioColumn} URL,
             files_url_instrumental.${audioColumn} URL_INSTRUMENTAL,
             upper(musics.id_language) IDIOMA,
             0 TAMANHO_LETRA,
             '' COR_LETRA,
             1 FUNDO_LETRA,
             files_image.size TAMANHO_IMAGEM,
             files_url.size TAMANHO_ARQUIVO,
             files_url_instrumental.size TAMANHO_ARQUIVO_PB,
             (SELECT GROUP_CONCAT(lyric) FROM lyrics WHERE lyrics.id_music = musics.id_music) LETRA
        FROM musics
        INNER JOIN albums_musics ON albums_musics.id_music = musics.id_music
        LEFT JOIN files files_image ON files_image.id_file = musics.id_file_image
        LEFT JOIN files files_url ON files_url.id_file = musics.id_file_music
        LEFT JOIN files files_url_instrumental
          ON files_url_instrumental.id_file = musics.id_file_instrumental_music
       WHERE musics.id_language = '${language}';

    CREATE VIEW MUSICAS_LETRA AS
      SELECT lyrics.id_lyric ID,
             lyrics.show_slide EXIBE_SLIDE,
             lyrics."order" ORDEM,
             files.${imageColumn} IMAGEM,
             lyrics.lyric LETRA,
             '' COR_LETRA,
             lyrics.id_music MUSICA,
             lyrics.time TEMPO,
             CASE WHEN lyrics.instrumental_time = '00:00:00'
                  THEN lyrics.time ELSE lyrics.instrumental_time END AS TEMPO_PB,
             1 FUNDO_LETRA,
             0 TAMANHO_LETRA,
             lyrics.aux_lyric LETRA_AUX,
             0 TAMANHO_LETRA_AUX,
             '' COR_LETRA_AUX,
             files.size TAMANHO_IMAGEM
        FROM lyrics
        LEFT JOIN files ON files.id_file = lyrics.id_file_image
       WHERE lyrics.id_language = '${language}';

    CREATE TABLE VERSAO AS SELECT 1 ID, ${version} VERSAO_BD;

    CREATE VIEW HINARIO_ADVENTISTA AS
      SELECT musics.id_music ID,
             albums_musics.track FAIXA,
             musics.name NOME,
             ${withoutAccents('musics.name')} AS NOME_SEMAC,
             SUBSTR('00' || albums_musics.track, -3, 3) || ' - ' || musics.name NOME_COM,
             substr(files_url.dir, 12, 100) ALBUM,
             files_url.${audioColumn} URL,
             files_url_instrumental.${audioColumn} URL_INSTRUMENTAL
        FROM musics
        INNER JOIN albums_musics ON albums_musics.id_music = musics.id_music
        INNER JOIN categories_albums ON categories_albums.id_album = albums_musics.id_album
        INNER JOIN categories ON categories.id_category = categories_albums.id_category
        LEFT JOIN files files_url ON files_url.id_file = musics.id_file_music
        LEFT JOIN files files_url_instrumental
          ON files_url_instrumental.id_file = musics.id_file_instrumental_music
       WHERE musics.id_language = '${language}' AND categories.slug = 'hymnal'
       ORDER BY albums_musics.track;

    CREATE VIEW HINARIO_ADVENTISTA_1996 AS
      SELECT musics.id_music ID,
             albums_musics.track FAIXA,
             musics.name NOME,
             ${withoutAccents('musics.name')} AS NOME_SEMAC,
             SUBSTR('00' || albums_musics.track, -3, 3) || ' - ' || musics.name NOME_COM,
             substr(files_url.dir, 12, 100) ALBUM,
             files_url.${audioColumn} URL,
             files_url_instrumental.${audioColumn} URL_INSTRUMENTAL
        FROM musics
        INNER JOIN albums_musics ON albums_musics.id_music = musics.id_music
        INNER JOIN categories_albums ON categories_albums.id_album = albums_musics.id_album
        INNER JOIN categories ON categories.id_category = categories_albums.id_category
        LEFT JOIN files files_url ON files_url.id_file = musics.id_file_music
        LEFT JOIN files files_url_instrumental
          ON files_url_instrumental.id_file = musics.id_file_instrumental_music
       WHERE musics.id_language = '${language}' AND categories.slug = 'hymnal_1996'
       ORDER BY albums_musics.track;

    CREATE TABLE _ALBUM_IGNORAR (ID INT);
    CREATE TABLE _COLETANEAS_PERSONALIZADAS (ID STRING, NOME STRING, URL STRING);

    CREATE TABLE ONL_CANAIS AS
      SELECT channel_id CANAL_ID, title NOME, custom_url CUSTOM_URL,
             default_image IMAGEM, default_image_base64 IMAGEM_64
        FROM online_videos_channels
       WHERE id_language = '${language}';

    CREATE TABLE ONL_PLAYLISTS AS
      SELECT playlist_id PLAYLIST_ID,
             (SELECT channel_id FROM online_videos_channels
               WHERE online_videos_channels.id_online_video_channel =
                     online_videos_playlists.id_online_video_channel) CANAL_ID,
             title NOME, default_image IMAGEM, default_image_base64 IMAGEM_64
        FROM online_videos_playlists
       WHERE id_language = '${language}';

    CREATE TABLE ONL_VIDEOS AS
      SELECT video_id VIDEO_ID,
             (SELECT playlist_id FROM online_videos_playlists
               WHERE online_videos_playlists.id_online_video_playlist =
                     online_videos.id_online_video_playlist) PLAYLIST_ID,
             title NOME, sequence POSICAO, default_image IMAGEM,
             default_image_base64 IMAGEM_64
        FROM online_videos
       WHERE id_language = '${language}';

    CREATE TABLE ARQUIVOS_SISTEMA AS
      SELECT 'ARQUIVOS_ADICIONAIS' AS TIPO, ARQUIVO, URL, TAMANHO,
             '' AS TABELA, '' AS CAMPO_ARQ, '' AS CAMPO_ARQ_TAM, '' AS CHAVE,
             URL_DOWNLOAD
        FROM ARQUIVOS_ADICIONAIS
       WHERE TRIM(URL) <> ''
      UNION
      SELECT 'MUSICA', MUSICAS.URL,
             'config\musicas\' || MUSICAS.ALBUM || '\' || MUSICAS.URL,
             TAMANHO_ARQUIVO, 'MUSICAS', 'ALBUM' || '\' || URL,
             'TAMANHO_ARQUIVO', MUSICAS.ALBUM || '\' || MUSICAS.URL,
             trim(file_audio.dir, '/') || '/' || file_audio.${audioColumn}
        FROM ALBUM
        INNER JOIN (MUSICAS INNER JOIN ALBUM_MUSICAS
          ON MUSICAS.ID = ALBUM_MUSICAS.ID_MUSICA)
          ON ALBUM.ID = ALBUM_MUSICAS.ID_ALBUM
        INNER JOIN musics source_music ON source_music.id_music = MUSICAS.ID
        LEFT JOIN files file_audio ON file_audio.id_file = source_music.id_file_music
       WHERE ((ALBUM.PERMITE_DESATIVAR = 'N') OR
              (ALBUM.PERMITE_DESATIVAR = 'S' AND
               ALBUM.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR)))
         AND TRIM(MUSICAS.URL) <> '' AND TRIM(MUSICAS.ALBUM) <> ''
      UNION
      SELECT 'MUSICA_PB', MUSICAS.URL_INSTRUMENTAL,
             'config\musicas\' || MUSICAS.ALBUM || '\' || MUSICAS.URL_INSTRUMENTAL,
             TAMANHO_ARQUIVO_PB, 'MUSICAS', 'ALBUM' || '\' || URL_INSTRUMENTAL,
             'TAMANHO_ARQUIVO_PB', MUSICAS.ALBUM || '\' || MUSICAS.URL_INSTRUMENTAL,
             trim(file_audio.dir, '/') || '/' || file_audio.${audioColumn}
        FROM ALBUM
        INNER JOIN (MUSICAS INNER JOIN ALBUM_MUSICAS
          ON MUSICAS.ID = ALBUM_MUSICAS.ID_MUSICA)
          ON ALBUM.ID = ALBUM_MUSICAS.ID_ALBUM
        INNER JOIN musics source_music ON source_music.id_music = MUSICAS.ID
        LEFT JOIN files file_audio ON file_audio.id_file = source_music.id_file_instrumental_music
       WHERE ((ALBUM.PERMITE_DESATIVAR = 'N') OR
              (ALBUM.PERMITE_DESATIVAR = 'S' AND
               ALBUM.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR)))
         AND TRIM(MUSICAS.URL_INSTRUMENTAL) <> '' AND TRIM(MUSICAS.ALBUM) <> ''
      UNION
      SELECT 'IMAGEM_FUNDO', MUSICAS_LETRA.IMAGEM, 'config\imagens\' || MUSICAS_LETRA.IMAGEM,
             TAMANHO_IMAGEM, 'MUSICAS_LETRA', 'IMAGEM', 'TAMANHO_IMAGEM', MUSICAS_LETRA.IMAGEM,
             trim(file_image.dir, '/') || '/' || file_image.${imageColumn}
        FROM MUSICAS_LETRA
        INNER JOIN lyrics source_lyric ON source_lyric.id_lyric = MUSICAS_LETRA.ID
        LEFT JOIN files file_image ON file_image.id_file = source_lyric.id_file_image
       WHERE TRIM(MUSICAS_LETRA.IMAGEM) <> ''
      UNION
      SELECT 'IMAGEM_FUNDO_CAPA', MUSICAS.IMAGEM, 'config\imagens\' || MUSICAS.IMAGEM,
             TAMANHO_IMAGEM, 'MUSICAS', 'IMAGEM', 'TAMANHO_IMAGEM', MUSICAS.IMAGEM,
             trim(file_image.dir, '/') || '/' || file_image.${imageColumn}
        FROM MUSICAS
        INNER JOIN musics source_music ON source_music.id_music = MUSICAS.ID
        LEFT JOIN files file_image ON file_image.id_file = source_music.id_file_image
       WHERE TRIM(MUSICAS.IMAGEM) <> ''
      UNION
      SELECT 'IMAGEM_ALBUM', ALBUM.IMAGEM, 'config\capas\' || ALBUM.IMAGEM,
             file_cover.size, '', '', '', '',
             trim(file_cover.dir, '/') || '/' || file_cover.${imageColumn}
        FROM ALBUM
        INNER JOIN albums source_album ON source_album.id_album = ALBUM.ID
        LEFT JOIN files file_cover ON file_cover.id_file = source_album.id_file_image
       WHERE ((ALBUM.PERMITE_DESATIVAR = 'N') OR
              (ALBUM.PERMITE_DESATIVAR = 'S' AND ALBUM.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR)))
         AND TRIM(ALBUM.IMAGEM) <> ''
       ORDER BY ARQUIVO;

    CREATE VIEW LISTA_MUSICAS AS
      SELECT M.ID,
             A.ID AS ID_ALBUM,
             A.NOME AS NOME_ALBUM,
             ${albumName} AS NOME_ALBUM_COM,
             AM.FAIXA,
             ${hymnalName} AS NOME,
             (CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO
                 WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'HASD')
               THEN SUBSTR('00' || AM.FAIXA, -3, 3) || ' - ' ELSE '' END) ||
             M.NOME || ' (' || COALESCE(
               (SELECT ALBUM_TIPO.SUBTITULO || ' - ' FROM ALBUM_TIPO
                 WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'JA_ANO'), '') ||
             A.NOME || ')' AS NOME_COM,
             CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO
               WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'HASD')
               THEN 'S' ELSE 'N' END AS TIPO_HASD,
             CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO
               WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'JA_ANO')
               THEN 'S' ELSE 'N' END AS TIPO_JA,
             'S' AS TIPO_BAIXADA, 'N' AS TIPO_WEB, 'N' AS TIPO_PERSO,
             'B' AS TIPO, '' AS URL_ALBUM,
             M.ALBUM, M.URL, M.URL_INSTRUMENTAL, M.IDIOMA, M.LETRA,
             ${withoutAccents(hymnalName)} AS NOME_SEMAC,
             ${withoutAccents('M.LETRA')} AS LETRA_SEMAC,
             ${withoutAccents(albumName)} AS NOME_ALBUM_COM_SEMAC
        FROM MUSICAS AS M
        LEFT JOIN ALBUM_MUSICAS AS AM ON M.ID = AM.ID_MUSICA
        LEFT JOIN ALBUM AS A ON AM.ID_ALBUM = A.ID
       WHERE (A.PERMITE_DESATIVAR = 'N') OR
             (A.PERMITE_DESATIVAR = 'S' AND A.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR));

    CREATE VIEW LISTA_MUSICAS_ONL AS
      SELECT ONL_VIDEOS.VIDEO_ID AS ID,
             0 AS ID_ALBUM,
             ONL_PLAYLISTS.NOME AS NOME_ALBUM,
             ONL_PLAYLISTS.NOME || ' (Canal ' || ONL_CANAIS.NOME || ')' AS NOME_ALBUM_COM,
             ONL_VIDEOS.POSICAO AS FAIXA,
             ONL_VIDEOS.NOME AS NOME,
             ONL_VIDEOS.NOME || ' (Canal ' || ONL_CANAIS.NOME || ')' AS NOME_COM,
             'N' AS TIPO_HASD, 'N' AS TIPO_JA, 'N' AS TIPO_BAIXADA,
             'S' AS TIPO_WEB, 'N' AS TIPO_PERSO, 'W' AS TIPO,
             ONL_VIDEOS.VIDEO_ID AS URL_ALBUM,
             '' AS ALBUM, '' AS URL, '' AS URL_INSTRUMENTAL, '' AS IDIOMA, '' AS LETRA,
             ${withoutAccents('ONL_VIDEOS.NOME')} AS NOME_SEMAC,
             '' AS LETRA_SEMAC,
             ${withoutAccents("ONL_PLAYLISTS.NOME || ' (Canal ' || ONL_CANAIS.NOME || ')'")} AS NOME_ALBUM_COM_SEMAC
        FROM ONL_VIDEOS
        INNER JOIN ONL_PLAYLISTS ON ONL_VIDEOS.PLAYLIST_ID = ONL_PLAYLISTS.PLAYLIST_ID
        INNER JOIN ONL_CANAIS ON ONL_PLAYLISTS.CANAL_ID = ONL_CANAIS.CANAL_ID
       ORDER BY ONL_VIDEOS.NOME;

    CREATE VIEW LISTA_MUSICAS_PERSO AS
      SELECT ID, 0 AS ID_ALBUM,
             'Coletâneas Personalizadas' AS NOME_ALBUM,
             'Coletâneas Personalizadas' AS NOME_ALBUM_COM,
             0 AS FAIXA, NOME, NOME AS NOME_COM,
             'N' AS TIPO_HASD, 'N' AS TIPO_JA, 'N' AS TIPO_BAIXADA,
             'N' AS TIPO_WEB, 'S' AS TIPO_PERSO, 'P' AS TIPO,
             '' AS URL_ALBUM, '' AS ALBUM, URL, '' AS URL_INSTRUMENTAL,
             '' AS IDIOMA, '' AS LETRA,
             ${withoutAccents('NOME')} AS NOME_SEMAC,
             '' AS LETRA_SEMAC,
             'coletaneas personalizadas' AS NOME_ALBUM_COM_SEMAC
        FROM _COLETANEAS_PERSONALIZADAS
       ORDER BY NOME;

    CREATE VIEW LISTA_MUSICAS_TODAS AS
      SELECT * FROM LISTA_MUSICAS
      UNION SELECT * FROM LISTA_MUSICAS_ONL
      UNION SELECT * FROM LISTA_MUSICAS_PERSO
      ORDER BY NOME;

    CREATE TABLE MUSICAS_SLIDE AS
      SELECT 'CAPA' AS TIPO, ID AS MUSICA_ID, -1 AS LETRA_ID,
             ALBUM || '/' || URL AS URL_MUSICA,
             CASE WHEN URL_INSTRUMENTAL <> ''
               THEN ALBUM || '/' || URL_INSTRUMENTAL ELSE '' END AS URL_MUSICA_PB,
             NOME AS LETRA, UPPER(NOME) AS LETRA_UCASE, -1 AS ORDEM,
             MUSICAS.IMAGEM AS IMAGEM,
             IFNULL(IMAGEM_POSICAO.POSICAO, 0) AS IMAGEM_POSICAO,
             '00:00:00' AS TEMPO, '00:00:00' AS TEMPO_PB,
             FUNDO_LETRA, TAMANHO_LETRA, COR_LETRA,
             '' AS LETRA_AUX, 0 AS TAMANHO_LETRA_AUX, '' AS COR_LETRA_AUX
        FROM MUSICAS
        LEFT JOIN IMAGEM_POSICAO ON IMAGEM_POSICAO.IMAGEM = MUSICAS.IMAGEM
      UNION
      SELECT 'LETRA', MUSICA, ID, '', '', LETRA, UPPER(LETRA), ORDEM,
             MUSICAS_LETRA.IMAGEM,
             IFNULL(IMAGEM_POSICAO.POSICAO, 0),
             TEMPO,
             CASE WHEN IFNULL(MUSICAS_LETRA.TEMPO_PB, 0) > 0
               THEN IFNULL(MUSICAS_LETRA.TEMPO_PB, 0) ELSE TEMPO END,
             FUNDO_LETRA, TAMANHO_LETRA, COR_LETRA,
             LETRA_AUX, TAMANHO_LETRA_AUX, COR_LETRA_AUX
        FROM MUSICAS_LETRA
        LEFT JOIN IMAGEM_POSICAO ON IMAGEM_POSICAO.IMAGEM = MUSICAS_LETRA.IMAGEM
       WHERE EXIBE_SLIDE = 1
       ORDER BY MUSICA_ID, ORDEM;

    CREATE VIEW LISTA_COLETANEAS AS
      SELECT DISTINCT A.ID, T.ID_ALBUM, T.TIPO, T.SUBTITULO, A.NOME,
             A.NOME || CASE WHEN T.SUBTITULO <> '' THEN ' (' || T.SUBTITULO || ')' ELSE '' END
               AS NOME_ALBUM,
             A.IMAGEM
        FROM ALBUM AS A
        LEFT JOIN ALBUM_TIPO AS T ON T.ID_ALBUM = A.ID
       WHERE A.PERMITE_DESATIVAR = 'N' OR
             (A.PERMITE_DESATIVAR = 'S' AND A.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR))
       ORDER BY T.ORDEM, A.NOME;

    CREATE VIEW DOXOLOGIA_ALBUNS AS
      SELECT A.ID, A.NOME, A.IMAGEM
        FROM ALBUM AS A
        LEFT JOIN ALBUM_TIPO AS AT ON A.ID = AT.ID_ALBUM
       WHERE AT.TIPO = 'DOX'
       ORDER BY AT.ORDEM, A.NOME;

    CREATE VIEW MUSICAS_INFANTIS AS
      SELECT MUSICA.ID, MUSICA.NOME, MUSICA.ALBUM, MUSICA.URL, MUSICA.URL_INSTRUMENTAL
        FROM MUSICAS AS MUSICA
        JOIN ALBUM_MUSICAS ON MUSICA.ID = ALBUM_MUSICAS.ID_MUSICA
        JOIN ALBUM ON ALBUM_MUSICAS.ID_ALBUM = ALBUM.ID
        JOIN ALBUM_TIPO ON ALBUM.ID = ALBUM_TIPO.ID_ALBUM
       WHERE ALBUM_TIPO.TIPO = 'INF'
       ORDER BY MUSICA.NOME;

    CREATE VIEW BIBLIA AS
      SELECT bible_verse.id_bible_verse ID,
             (SELECT abbreviation FROM bible_version
               WHERE id_bible_version = bible_verse.id_bible_version) VERSAO,
             CASE WHEN bible_verse.id_bible_book > 66
               THEN bible_verse.id_bible_book - 66 ELSE bible_verse.id_bible_book END LIVRO,
             bible_verse.chapter CAPITULO,
             bible_verse.verse VERSICULO,
             bible_verse.text PASSAGEM
        FROM bible_verse
       WHERE bible_verse.id_language = '${language}';

    CREATE VIEW LIVRO AS
      SELECT book_number ID,
             abbreviation SIGLA,
             CASE WHEN book_number > 39 THEN book_number - 39 ELSE book_number END ID_SECAO,
             CASE WHEN testament = 1 THEN 'AT' ELSE 'NT' END TESTAMENTO,
             name LIVRO,
             keywords PALAVRACHAVE,
             CASE WHEN SUBSTR(abbreviation, 1, 1) IN ('1', '2', '3')
               THEN SUBSTR(abbreviation, 2, 5) ELSE abbreviation END SIGLA_L,
             CASE WHEN SUBSTR(abbreviation, 1, 1) IN ('1', '2', '3')
               THEN SUBSTR(abbreviation, 1, 1) ELSE '' END SIGLA_N,
             chapters CAPITULOS,
             '$0' || SUBSTR(color, 6, 2) || SUBSTR(color, 4, 2) || SUBSTR(color, 2, 2) COR
        FROM bible_book
       WHERE bible_book.id_language = '${language}';

    CREATE VIEW VERSAO_BIBLICA AS
      SELECT abbreviation SIGLA,
             name VERSAO,
             CASE WHEN abbreviation = 'NTLH' THEN 0 ELSE 1 END QUEBRA,
             CASE WHEN abbreviation = 'NTLH' THEN '<pb/>' ELSE '' END SIMBOLO_QUEBRA,
             '' EXPLICACAO_VERSOS
        FROM bible_version
       WHERE id_language = '${language}';
  `)
}

function buildLanguageDatabase(
  basePath: string,
  stagedPath: string,
  finalPath: string,
  language: string,
  version: number,
  requestedBibleSource: string | undefined,
  audio: LegacyAudioMode,
  missingOriginals: string[],
  publishedFiles?: ReadonlyMap<string, number>,
  materializeSearch = false,
): LegacyExportResult {
  copyFileSync(basePath, stagedPath)
  const db = new DatabaseSync(stagedPath)
  let bibleSource: 'd1' | string = 'd1'

  try {
    db.exec('PRAGMA foreign_keys = OFF; BEGIN IMMEDIATE;')
    keepLanguage(db, language)

    const d1Verses = Number(
      row(db, 'SELECT count(*) AS total FROM bible_verse WHERE id_language = ?', language)?.total ??
        0,
    )
    if (d1Verses === 0) {
      const source = resolveBibleSource(requestedBibleSource, language, finalPath)
      if (!source) {
        throw new Error(
          `o export D1 não contém bible_verse para ${language}; ` +
            'inclua a Bíblia no seed D1 ou informe --bible-source=<arquivo|diretório|modelo-{lang}>',
        )
      }
      copyBible(db, source, language)
      bibleSource = source
    }

    dropD1OnlyObjects(db)
    for (const [table, names] of Object.entries(DISPLAY_TEXT_COLUMNS)) {
      typeDisplayColumns(db, table, names)
    }
    createLegacyObjects(db, language, version, audio)
    if (materializeSearch) materializeMusicLists(db)
    validateDownloads(db, publishedFiles)
    const unknown = (
      db
        .prepare(
          "SELECT name FROM sqlite_master WHERE type IN ('table', 'view') " +
            "AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )
        .all() as Row[]
    )
      .map((object) => String(object.name))
      .filter((name) => !LEGACY_OBJECTS.has(name))
    if (unknown.length > 0) {
      throw new Error(
        `o banco ${language} sairia com objetos que o Delphi não conhece: ${unknown.join(', ')}; ` +
          'inclua-os em SYSTEM_TABLES se forem internos da API',
      )
    }
    db.exec('COMMIT;')
    // A fonte da Bíblia é só leitura; solta antes do VACUUM e do optimize.
    if (bibleSource !== 'd1') db.exec('DETACH DATABASE bible_source')

    const violations = db.prepare('PRAGMA foreign_key_check').all()
    if (violations.length > 0) {
      throw new Error(
        `banco ${language} tem ${violations.length} violação(ões) de chave estrangeira`,
      )
    }
    const integrity = String(row(db, 'PRAGMA integrity_check')?.integrity_check ?? '')
    if (integrity !== 'ok') throw new Error(`integrity_check falhou para ${language}: ${integrity}`)
    db.exec('VACUUM; PRAGMA optimize;')

    const bibleVerses = Number(row(db, 'SELECT count(*) AS total FROM BIBLIA')?.total ?? 0)
    return {
      language,
      path: finalPath,
      version,
      bibleVerses,
      bibleSource,
      audio,
      missingOriginals,
    }
  } catch (error) {
    try {
      db.exec('ROLLBACK')
    } catch {
      // A falha pode ter ocorrido antes de a transação começar ou após o COMMIT.
    }
    throw error
  } finally {
    db.close()
  }
}

export function exportLegacyDatabases(options: LegacyExportOptions): LegacyExportResult[] {
  const exportSql = resolve(options.exportSql)
  if (!existsSync(exportSql) || !statSync(exportSql).isFile()) {
    throw new Error(`export D1 não encontrado: ${exportSql}`)
  }

  const outDir = resolve(options.outDir ?? 'legacy-db')
  const languages = [...new Set((options.languages ?? ['pt', 'es']).map(validateLanguage))]
  const audio = validateAudioMode(options.audio)
  if (
    options.publishedFiles &&
    (audio !== 'stored' || options.originalFiles || options.legacyFileSizes)
  ) {
    throw new Error('publishedFiles exige audio=stored e não pode combinar outros inventários')
  }
  if (languages.length === 0) throw new Error('informe ao menos um idioma')

  mkdirSync(outDir, { recursive: true })
  const stagingDir = mkdtempSync(join(outDir, '.legacy-sqlite-'))
  const basePath = join(stagingDir, 'd1.db')
  try {
    const base = new DatabaseSync(basePath)
    let version: number
    let missingOriginals: string[] = []
    try {
      base.exec(readFileSync(exportSql, 'utf8'))
      assertRequiredSchema(base)
      if (options.legacyFileSizes) applyFileSizeOverrides(base, options.legacyFileSizes)
      if (options.originalFiles) missingOriginals = applyOriginalFiles(base, options.originalFiles)
      if (options.publishedFiles) applyPublishedFiles(base, options.publishedFiles)
      if (audio === 'stored') {
        // O downloader HTTPS também pode ler files diretamente; ali o nome
        // precisa descrever o mesmo BMP e tamanho que ARQUIVOS_SISTEMA usa.
        base.exec(
          "UPDATE files SET file_name = name WHERE type IN ('image_album', 'image_music', 'image', 'cover')",
        )
      }
      shiftTimestamps(base, options.timestampOffsetHours ?? 0)
      const sourceVersion = databaseVersion(base)
      version = options.versionOverride ?? sourceVersion
      if (!Number.isSafeInteger(version) || version < sourceVersion) {
        throw new Error(
          `versionOverride inválida: ${String(options.versionOverride)}; ` +
            `use um inteiro >= à versão ${sourceVersion} do D1`,
        )
      }
      for (const language of languages) {
        if (!row(base, 'SELECT 1 AS found FROM languages WHERE id_language = ?', language)) {
          throw new Error(`idioma ${language} ausente no export D1`)
        }
      }
    } finally {
      base.close()
    }

    const staged: Array<{ result: LegacyExportResult; stagedPath: string }> = []
    for (const language of languages) {
      const finalPath = join(outDir, `${language}_database.db`)
      const stagedPath = join(stagingDir, `${language}_database.db`)
      staged.push({
        result: buildLanguageDatabase(
          basePath,
          stagedPath,
          finalPath,
          language,
          version,
          options.bibleSource,
          audio,
          missingOriginals,
          options.publishedFiles,
          options.materializeMusicLists,
        ),
        stagedPath,
      })
    }

    for (const item of staged) {
      rmSync(item.result.path, { force: true })
      renameSync(item.stagedPath, item.result.path)
    }
    return staged.map((item) => item.result)
  } finally {
    rmSync(stagingDir, { recursive: true, force: true })
  }
}

function cli() {
  const args = new Map(
    process.argv.slice(2).map((argument) => {
      const [key, ...value] = argument.replace(/^--/, '').split('=')
      return [key, value.join('=')] as const
    }),
  )

  if (args.has('help')) {
    console.log(
      'Uso: node --experimental-strip-types scripts/export-legacy-sqlite.ts ' +
        '--export=replica.sql [--out=legacy-db] [--languages=pt,es] ' +
        '[--audio=legacy|stored] [--version=187] ' +
        '[--materialize-music-lists] ' +
        '[--bible-source=/backup/db_{lang}_186.db]\n' +
        'Use diretórios distintos para os modos legacy e stored.',
    )
    return
  }

  const exportSql = args.get('export')
  if (!exportSql) throw new Error('informe --export=<arquivo do wrangler d1 export>')
  const results = exportLegacyDatabases({
    exportSql,
    outDir: args.get('out') || undefined,
    languages: args.get('languages')?.split(',').filter(Boolean),
    bibleSource: args.get('bible-source') || undefined,
    audio: validateAudioMode(args.get('audio')),
    materializeMusicLists: args.has('materialize-music-lists'),
    versionOverride: args.has('version') ? Number(args.get('version')) : undefined,
  })

  for (const result of results) {
    const bible = result.bibleSource === 'd1' ? 'D1' : `preservada de ${result.bibleSource}`
    console.log(
      `${result.language}: ${result.path} — versão ${result.version}, ` +
        `${result.bibleVerses} versículos (${bible}), áudio ${result.audio}`,
    )
  }
}

const invokedPath = process.argv[1] ? pathToFileURL(resolve(process.argv[1])).href : ''
if (import.meta.url === invokedPath) {
  try {
    cli()
  } catch (error) {
    console.error(error instanceof Error ? error.message : error)
    process.exitCode = 1
  }
}
