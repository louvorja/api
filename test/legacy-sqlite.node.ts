import assert from 'node:assert/strict'
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { DatabaseSync } from 'node:sqlite'
import test from 'node:test'
import {
  exportLegacyDatabases,
  LEGACY_TABLES,
  LEGACY_VIEWS,
} from '../scripts/export-legacy-sqlite.ts'
import { MATERIALIZED_MUSIC_LISTS } from '../scripts/lib/delphi-search.ts'

type Row = Record<string, unknown>

const schema = [
  readFileSync(resolve('migrations/0001_schema.sql'), 'utf8'),
  readFileSync(resolve('migrations/0002_row_counts.sql'), 'utf8'),
].join('\n')

function fixtureSql(includeBibleVerses = true): string {
  const verses = includeBibleVerses
    ? `
      INSERT INTO bible_verse
        (id_bible_verse, id_bible_version, id_bible_book, chapter, verse, text, id_language)
      VALUES
        (1, 1, 1, 1, 1, 'No princípio', 'pt'),
        (2, 2, 67, 1, 1, 'En el principio', 'es');
    `
    : ''

  return `${schema}
    INSERT INTO languages (id_language, language) VALUES ('pt', 'Português'), ('es', 'Español');

    INSERT INTO files
      (id_file, name, type, size, dir, file_name, image_position, version)
    VALUES
      (1, 'capa-pt.bmp', 'image_album', 101, '/covers', 'capa-pt.jpg', NULL, 1),
      (2, 'faixa-pt.mp3', 'music', 102, '/musics/pt/Album PT', 'faixa-pt.opus', NULL, 1),
      (3, 'playback-pt.mp3', 'music', 103, '/musics/pt/Album PT', 'playback-pt.mp3', NULL, 1),
      (4, 'fundo-pt.bmp', 'image_music', 104, '/images', 'fundo-pt.jpg', 3, 1),
      (5, 'capa-es.bmp', 'cover', 105, '/covers', 'capa-es.jpg', NULL, 1),
      (6, 'faixa-es.mp3', 'music', 106, '/musics/es/Album ES', 'faixa-es.opus', NULL, 1),
      (7, 'playback-es.mp3', 'music', 107, '/musics/es/Album ES', 'playback-es.mp3', NULL, 1),
      (8, 'fundo-es.bmp', 'image', 108, '/images', 'fundo-es.jpg', 4, 1);

    INSERT INTO albums (id_album, name, id_file_image, color, id_language)
    VALUES (1, 'Álbum PT', 1, '#112233', 'pt'), (2, 'Álbum ES', 5, '#445566', 'es');

    INSERT INTO categories (id_category, name, slug, "order", type, id_language)
    VALUES
      (1, 'Hinário', 'hymnal', 1, 'album', 'pt'),
      (2, 'Himnario', 'hymnal', 1, 'album', 'es');

    INSERT INTO musics
      (id_music, name, id_file_image, id_file_music, id_file_instrumental_music, id_language)
    VALUES
      (1, 'Ação', 4, 2, 3, 'pt'),
      (2, 'Canción', 8, 6, 7, 'es');

    INSERT INTO lyrics
      (id_lyric, id_music, lyric, aux_lyric, id_file_image, time, instrumental_time,
       show_slide, "order", id_language)
    VALUES
      (1, 1, 'Graça', 'Grace', 4, '00:00:05', '00:00:00', 1, 1, 'pt'),
      (2, 2, 'Canción', NULL, 8, '00:00:06', '00:00:07', 1, 1, 'es');

    INSERT INTO albums_musics (id_album_music, id_album, id_music, track, id_language)
    VALUES (1, 1, 1, 1, 'pt'), (2, 2, 2, 1, 'es');

    INSERT INTO categories_albums
      (id_category_album, id_category, id_album, name, "order", id_language)
    VALUES (1, 1, 1, '', 1, 'pt'), (2, 2, 2, '', 1, 'es');

    INSERT INTO configs (key, type, value) VALUES ('version_number', 'number', '186');

    INSERT INTO bible_version (id_bible_version, name, abbreviation, id_language)
    VALUES (1, 'Nova Tradução', 'NTLH', 'pt'), (2, 'Nueva Versión', 'NVI', 'es');

    INSERT INTO bible_book
      (id_bible_book, book_number, name, chapters, testament, keywords, abbreviation,
       color, id_language)
    VALUES
      (1, 1, 'Gênesis', 50, 1, 'genesis', 'Gn', '#112233', 'pt'),
      (67, 1, 'Génesis', 50, 1, 'genesis', 'Gn', '#112233', 'es');

    ${verses}

    INSERT INTO online_videos_channels
      (id_online_video_channel, channel_id, title, custom_url, default_image,
       default_image_base64, status, playlists, id_language)
    VALUES
      (1, 'canal-pt', 'Canal PT', '@pt', 'canal-pt.jpg', 'base64-pt', 'validated', '[]', 'pt'),
      (2, 'canal-es', 'Canal ES', '@es', 'canal-es.jpg', 'base64-es', 'validated', '[]', 'es');

    INSERT INTO online_videos_playlists
      (id_online_video_playlist, id_online_video_channel, playlist_id, title,
       default_image, default_image_base64, status, id_language)
    VALUES
      (1, 1, 'playlist-pt', 'Playlist PT', 'playlist-pt.jpg', 'base64-pt', 'validated', 'pt'),
      (2, 2, 'playlist-es', 'Playlist ES', 'playlist-es.jpg', 'base64-es', 'validated', 'es');

    INSERT INTO online_videos
      (id_online_video, id_online_video_playlist, video_id, title, sequence,
       default_image, default_image_base64, status, id_language)
    VALUES
      (1, 1, 'video-pt', 'Vídeo PT', 1, 'video-pt.jpg', 'base64-pt', 'validated', 'pt'),
      (2, 2, 'video-es', 'Vídeo ES', 1, 'video-es.jpg', 'base64-es', 'validated', 'es');
  `
}

function writeExport(root: string, name: string, includeBibleVerses = true): string {
  const path = join(root, name)
  writeFileSync(path, fixtureSql(includeBibleVerses))
  return path
}

function names(db: DatabaseSync, object: string): string[] {
  return db
    .prepare(`PRAGMA table_info("${object}")`)
    .all()
    .map((record) => String((record as Row).name))
}

function assertClassicListTypes(db: DatabaseSync) {
  for (const name of MATERIALIZED_MUSIC_LISTS) {
    const fields = db.prepare(`PRAGMA table_info(${name})`).all()
    assert.equal(fields.length, 22)
    for (const field of fields) {
      assert.ok(field.type, `${name}.${field.name} precisa de tipo declarado`)
      if (['ID', 'ID_ALBUM', 'FAIXA', 'LETRA', 'LETRA_SEMAC'].includes(String(field.name))) continue
      assert.match(String(field.type), /^NVARCHAR\(\d+\)$/, `${name}.${field.name}`)
    }
    const metadata = new Map(
      db
        .prepare(`SELECT * FROM ${name} WHERE 0`)
        .columns()
        .map((field) => [field.name, field.type]),
    )
    assert.match(metadata.get('NOME') ?? '', /^NVARCHAR\(\d+\)$/)
    assert.equal(metadata.get('ID_ALBUM'), 'INTEGER')
    assert.equal(metadata.get('FAIXA'), 'INTEGER')
    assert.equal(metadata.get('LETRA'), 'TEXT')
    assert.equal(metadata.get('LETRA_SEMAC'), 'TEXT')
    if (name === 'LISTA_MUSICAS') assert.equal(metadata.get('ID'), 'INTEGER')
    else assert.match(metadata.get('ID') ?? '', /^NVARCHAR\(\d+\)$/)
  }
}

test('gera os objetos Delphi, os campos essenciais e um banco por idioma', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-legacy-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const outDir = join(root, 'out')

  const results = exportLegacyDatabases({
    exportSql: writeExport(root, 'replica.sql'),
    outDir,
  })

  assert.deepEqual(
    results.map((result) => result.path),
    [join(outDir, 'pt_database.db'), join(outDir, 'es_database.db')],
  )
  assert.deepEqual(
    results.map((result) => result.bibleSource),
    ['d1', 'd1'],
  )
  assert.deepEqual(
    results.map((result) => result.audio),
    ['legacy', 'legacy'],
  )

  const db = new DatabaseSync(join(outDir, 'pt_database.db'), { readOnly: true })
  context.after(() => db.close())

  const legacy = new Map(
    (
      db
        .prepare(
          "SELECT name, type FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name",
        )
        .all() as Row[]
    ).map((record) => [String(record.name), String(record.type)]),
  )
  for (const table of LEGACY_TABLES) assert.equal(legacy.get(table), 'table', table)
  for (const view of LEGACY_VIEWS) assert.equal(legacy.get(view), 'view', view)

  assert.deepEqual(names(db, 'VERSAO'), ['ID', 'VERSAO_BD'])
  assert.deepEqual(names(db, 'ARQUIVOS_SISTEMA'), [
    'TIPO',
    'ARQUIVO',
    'URL',
    'TAMANHO',
    'TABELA',
    'CAMPO_ARQ',
    'CAMPO_ARQ_TAM',
    'CHAVE',
    'URL_DOWNLOAD',
  ])
  assert.equal(
    db.prepare('SELECT count(*) total FROM ARQUIVOS_ADICIONAIS WHERE TAMANHO = 0').get()?.total,
    15,
  )
  assert.deepEqual(names(db, 'ALBUM'), ['ID', 'NOME', 'IMAGEM', 'PERMITE_DESATIVAR'])
  assert.deepEqual(names(db, 'BIBLIA'), [
    'ID',
    'VERSAO',
    'LIVRO',
    'CAPITULO',
    'VERSICULO',
    'PASSAGEM',
  ])
  assert.deepEqual(names(db, 'MUSICAS_SLIDE'), [
    'TIPO',
    'MUSICA_ID',
    'LETRA_ID',
    'URL_MUSICA',
    'URL_MUSICA_PB',
    'LETRA',
    'LETRA_UCASE',
    'ORDEM',
    'IMAGEM',
    'IMAGEM_POSICAO',
    'TEMPO',
    'TEMPO_PB',
    'FUNDO_LETRA',
    'TAMANHO_LETRA',
    'COR_LETRA',
    'LETRA_AUX',
    'TAMANHO_LETRA_AUX',
    'COR_LETRA_AUX',
  ])
  assert.deepEqual(names(db, 'LISTA_MUSICAS_TODAS'), [
    'ID',
    'ID_ALBUM',
    'NOME_ALBUM',
    'NOME_ALBUM_COM',
    'FAIXA',
    'NOME',
    'NOME_COM',
    'TIPO_HASD',
    'TIPO_JA',
    'TIPO_BAIXADA',
    'TIPO_WEB',
    'TIPO_PERSO',
    'TIPO',
    'URL_ALBUM',
    'ALBUM',
    'URL',
    'URL_INSTRUMENTAL',
    'IDIOMA',
    'LETRA',
    'NOME_SEMAC',
    'LETRA_SEMAC',
    'NOME_ALBUM_COM_SEMAC',
  ])

  assert.deepEqual({ ...db.prepare('SELECT * FROM VERSAO').get() }, { ID: 1, VERSAO_BD: 186 })
  assert.deepEqual(
    db
      .prepare('SELECT ID, PASSAGEM FROM BIBLIA')
      .all()
      .map((record) => ({ ...record })),
    [{ ID: 1, PASSAGEM: 'No princípio' }],
  )
  assert.equal(db.prepare('SELECT NOME_SEMAC FROM HINARIO_ADVENTISTA').get()?.NOME_SEMAC, 'acao')
  assert.deepEqual(
    { ...db.prepare('SELECT URL, URL_INSTRUMENTAL, IMAGEM FROM MUSICAS').get() },
    {
      URL: 'faixa-pt.mp3',
      URL_INSTRUMENTAL: 'playback-pt.mp3',
      IMAGEM: 'fundo-pt.bmp',
    },
  )
  assert.equal(db.prepare('SELECT IMAGEM FROM ALBUM').get()?.IMAGEM, 'capa-pt.bmp')
  assert.deepEqual(
    { ...db.prepare('SELECT URL, URL_INSTRUMENTAL FROM HINARIO_ADVENTISTA').get() },
    { URL: 'faixa-pt.mp3', URL_INSTRUMENTAL: 'playback-pt.mp3' },
  )
  assert.equal(
    db.prepare("SELECT URL_MUSICA FROM MUSICAS_SLIDE WHERE TIPO = 'CAPA'").get()?.URL_MUSICA,
    'Album PT/faixa-pt.mp3',
  )
  assert.equal(db.prepare('SELECT count(*) AS total FROM files').get()?.total, 8)
  assert.deepEqual(
    db
      .prepare('SELECT id_language FROM languages')
      .all()
      .map((record) => ({ ...record })),
    [{ id_language: 'pt' }],
  )
  assert.equal(db.prepare('SELECT count(*) AS total FROM musics').get()?.total, 1)
  assert.equal(db.prepare('SELECT count(*) AS total FROM ONL_VIDEOS').get()?.total, 1)
  assert.equal(db.prepare('PRAGMA integrity_check').get()?.integrity_check, 'ok')

  for (const systemTable of [
    'configs',
    'users',
    'logs',
    'download_logs',
    'jwt_blacklist',
    'row_counts',
  ]) {
    assert.equal(legacy.has(systemTable), false, systemTable)
  }
})

test('modo stored usa áudio físico e preserva imagens BMP', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-legacy-stored-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const [result] = exportLegacyDatabases({
    exportSql: writeExport(root, 'replica.sql'),
    outDir: join(root, 'stored-output'),
    languages: ['pt'],
    audio: 'stored',
    versionOverride: 187,
    legacyFileSizes: new Map([['images/fundo-pt.jpg', 9_003]]),
  })

  assert.ok(result)
  assert.equal(result.audio, 'stored')
  assert.equal(result.version, 187)
  const db = new DatabaseSync(result.path, { readOnly: true })
  context.after(() => db.close())

  assert.deepEqual(
    { ...db.prepare('SELECT URL, URL_INSTRUMENTAL, IMAGEM FROM MUSICAS').get() },
    {
      URL: 'faixa-pt.opus',
      URL_INSTRUMENTAL: 'playback-pt.mp3',
      IMAGEM: 'fundo-pt.bmp',
    },
  )
  assert.deepEqual(
    { ...db.prepare('SELECT URL, URL_INSTRUMENTAL FROM HINARIO_ADVENTISTA').get() },
    { URL: 'faixa-pt.opus', URL_INSTRUMENTAL: 'playback-pt.mp3' },
  )
  assert.deepEqual(
    {
      ...db
        .prepare("SELECT URL_MUSICA, URL_MUSICA_PB, IMAGEM FROM MUSICAS_SLIDE WHERE TIPO = 'CAPA'")
        .get(),
    },
    {
      URL_MUSICA: 'Album PT/faixa-pt.opus',
      URL_MUSICA_PB: 'Album PT/playback-pt.mp3',
      IMAGEM: 'fundo-pt.bmp',
    },
  )
  assert.deepEqual({ ...db.prepare('SELECT * FROM VERSAO').get() }, { ID: 1, VERSAO_BD: 187 })
  assert.deepEqual(
    db
      .prepare(
        "SELECT TIPO, ARQUIVO FROM ARQUIVOS_SISTEMA WHERE TIPO IN ('MUSICA', 'MUSICA_PB') ORDER BY TIPO",
      )
      .all()
      .map((record) => ({ ...record })),
    [
      { TIPO: 'MUSICA', ARQUIVO: 'faixa-pt.opus' },
      { TIPO: 'MUSICA_PB', ARQUIVO: 'playback-pt.mp3' },
    ],
  )
  assert.equal(db.prepare('SELECT IMAGEM FROM ALBUM').get()?.IMAGEM, 'capa-pt.bmp')
  assert.equal(db.prepare('SELECT IMAGEM FROM MUSICAS_LETRA').get()?.IMAGEM, 'fundo-pt.bmp')
  assert.equal(db.prepare('SELECT TAMANHO_IMAGEM FROM MUSICAS').get()?.TAMANHO_IMAGEM, 9_003)
  assert.equal(
    db.prepare('SELECT file_name FROM files WHERE id_file = 1').get()?.file_name,
    'capa-pt.bmp',
  )
  assert.deepEqual(
    { ...db.prepare('SELECT file_name, size FROM files WHERE id_file = 4').get() },
    { file_name: 'fundo-pt.bmp', size: 9_003 },
  )
})

test('listas materializadas preservam os dados e as alterações locais do Delphi nos dois idiomas', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-search-tables-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const exportSql = join(root, 'replica.sql')
  const sql = `${fixtureSql()}
    INSERT INTO categories (id_category, name, slug, "order", type, id_language)
      VALUES (3, 'Diversas', 'misc', 2, 'album', 'pt');
    INSERT INTO albums (id_album, name, id_file_image, color, id_language)
      VALUES (3, 'Opcional', 1, '#112233', 'pt');
    INSERT INTO categories_albums (id_category_album, id_category, id_album, name, "order", id_language)
      VALUES (3, 3, 3, '', 2, 'pt');
    INSERT INTO albums_musics (id_album_music, id_album, id_music, track, id_language)
      VALUES (3, 3, 1, 2, 'pt');
  `
  writeFileSync(exportSql, sql)
  const views = exportLegacyDatabases({ exportSql, outDir: join(root, 'views'), audio: 'stored' })
  const tables = exportLegacyDatabases({
    exportSql,
    outDir: join(root, 'tables'),
    audio: 'stored',
    materializeMusicLists: true,
  })

  for (const [index, result] of tables.entries()) {
    const source = new DatabaseSync(views[index]!.path)
    const target = new DatabaseSync(result.path)
    context.after(() => source.close())
    context.after(() => target.close())
    const lists = [...MATERIALIZED_MUSIC_LISTS, 'LISTA_MUSICAS_PERSO', 'LISTA_MUSICAS_TODAS']
    const compare = () => {
      for (const name of lists) {
        const query = `SELECT * FROM ${name} ORDER BY ID, ID_ALBUM, NOME_ALBUM_COM, FAIXA, NOME`
        assert.deepEqual(target.prepare(query).all(), source.prepare(query).all(), name)
      }
    }
    for (const name of lists) {
      assert.deepEqual(names(target, name), names(source, name), name)
      const kind = (db: DatabaseSync) =>
        db.prepare('SELECT type FROM sqlite_master WHERE name = ?').get(name)?.type
      assert.equal(kind(source), 'view')
      assert.equal(
        kind(target),
        MATERIALIZED_MUSIC_LISTS.some((list) => list === name) ? 'table' : 'view',
      )
    }
    assertClassicListTypes(target)
    compare()
    const mutate = (statement: string) => {
      source.exec(statement)
      target.exec(statement)
      compare()
    }
    // O mesmo ID pode aparecer em vários álbuns; o hinário não pode ser desativado.
    mutate('INSERT INTO _ALBUM_IGNORAR (ID) VALUES (3)')
    mutate('UPDATE _ALBUM_IGNORAR SET ID = 1')
    mutate('DELETE FROM _ALBUM_IGNORAR')
    mutate(
      "INSERT INTO _COLETANEAS_PERSONALIZADAS VALUES ('pessoal', 'Graça em casa', 'local.slja')",
    )

    // A API entrega DELETE/INSERT, inclusive por playlist; IDs de vídeo podem se repetir.
    mutate("INSERT INTO ONL_PLAYLISTS VALUES ('extra', 'canal-pt', 'Extra', '', '')")
    mutate("INSERT INTO ONL_VIDEOS VALUES ('video-pt', 'extra', 'Ação ao vivo', 2, '', '')")
    mutate("INSERT INTO ONL_VIDEOS VALUES ('00123', 'extra', 'Graça', 3, '', '')")
    mutate(
      "UPDATE ONL_VIDEOS SET VIDEO_ID = 'novo', NOME = 'Renovação' WHERE PLAYLIST_ID = 'extra'",
    )
    mutate("UPDATE ONL_PLAYLISTS SET NOME = 'Louvor renovado' WHERE PLAYLIST_ID = 'extra'")
    mutate("UPDATE ONL_CANAIS SET NOME = 'Canal bênção' WHERE CANAL_ID = 'canal-pt'")
    mutate("UPDATE ONL_PLAYLISTS SET PLAYLIST_ID = 'mudou' WHERE PLAYLIST_ID = 'extra'")
    mutate("UPDATE ONL_PLAYLISTS SET PLAYLIST_ID = 'extra' WHERE PLAYLIST_ID = 'mudou'")
    mutate("DELETE FROM ONL_VIDEOS WHERE PLAYLIST_ID = 'extra'")
    mutate("DELETE FROM ONL_PLAYLISTS WHERE CANAL_ID = 'canal-pt'")
    mutate("INSERT INTO ONL_PLAYLISTS VALUES ('playlist-pt', 'canal-pt', 'Restaurada', '', '')")
    mutate("UPDATE ONL_CANAIS SET CANAL_ID = 'outro' WHERE CANAL_ID = 'canal-pt'")
    mutate("UPDATE ONL_CANAIS SET CANAL_ID = 'canal-pt' WHERE CANAL_ID = 'outro'")
    mutate('DELETE FROM ONL_CANAIS')
    mutate("INSERT INTO ONL_CANAIS VALUES ('canal-pt', 'Restaurado', '', '', '')")
    assert.equal(target.prepare('PRAGMA integrity_check').get()?.integrity_check, 'ok')
    assert.deepEqual(target.prepare('PRAGMA foreign_key_check').all(), [])
  }
  assert.equal(readFileSync(exportSql, 'utf8'), sql)
})

test('nomes têm metadados de string para o Classic, preservando Unicode, nulos e o schema de origem', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-classic-types-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const exportSql = join(root, 'replica.sql')
  const title = 'Canção 🎵 — Acción '.repeat(100)
  // D1 exporta identificadores entre aspas; o nome pode ser maior que o padrão do driver.
  const sql = `${fixtureSql().replace('  name                       TEXT,', '  "name"                     TEXT,')}
    UPDATE musics SET name = '${title}';
    INSERT INTO musics (id_music, name, id_language) VALUES (100, NULL, 'pt');
    DELETE FROM musics WHERE id_music = 100;
    INSERT INTO musics (id_music, name, id_language) VALUES (3, NULL, 'pt');
    INSERT INTO albums_musics (id_album_music, id_album, id_music, track, id_language)
      VALUES (3, 1, 3, 2, 'pt');
    CREATE INDEX music_name_lookup ON musics ("name");
  `
  writeFileSync(exportSql, sql)
  const original = new DatabaseSync(':memory:')
  context.after(() => original.close())
  original.exec(sql)
  for (const audio of ['legacy', 'stored'] as const) {
    const results = exportLegacyDatabases({
      exportSql,
      outDir: join(root, audio),
      audio,
      materializeMusicLists: audio === 'stored',
    })
    for (const result of results) {
      const db = new DatabaseSync(result.path)
      context.after(() => db.close())
      assert.deepEqual(
        db.prepare('SELECT * FROM musics ORDER BY id_music').all(),
        original
          .prepare('SELECT * FROM musics WHERE id_language = ? ORDER BY id_music')
          .all(result.language),
      )
      assert.deepEqual(
        db.prepare('PRAGMA foreign_key_list(musics)').all(),
        original.prepare('PRAGMA foreign_key_list(musics)').all(),
      )
      assert.deepEqual(
        db.prepare('PRAGMA index_list(musics)').all(),
        original.prepare('PRAGMA index_list(musics)').all(),
      )
      // Mesmo caminho da tela de álbum: o driver precisa do tipo declarado, não só typeof(valor).
      const albumQuery = db.prepare(`
        SELECT DISTINCT M.NOME AS NOME FROM MUSICAS M
        LEFT JOIN ALBUM_MUSICAS A ON A.ID_MUSICA = M.ID WHERE ID_ALBUM = ?
      `)
      const type = albumQuery.columns()[0]?.type ?? ''
      assert.match(type, /^NVARCHAR\(\d+\)$/)
      assert.ok(Number(type.match(/\d+/)?.[0]) >= title.length)
      assert.equal(
        db.prepare('SELECT NOME FROM MUSICAS WHERE ID = ?').get(result.language === 'pt' ? 1 : 2)
          ?.NOME,
        title,
      )
      if (result.language === 'pt') {
        assert.equal(db.prepare('SELECT NOME FROM MUSICAS WHERE ID = 3').get()?.NOME, null)
      }
      assert.equal(
        db.prepare("SELECT seq FROM sqlite_sequence WHERE name = 'musics'").get()?.seq,
        100,
      )
      assert.equal(
        db.prepare("SELECT type FROM sqlite_master WHERE name = 'MUSICAS'").get()?.type,
        'view',
      )
      if (audio === 'stored') {
        assertClassicListTypes(db)
        const name = db.prepare('SELECT NOME FROM LISTA_MUSICAS WHERE ID = ?')
        const value = name.get(result.language === 'pt' ? 1 : 2)?.NOME
        assert.ok(typeof value === 'string' && value.startsWith(title))
        assert.ok(Number(name.columns()[0]?.type?.match(/\d+/)?.[0]) >= value.length)
      }
      assert.deepEqual(db.prepare('PRAGMA foreign_key_check').all(), [])
      assert.equal(db.prepare('PRAGMA integrity_check').get()?.integrity_check, 'ok')
    }
  }
  assert.equal(
    original.prepare("SELECT type FROM pragma_table_info('musics') WHERE name = 'name'").get()
      ?.type,
    'TEXT',
  )
  assert.equal(readFileSync(exportSql, 'utf8'), sql)
})

test('listas vazias também declaram os tipos para o Classic', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-classic-empty-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const exportSql = join(root, 'replica.sql')
  writeFileSync(
    exportSql,
    `${fixtureSql()}
    DELETE FROM lyrics; DELETE FROM albums_musics; DELETE FROM musics; DELETE FROM online_videos;
  `,
  )
  const results = exportLegacyDatabases({
    exportSql,
    outDir: join(root, 'out'),
    audio: 'stored',
    materializeMusicLists: true,
  })
  for (const result of results) {
    const db = new DatabaseSync(result.path, { readOnly: true })
    context.after(() => db.close())
    assertClassicListTypes(db)
    for (const name of MATERIALIZED_MUSIC_LISTS) {
      assert.equal(db.prepare(`SELECT count(*) total FROM ${name}`).get()?.total, 0)
    }
    assert.match(
      db.prepare('SELECT NOME FROM MUSICAS').columns()[0]?.type ?? '',
      /^NVARCHAR\(\d+\)$/,
    )
  }
})

test('livros da Bíblia e álbuns chegam como string ao Classic, também com a Bíblia preservada', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-classic-bible-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const outDir = join(root, 'out')
  const original = new DatabaseSync(':memory:')
  context.after(() => original.close())
  original.exec(fixtureSql())

  const fromD1 = exportLegacyDatabases({ exportSql: writeExport(root, 'with-bible.sql'), outDir })
  const preserved = exportLegacyDatabases({
    exportSql: writeExport(root, 'without-bible.sql', false),
    outDir,
    audio: 'stored',
    materializeMusicLists: true,
  })
  for (const result of [...fromD1, ...preserved]) {
    const db = new DatabaseSync(result.path, { readOnly: true })
    const declared = (sql: string, column: string) =>
      db
        .prepare(sql)
        .columns()
        .find((field) => field.name === column)?.type ?? ''
    // Consultas da tela da Bíblia, das grades de álbuns e da lista de álbuns da letra.
    for (const column of ['LIVRO', 'SIGLA', 'PALAVRACHAVE']) {
      assert.match(declared('SELECT * FROM LIVRO ORDER BY ID', column), /^NVARCHAR\(\d+\)$/)
    }
    assert.match(
      declared(
        "SELECT * FROM ALBUM WHERE PERMITE_DESATIVAR = 'S' AND ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR) ORDER BY NOME",
        'NOME',
      ),
      /^NVARCHAR\(\d+\)$/,
    )
    assert.match(
      declared(
        'SELECT A.ID,A.NOME FROM ALBUM_MUSICAS AM LEFT JOIN ALBUM A ON (A.ID = AM.ID_ALBUM) WHERE AM.ID_MUSICA = 1 ORDER BY A.NOME',
        'NOME',
      ),
      /^NVARCHAR\(\d+\)$/,
    )
    assert.deepEqual(
      { ...db.prepare('SELECT LIVRO, SIGLA, PALAVRACHAVE FROM LIVRO').get() },
      result.language === 'pt'
        ? { LIVRO: 'Gênesis', SIGLA: 'Gn', PALAVRACHAVE: 'genesis' }
        : { LIVRO: 'Génesis', SIGLA: 'Gn', PALAVRACHAVE: 'genesis' },
    )
    for (const table of ['albums', 'bible_book']) {
      assert.deepEqual(
        db.prepare(`SELECT * FROM ${table} ORDER BY 1`).all(),
        original
          .prepare(`SELECT * FROM ${table} WHERE id_language = ? ORDER BY 1`)
          .all(result.language),
      )
      assert.deepEqual(
        db.prepare(`PRAGMA index_list(${table})`).all(),
        original.prepare(`PRAGMA index_list(${table})`).all(),
      )
    }
    assert.equal(db.prepare('PRAGMA integrity_check').get()?.integrity_check, 'ok')
    db.close()
  }
  assert.equal(
    original.prepare("SELECT type FROM pragma_table_info('bible_book') WHERE name = 'name'").get()
      ?.type,
    'TEXT',
  )
})

test('modo legacy grava no SQLite o tamanho real do MP3 materializado', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-legacy-size-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const [result] = exportLegacyDatabases({
    exportSql: writeExport(root, 'replica.sql'),
    outDir: join(root, 'legacy'),
    languages: ['pt'],
    legacyFileSizes: new Map([
      ['musics/pt/Album PT/faixa-pt.opus', 9_001],
      ['musics/pt/Album PT/playback-pt.mp3', 9_002],
    ]),
  })

  assert.ok(result)
  const db = new DatabaseSync(result.path, { readOnly: true })
  context.after(() => db.close())
  assert.deepEqual(
    db
      .prepare(
        "SELECT TIPO, TAMANHO FROM ARQUIVOS_SISTEMA WHERE TIPO IN ('MUSICA', 'MUSICA_PB') ORDER BY TIPO",
      )
      .all()
      .map((record) => ({ ...record })),
    [
      { TIPO: 'MUSICA', TAMANHO: 9_001 },
      { TIPO: 'MUSICA_PB', TAMANHO: 9_002 },
    ],
  )
})

const additionalPaths = [
  'config/1minuto_escsb.mp3',
  'config/5minutos_escsb.mp3',
  'config/abertura_escsb.mp3',
  'config/fontes/din-condensed-bold.ttf',
  'bass.dll',
  'borlndmm.dll',
  'midas.dll',
  'ssleay32.dll',
  'libeay32.dll',
  'config/ico/louvorja_slja.ico',
  'bassopus.dll',
  'config/server/404.html',
  'config/server/api-v2.html',
  'config/server/index.html',
  'config/server/mirror.html',
]

function publishedInventory() {
  const inventory = new Map([
    ['covers/capa-pt.bmp', 8_001],
    ['musics/pt/Album PT/faixa-pt.opus', 8_002],
    ['musics/pt/Album PT/playback-pt.mp3', 8_003],
    ['images/fundo-pt.bmp', 8_004],
    ['covers/capa-es.bmp', 8_005],
    ['musics/es/Album ES/faixa-es.opus', 8_006],
    ['musics/es/Album ES/playback-es.mp3', 8_007],
    ['images/fundo-es.bmp', 8_008],
    // A presença do JPEG e do MP3 antigo não pode mudar a seleção do Delphi.
    ['images/fundo-pt.jpg', 4_004],
    ['musics/pt/Album PT/faixa-pt.mp3', 16_002],
    ['meta/params.json', 500],
  ])
  for (const [index, path] of additionalPaths.entries())
    inventory.set(`system/${path}`, 10_000 + index)
  return inventory
}

test('files, views e inventário publicado concordam no nome e tamanho de Opus, MP3 e BMP', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-delphi-published-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const [result] = exportLegacyDatabases({
    exportSql: writeExport(root, 'replica.sql'),
    outDir: join(root, 'stored'),
    languages: ['pt'],
    audio: 'stored',
    publishedFiles: publishedInventory(),
  })
  assert.ok(result)
  const db = new DatabaseSync(result.path, { readOnly: true })
  context.after(() => db.close())
  assert.deepEqual(
    {
      ...db
        .prepare(
          'SELECT URL, TAMANHO_ARQUIVO, URL_INSTRUMENTAL, TAMANHO_ARQUIVO_PB, IMAGEM, TAMANHO_IMAGEM FROM MUSICAS',
        )
        .get(),
    },
    {
      URL: 'faixa-pt.opus',
      TAMANHO_ARQUIVO: 8_002,
      URL_INSTRUMENTAL: 'playback-pt.mp3',
      TAMANHO_ARQUIVO_PB: 8_003,
      IMAGEM: 'fundo-pt.bmp',
      TAMANHO_IMAGEM: 8_004,
    },
  )
  assert.equal(
    db.prepare('SELECT file_name FROM files WHERE id_file = 2').get()?.file_name,
    'faixa-pt.opus',
  )
  const inventory = publishedInventory()
  for (const file of db.prepare('SELECT dir, file_name, size FROM files').all()) {
    const key = `${String(file.dir).replace(/^\/+|\/+$/g, '')}/${String(file.file_name)}`
    assert.equal(inventory.get(key), file.size, key)
  }
  assert.deepEqual(
    {
      ...db
        .prepare(
          'SELECT files.file_name, files.size, ALBUM.IMAGEM FROM files JOIN albums ON albums.id_file_image = files.id_file JOIN ALBUM ON ALBUM.ID = albums.id_album',
        )
        .get(),
    },
    { file_name: 'capa-pt.bmp', size: 8_001, IMAGEM: 'capa-pt.bmp' },
  )
  assert.deepEqual(
    { ...db.prepare('SELECT file_name, size FROM files WHERE id_file = 4').get() },
    { file_name: 'fundo-pt.bmp', size: 8_004 },
  )
  assert.deepEqual(
    db
      .prepare(
        "SELECT TIPO, ARQUIVO, TAMANHO FROM ARQUIVOS_SISTEMA WHERE TIPO IN ('MUSICA', 'MUSICA_PB', 'IMAGEM_FUNDO') ORDER BY TIPO",
      )
      .all()
      .map((row) => ({ ...row })),
    [
      { TIPO: 'IMAGEM_FUNDO', ARQUIVO: 'fundo-pt.bmp', TAMANHO: 8_004 },
      { TIPO: 'MUSICA', ARQUIVO: 'faixa-pt.opus', TAMANHO: 8_002 },
      { TIPO: 'MUSICA_PB', ARQUIVO: 'playback-pt.mp3', TAMANHO: 8_003 },
    ],
  )
})

test('ausência de Opus ou BMP cancela geração mesmo existindo MP3 antigo ou JPEG', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-delphi-missing-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const exportSql = writeExport(root, 'replica.sql')
  const outDir = join(root, 'stored')
  const [before] = exportLegacyDatabases({ exportSql, outDir })
  assert.ok(before)
  const previousBytes = readFileSync(before.path)
  for (const key of ['musics/pt/Album PT/faixa-pt.opus', 'images/fundo-pt.bmp']) {
    const publishedFiles = publishedInventory()
    publishedFiles.delete(key)
    assert.throws(
      () => exportLegacyDatabases({ exportSql, outDir, audio: 'stored', publishedFiles }),
      /1 arquivo\(s\) do Delphi ausente\(s\)/,
    )
    assert.deepEqual(readFileSync(before.path), previousBytes)
  }
})

test('inventário publicado rejeita tamanhos inválidos e inventários conflitantes', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-delphi-invalid-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const options = {
    exportSql: writeExport(root, 'replica.sql'),
    outDir: join(root, 'stored'),
    audio: 'stored' as const,
    publishedFiles: publishedInventory(),
  }
  assert.throws(
    () => exportLegacyDatabases({ ...options, originalFiles: new Map() }),
    /não pode combinar/,
  )
  assert.throws(() => exportLegacyDatabases({ ...options, audio: 'legacy' }), /exige audio=stored/)
  options.publishedFiles.set('images/fundo-pt.bmp', -1)
  assert.throws(() => exportLegacyDatabases(options), /tamanho publicado inválido/)
})

test('mapeia downloads PT e ES, publica os 15 adicionais e conserva a versão 185', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-delphi-downloads-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const exportSql = join(root, 'replica.sql')
  const source = fixtureSql().replace(
    "'version_number', 'number', '186'",
    "'version_number', 'number', '185'",
  )
  writeFileSync(exportSql, source)
  const publishedFiles = publishedInventory()
  const results = exportLegacyDatabases({
    exportSql,
    outDir: join(root, 'out'),
    audio: 'stored',
    versionOverride: 185,
    publishedFiles,
  })
  assert.deepEqual(
    results.map((result) => result.version),
    [185, 185],
  )
  assert.equal(readFileSync(exportSql, 'utf8'), source)
  for (const result of results) {
    const db = new DatabaseSync(result.path, { readOnly: true })
    context.after(() => db.close())
    const lang = result.language
    const album = `Album ${lang.toUpperCase()}`
    const expected = [
      [
        'MUSICA',
        `config\\musicas\\${album}\\faixa-${lang}.opus`,
        `musics/${lang}/${album}/faixa-${lang}.opus`,
      ],
      [
        'MUSICA_PB',
        `config\\musicas\\${album}\\playback-${lang}.mp3`,
        `musics/${lang}/${album}/playback-${lang}.mp3`,
      ],
      ['IMAGEM_ALBUM', `config\\capas\\capa-${lang}.bmp`, `covers/capa-${lang}.bmp`],
      ['IMAGEM_FUNDO', `config\\imagens\\fundo-${lang}.bmp`, `images/fundo-${lang}.bmp`],
      ['IMAGEM_FUNDO_CAPA', `config\\imagens\\fundo-${lang}.bmp`, `images/fundo-${lang}.bmp`],
    ] as const
    for (const [type, local, remote] of expected) {
      assert.deepEqual(
        {
          ...db
            .prepare('SELECT URL, URL_DOWNLOAD, TAMANHO FROM ARQUIVOS_SISTEMA WHERE TIPO = ?')
            .get(type),
        },
        { URL: local, URL_DOWNLOAD: remote, TAMANHO: publishedFiles.get(remote) },
      )
    }
    const extras = db
      .prepare('SELECT URL, URL_DOWNLOAD, TAMANHO FROM ARQUIVOS_ADICIONAIS ORDER BY URL_DOWNLOAD')
      .all()
    assert.deepEqual(
      extras.map((extra) => ({ ...extra })),
      additionalPaths
        .map((path) => ({
          URL: path.replaceAll('/', '\\'),
          URL_DOWNLOAD: `system/${path}`,
          TAMANHO: publishedFiles.get(`system/${path}`),
        }))
        .sort((left, right) => (left.URL_DOWNLOAD < right.URL_DOWNLOAD ? -1 : 1)),
    )
    assert.equal(
      db
        .prepare("SELECT count(*) total FROM ARQUIVOS_SISTEMA WHERE TIPO = 'ARQUIVOS_ADICIONAIS'")
        .get()?.total,
      15,
    )
    for (const record of db.prepare('SELECT URL_DOWNLOAD, TAMANHO FROM ARQUIVOS_SISTEMA').all()) {
      assert.equal(record.TAMANHO, publishedFiles.get(String(record.URL_DOWNLOAD)))
      assert.ok(Number(record.TAMANHO) > 0)
    }
    assert.equal(db.prepare('SELECT VERSAO_BD FROM VERSAO').get()?.VERSAO_BD, 185)
  }
})

test('nomes iguais em álbuns e idiomas distintos conservam o arquivo referenciado por ID', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-delphi-same-name-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const exportSql = writeExport(root, 'replica.sql')
  writeFileSync(
    exportSql,
    readFileSync(exportSql, 'utf8') +
      `
    INSERT INTO files (id_file, name, type, size, dir, file_name, version)
      VALUES (9, 'faixa-pt.mp3', 'music', 9009, '/musics/pt/Outro Album', 'faixa-pt.opus', 1);
    INSERT INTO albums (id_album, name, id_file_image, id_language) VALUES (3, 'Outro Album', 1, 'pt');
    INSERT INTO musics (id_music, name, id_file_music, id_file_image, id_language)
      VALUES (3, 'Outra música', 9, 4, 'pt');
    INSERT INTO albums_musics (id_album_music, id_album, id_music, track, id_language)
      VALUES (3, 3, 3, 1, 'pt');
    UPDATE files SET name = 'faixa-pt.mp3', file_name = 'faixa-pt.opus' WHERE id_file = 6;
  `,
  )
  const inventory = publishedInventory()
  inventory.set('musics/pt/Outro Album/faixa-pt.opus', 9_009)
  inventory.set('musics/es/Album ES/faixa-pt.opus', 8_006)
  const results = exportLegacyDatabases({
    exportSql,
    outDir: join(root, 'out'),
    audio: 'stored',
    publishedFiles: inventory,
  })
  const actual = results.flatMap((result) => {
    const db = new DatabaseSync(result.path, { readOnly: true })
    try {
      return db
        .prepare(
          "SELECT URL, URL_DOWNLOAD, TAMANHO FROM ARQUIVOS_SISTEMA WHERE TIPO = 'MUSICA' AND ARQUIVO = 'faixa-pt.opus'",
        )
        .all()
        .map((record) => ({ ...record }))
    } finally {
      db.close()
    }
  })
  assert.deepEqual(
    actual.sort((left, right) =>
      String(left.URL_DOWNLOAD).localeCompare(String(right.URL_DOWNLOAD)),
    ),
    [
      {
        URL: 'config\\musicas\\Album ES\\faixa-pt.opus',
        URL_DOWNLOAD: 'musics/es/Album ES/faixa-pt.opus',
        TAMANHO: 8_006,
      },
      {
        URL: 'config\\musicas\\Album PT\\faixa-pt.opus',
        URL_DOWNLOAD: 'musics/pt/Album PT/faixa-pt.opus',
        TAMANHO: 8_002,
      },
      {
        URL: 'config\\musicas\\Outro Album\\faixa-pt.opus',
        URL_DOWNLOAD: 'musics/pt/Outro Album/faixa-pt.opus',
        TAMANHO: 9_009,
      },
    ],
  )
})

test('adicional ausente ou vazio impede substituir bancos existentes', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-delphi-extra-missing-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const exportSql = writeExport(root, 'replica.sql')
  const outDir = join(root, 'out')
  const previous = exportLegacyDatabases({ exportSql, outDir }).map((result) => ({
    path: result.path,
    body: readFileSync(result.path),
  }))
  for (const size of [undefined, 0, -1, Number.NaN]) {
    const publishedFiles = publishedInventory()
    const key = 'system/config/server/index.html'
    if (size === undefined) publishedFiles.delete(key)
    else publishedFiles.set(key, size)
    assert.throws(
      () => exportLegacyDatabases({ exportSql, outDir, audio: 'stored', publishedFiles }),
      /(?:download ausente|tamanho publicado inválido).*system\/config\/server\/index\.html/,
    )
    for (const result of previous) assert.deepEqual(readFileSync(result.path), result.body)
  }
})

test('recusa caminho inseguro ou duas remotas para o mesmo destino local', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-delphi-invalid-download-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const exportSql = join(root, 'replica.sql')
  writeFileSync(
    exportSql,
    fixtureSql() + "UPDATE files SET file_name = '../escape.opus' WHERE id_file = 2;",
  )
  assert.throws(
    () => exportLegacyDatabases({ exportSql, outDir: join(root, 'invalid'), audio: 'stored' }),
    /caminho de download inválido/,
  )
  writeFileSync(
    exportSql,
    fixtureSql() +
      `
    INSERT INTO files (id_file, name, type, size, dir, file_name, version)
      VALUES (9, 'fundo-pt.bmp', 'image_music', 9009, '/images/outro', 'fundo-pt.jpg', 1);
    UPDATE lyrics SET id_file_image = 9 WHERE id_lyric = 1;
  `,
  )
  assert.throws(
    () => exportLegacyDatabases({ exportSql, outDir: join(root, 'ambiguous'), audio: 'stored' }),
    /download ambíguo para config\\imagens\\fundo-pt\.bmp/,
  )
})

test('preserva a Bíblia do banco anterior quando o export D1 não traz versículos', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-legacy-preserve-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const outDir = join(root, 'out')

  exportLegacyDatabases({
    exportSql: writeExport(root, 'with-bible.sql'),
    outDir,
    languages: ['pt'],
  })
  const [result] = exportLegacyDatabases({
    exportSql: writeExport(root, 'without-bible.sql', false),
    outDir,
    languages: ['pt'],
  })

  assert.ok(result)
  assert.equal(result.bibleSource, join(outDir, 'pt_database.db'))
  assert.equal(result.bibleVerses, 1)
  const db = new DatabaseSync(result.path, { readOnly: true })
  context.after(() => db.close())
  assert.equal(db.prepare('SELECT PASSAGEM FROM BIBLIA').get()?.PASSAGEM, 'No princípio')
})

test('recusa a primeira geração sem Bíblia e explica a fonte exigida', (context) => {
  const root = mkdtempSync(join(tmpdir(), 'louvorja-legacy-no-bible-'))
  context.after(() => rmSync(root, { recursive: true, force: true }))
  const outDir = join(root, 'out')

  assert.throws(
    () =>
      exportLegacyDatabases({
        exportSql: writeExport(root, 'without-bible.sql', false),
        outDir,
        languages: ['pt'],
      }),
    /inclua a Bíblia no seed D1 ou informe --bible-source/,
  )
  assert.equal(existsSync(join(outDir, 'pt_database.db')), false)
})
