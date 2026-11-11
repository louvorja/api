import {
  applyD1Migrations,
  createExecutionContext,
  env,
  waitOnExecutionContext,
} from 'cloudflare:test'
import { beforeAll, describe, expect, it } from 'vitest'
import worker from '../src/index.ts'
import { hashPassword } from '../src/lib/auth/password.ts'

type Json = Record<string, any>

const call = async (path: string, init: RequestInit & { token?: string; json?: unknown } = {}) => {
  const headers = new Headers(init.headers)
  if (init.token) headers.set('Authorization', `Bearer ${init.token}`)
  if (init.json !== undefined) headers.set('Content-Type', 'application/json')
  const ctx = createExecutionContext()
  const res = await worker.fetch(
    new Request(`https://api.test${path}`, {
      ...init,
      headers,
      body: init.json === undefined ? init.body : JSON.stringify(init.json),
    }),
    env,
    ctx,
  )
  await waitOnExecutionContext(ctx)
  return res
}

const snapshots: Record<string, Json[]> = {
  languages: [
    { id_language: 'pt', language: 'Português' },
    { id_language: 'es', language: 'Espanhol' },
  ],
  musics: [
    {
      id_music: 10,
      name: 'Coração aberto',
      id_file_image: 2,
      id_file_music: 3,
      id_file_instrumental_music: 4,
      id_language: 'pt',
    },
    {
      id_music: 11,
      name: 'Árvore da vida',
      id_file_image: 6,
      id_file_music: null,
      id_file_instrumental_music: null,
      id_language: 'pt',
    },
  ],
  categories: [
    {
      id_category: 1,
      name: 'Coleção',
      slug: 'collection',
      order: 2,
      type: 'collection',
      id_language: 'pt',
    },
    {
      id_category: 2,
      name: 'Hinário',
      slug: 'hymnal',
      order: 1,
      type: 'hymnal',
      id_language: 'pt',
    },
  ],
  categories_albums: [
    {
      id_category_album: 1,
      id_category: 2,
      category_name: 'Hinário',
      id_album: 1,
      album_name: 'Primeiro álbum',
      name: 'Coletânea',
      order: 4,
      id_language: 'pt',
    },
    {
      id_category_album: 2,
      id_category: 1,
      category_name: 'Coleção',
      id_album: 2,
      album_name: 'Segundo álbum',
      name: 'Avulso',
      order: 8,
      id_language: 'pt',
    },
  ],
  albums: [
    {
      id_album: 1,
      name: 'Primeiro álbum',
      id_file_image: 1,
      id_language: 'pt',
      color: '#111111',
      subtitle: '',
      order: '',
    },
    {
      id_album: 2,
      name: 'Segundo álbum',
      id_file_image: null,
      id_language: 'pt',
      color: '#222222',
      subtitle: '',
      order: '',
    },
  ],
  albums_musics: [
    {
      id_album_music: 1,
      id_music: 10,
      music_name: 'Coração aberto',
      id_album: 1,
      album_name: 'Primeiro álbum',
      track: 1,
      id_language: 'pt',
    },
    {
      id_album_music: 2,
      id_music: 11,
      music_name: 'Árvore da vida',
      id_album: 2,
      album_name: 'Segundo álbum',
      track: 2,
      id_language: 'pt',
    },
  ],
  lyrics: [
    {
      id_lyric: 20,
      id_music: 10,
      music: 'Coração aberto',
      lyric: 'Primeiro',
      id_file_image: 5,
      show_slide: 1,
      order: 1,
      id_language: 'pt',
    },
    {
      id_lyric: 21,
      id_music: 11,
      music: 'Árvore da vida',
      lyric: 'Segundo',
      show_slide: 0,
      order: 1,
      id_language: 'pt',
    },
  ],
  hymnal: [
    { id_music: 10, name: 'Hino dez', track: 1, id_language: 'pt' },
    { id_music: 11, name: 'Cântico onze', track: 2, id_language: 'pt' },
  ],
  files: [
    {
      id_file: 1,
      name: 'capa.bmp',
      type: 'image_album',
      size: 10,
      dir: '/covers',
      file_name: 'capa.bmp',
      version: 1,
    },
    {
      id_file: 2,
      name: 'fundo.jpg',
      type: 'image_music',
      size: 20,
      dir: '/images',
      file_name: 'fundo.jpg',
      version: 1,
    },
    {
      id_file: 3,
      name: 'faixa.mp3',
      type: 'music',
      size: 30,
      dir: '/musics/pt',
      file_name: 'faixa.mp3',
      duration: '00:03:00',
      version: 1,
    },
    {
      id_file: 4,
      name: 'playback.mp3',
      type: 'music',
      size: 40,
      dir: '/musics/pt',
      file_name: 'playback.mp3',
      duration: '00:04:00',
      version: 1,
    },
    {
      id_file: 5,
      name: 'slide.jpg',
      type: 'image_lyric',
      size: 50,
      dir: '/images',
      file_name: 'slide.jpg',
      version: 1,
    },
    {
      id_file: 6,
      name: 'fora.jpg',
      type: 'image_music',
      size: 60,
      dir: '/images',
      file_name: 'fora.jpg',
      version: 1,
    },
  ],
}

let token: string

beforeAll(async () => {
  await applyD1Migrations(env.DB, env.TEST_MIGRATIONS)
  const now = '2026-10-10 12:00:00'
  const password = await hashPassword('Senha@123')
  await env.DB.batch([
    env.DB.prepare(
      "INSERT INTO languages VALUES ('pt', 'Português', ?1, ?1), ('es', 'Espanhol', ?1, ?1)",
    ).bind(now),
    env.DB.prepare(
      'INSERT INTO files (id_file, name, type, size, dir, file_name, image_position, duration, version, created_at, updated_at) VALUES ' +
        "(1, 'capa.bmp', 'image_album', 10, '/covers', 'capa.jpg', NULL, NULL, 1, ?1, ?1), " +
        "(2, 'fundo.jpg', 'image_music', 20, '/images', 'fundo.jpg', NULL, NULL, 1, ?1, ?1), " +
        "(3, 'faixa.mp3', 'music', 30, '/musics/pt', 'faixa.opus', NULL, '00:03:00', 1, ?1, ?1), " +
        "(4, 'playback.mp3', 'music', 40, '/musics/pt', 'playback.opus', NULL, '00:04:00', 1, ?1, ?1), " +
        "(5, 'slide.jpg', 'image_lyric', 50, '/images', 'slide.jpg', NULL, NULL, 1, ?1, ?1), " +
        "(6, 'fora.jpg', 'image_music', 60, '/images', 'fora.jpg', NULL, NULL, 1, ?1, ?1)",
    ).bind(now),
    env.DB.prepare(
      'INSERT INTO albums (id_album, name, id_file_image, color, id_language, created_at, updated_at) VALUES ' +
        "(1, 'Primeiro álbum', 1, '#111111', 'pt', ?1, ?1), " +
        "(2, 'Segundo álbum', NULL, '#222222', 'pt', ?1, ?1)",
    ).bind(now),
    env.DB.prepare(
      'INSERT INTO categories (id_category, name, slug, "order", type, id_language, created_at, updated_at) VALUES ' +
        "(1, 'Coleção', 'collection', 2, 'collection', 'pt', ?1, ?1), " +
        "(2, 'Hinário', 'hymnal', 1, 'hymnal', 'pt', ?1, ?1)",
    ).bind(now),
    env.DB.prepare(
      'INSERT INTO categories_albums (id_category_album, id_category, id_album, name, "order", id_language) VALUES ' +
        "(1, 2, 1, 'Coletânea', 4, 'pt'), (2, 1, 2, 'Avulso', 8, 'pt')",
    ),
    env.DB.prepare(
      'INSERT INTO musics (id_music, name, id_file_image, id_file_music, id_file_instrumental_music, id_language, created_at, updated_at) VALUES ' +
        "(10, 'Coração aberto', 2, 3, 4, 'pt', ?1, ?1), " +
        "(11, 'Árvore da vida', 6, NULL, NULL, 'pt', ?1, ?1)",
    ).bind(now),
    env.DB.prepare(
      "INSERT INTO albums_musics (id_album_music, id_album, id_music, track, id_language) VALUES (1, 1, 10, 1, 'pt'), (2, 2, 11, 2, 'pt')",
    ),
    env.DB.prepare(
      'INSERT INTO lyrics (id_lyric, id_music, lyric, aux_lyric, id_file_image, time, instrumental_time, show_slide, "order", id_language) VALUES ' +
        "(20, 10, 'Primeiro', NULL, 5, '00:00:01', '00:00:00', 1, 1, 'pt'), " +
        "(21, 11, 'Segundo', NULL, NULL, '00:00:01', '00:00:00', 0, 1, 'pt')",
    ),
    env.DB.prepare(
      'INSERT INTO users (id, name, username, email, password, is_temporary_password, is_admin, permissions, created_at, updated_at) ' +
        "VALUES (1, 'Admin', 'admin-routes', 'admin-routes@louvorja.test', ?1, 0, 1, NULL, ?2, ?2)",
    ).bind(password, now),
  ])

  for (const [name, rows] of Object.entries(snapshots)) {
    await env.FILES.put(`rest/pt_${name}.json`, JSON.stringify(rows))
  }
  await env.FILES.put(
    'json_db/catalog.json',
    JSON.stringify([
      { kind: 'zeta' },
      { kind: 'alfa' },
      { kind: 'alfa' },
      { kind: '' },
      { kind: null },
      {},
    ]),
  )
  await env.FILES.put(
    'meta/params.json',
    JSON.stringify({
      db_version: 185,
      https_db_version: 189,
      pt_download: 'https://download.test/pt',
      es_download: 'https://download.test/es',
      pt_version: '26.11',
      es_version: '26.11',
      download: 'https://download.test/antigo',
    }),
  )

  const login = await call('/auth/login', {
    method: 'POST',
    json: { username: 'admin-routes', password: 'Senha@123' },
  })
  token = ((await login.json()) as Json).token
})

describe('contratos legados complementares', () => {
  it('anuncia a mesma versão do banco em JSON e INI', async () => {
    const json = await call('/params')
    expect(await json.json()).toMatchObject({ db_version: 189, https_db_version: 189 })

    const ini = await call('/params?type=env')
    const lines = (await ini.text()).split('\r\n')
    expect(lines).toContain('db_version=189')
    expect(lines).toContain('https_db_version=189')
  })
  it('extrai categorias únicas de uma coluna do json_db', async () => {
    const res = await call('/db/catalog/categories?column=kind')
    expect(res.status).toBe(200)
    expect(await res.json()).toEqual(['alfa', 'zeta'])

    const missingColumn = await call('/db/catalog/categories')
    expect(missingColumn.status).toBe(400)
    expect(await missingColumn.json()).toEqual({ error: 'Parâmetro column é obrigatório' })

    const missingTable = await call('/db/missing/categories?column=kind')
    expect(missingTable.status).toBe(404)
    expect(await missingTable.json()).toEqual({ error: 'Table not found' })
  })

  it('usa ?lang em /download e dá precedência ao idioma do path', async () => {
    await env.DB.prepare('DELETE FROM download_logs').run()
    expect(
      (
        await call('/download?lang=ES', {
          headers: {
            'CF-Connecting-IP': '203.0.113.8',
            'User-Agent': 'Delphi/LouvorJA',
          },
        })
      ).headers.get('location'),
    ).toBe('https://download.test/es')
    expect((await call('/download')).headers.get('location')).toBe('https://download.test/pt')
    expect((await call('/pt/download?lang=es')).headers.get('location')).toBe(
      'https://download.test/pt',
    )
    expect(
      await env.DB.prepare(
        'SELECT id_language, version, ip, browser FROM download_logs WHERE id_language = ? ORDER BY id_download_log LIMIT 1',
      )
        .bind('es')
        .first(),
    ).toMatchObject({
      id_language: 'es',
      version: '26.11',
      ip: '203.0.113.8',
      browser: 'Delphi/LouvorJA',
    })
    expect(
      (
        await env.DB.prepare('SELECT count(*) AS total FROM download_logs').first<{
          total: number
        }>()
      )?.total,
    ).toBe(3)
  })

  it('filtra /admin/files por todos os arquivos ligados ao álbum', async () => {
    const body = (await (
      await call('/admin/files?id_album=1&sort_by=id_file&limit=-1', { token })
    ).json()) as Json
    expect(body.data.map((row: Json) => row.id_file)).toEqual([1, 2, 3, 4, 5])

    const music = (await (
      await call('/admin/files?id_album=1&type=music&sort_by=id_file&limit=-1', { token })
    ).json()) as Json
    expect(music.data.map((row: Json) => row.id_file)).toEqual([3, 4])
  })
})

// O plano gratuito do D1 para de responder quando a cota diária estoura. As
// rotas públicas não têm autenticação, então o custo delas no banco precisa
// ser zero na leitura e limitado na escrita.
describe('custo das rotas públicas no banco', () => {
  const semBanco = new Proxy({} as D1Database, {
    get() {
      throw new Error('rota pública consultou o D1')
    },
  })

  const publicCall = async (path: string) => {
    const ctx = createExecutionContext()
    const res = await worker.fetch(
      new Request(`https://api.test${path}`),
      {
        FILES: env.FILES,
        FILES_ORIGINALS: env.FILES_ORIGINALS,
        DB: semBanco,
        RATE_LIMIT_FILES: env.RATE_LIMIT_FILES,
        RATE_LIMIT_METADATA: env.RATE_LIMIT_METADATA,
        RATE_LIMIT_GENERAL: env.RATE_LIMIT_GENERAL,
        BIBLE_LIMITER: env.BIBLE_LIMITER,
        JWT_SECRET: env.JWT_SECRET,
      },
      ctx,
    )
    await waitOnExecutionContext(ctx)
    return res
  }

  it('responde catálogo e vídeos online sem tocar o D1', async () => {
    await env.FILES.put(
      'rest/pt_collections_online.json',
      JSON.stringify({ channels: [], playlists: [], videos: [] }),
    )
    const paths = [
      '/params',
      '/db/catalog',
      '/db/catalog/categories?column=kind',
      '/json_db/catalog',
      '/pt/collections/online',
      '/onlinevideos',
      '/onlinevideos?tipo=videos&id=qualquer',
      '/onlinevideos?format=json&lang=pt',
      '/version',
      '/player?v=abc',
    ]
    for (const path of paths) {
      const res = await publicCall(path)
      expect(res.status, path).toBe(200)
    }
    // O redirecionamento não depende do registro: sem banco ele só não é gravado.
    expect((await publicCall('/download')).status).toBe(302)
  })

  it('para de gravar registros de download ao atingir o teto do dia', async () => {
    await env.DB.batch([
      env.DB.prepare('DELETE FROM download_logs'),
      env.DB.prepare('DELETE FROM public_write_budget'),
      env.DB.prepare(
        "INSERT INTO public_write_budget (day, name, used) VALUES (date('now'), 'download', 499)",
      ),
    ])
    const state = async () => ({
      logs: (
        await env.DB.prepare('SELECT count(*) AS total FROM download_logs').first<{
          total: number
        }>()
      )?.total,
      used: (
        await env.DB.prepare(
          "SELECT used FROM public_write_budget WHERE day = date('now') AND name = 'download'",
        ).first<{ used: number }>()
      )?.used,
    })

    await call('/download')
    expect(await state()).toEqual({ logs: 1, used: 500 })

    // Acima do teto o contador anda uma última vez e depois nada mais é escrito.
    await call('/download')
    expect(await state()).toEqual({ logs: 1, used: 501 })
    await call('/download')
    await call('/download')
    expect(await state()).toEqual({ logs: 1, used: 501 })
  })
})
