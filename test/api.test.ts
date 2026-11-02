import { createExecutionContext, env, waitOnExecutionContext } from 'cloudflare:test'
import { beforeAll, describe, expect, it } from 'vitest'
import worker from '../src/index.ts'

const call = async (path: string, init?: RequestInit) => {
  const ctx = createExecutionContext()
  const res = await worker.fetch(new Request(`https://api.test${path}`, init), env, ctx)
  await waitOnExecutionContext(ctx)
  return res
}

beforeAll(async () => {
  await env.FILES.put('json_db/config.json', JSON.stringify({ version_number: 185 }))
  await env.FILES.put('json_db/music_1.json', JSON.stringify({ id_music: 1 }))
  await env.FILES.put('meta/bible-bundle.zip', new Uint8Array([80, 75, 3, 4]))
  await env.FILES.put('meta/params.json', JSON.stringify({ db_version: '185', help: 'x' }))
  await env.FILES.put('covers/1992.jpg', new Uint8Array([1, 2, 3, 4, 5]))
  await env.FILES.put('musics/pt/A/B.opus', new Uint8Array(100))
  await env.FILES.put('musics/pt/A/So Mp3.mp3', new Uint8Array(50))

  await env.FILES.put(
    'rest/pt_categoryslug_doxology_albums.json',
    JSON.stringify(Array.from({ length: 40 }, (_, i) => ({ id_album: i + 1 }))),
  )
  await env.FILES.put(
    'rest/pt_collections_online.json',
    JSON.stringify({ channels: [], playlists: [], videos: [] }),
  )
  await env.FILES.put('meta/onlinevideos.txt', 'DELETE FROM ONL_CANAIS|INSERT INTO ...')
})

describe('json_db', () => {
  it('serve as coleções que antes eram só REST como chaves do json_db', async () => {
    await env.FILES.put(
      'rest/pt_categoryslug_children_albums.json',
      JSON.stringify([{ id_album: 9, url_image: '/covers/9.jpg' }]),
    )
    const online = await call('/json_db/pt_collections_online')
    expect(online.status).toBe(200)
    expect(await online.json()).toEqual({ channels: [], playlists: [], videos: [] })

    const children = await call('/json_db/pt_children_albums')
    expect(children.status).toBe(200)
    expect(children.headers.get('cache-control')).toBe('private, max-age=86400')
    expect(await children.json()).toEqual([
      { id_album: 9, url_image: 'https://files.louvorja.com.br/covers/9.jpg' },
    ])

    // A chave do json_db devolve a lista inteira, sem o envelope paginado.
    const doxology = (await (await call('/json_db/pt_doxology_albums')).json()) as unknown[]
    expect(doxology).toHaveLength(40)

    expect((await call('/json_db/es_children_albums')).status).toBe(404)
    expect((await call('/json_db/fr_collections_online')).status).toBe(404)
  })

  it('marca as rotas REST equivalentes como descontinuadas', async () => {
    for (const path of ['/pt/collections/online', '/pt/albums/category/doxology']) {
      expect((await call(path)).headers.get('deprecation'), path).toBe('true')
    }
    expect((await call('/json_db/pt_collections_online')).headers.get('deprecation')).toBeNull()
  })

  it('serve um arquivo existente', async () => {
    const res = await call('/json_db/music_1')
    expect(res.status).toBe(200)
    expect(res.headers.get('cache-control')).toBe('private, max-age=86400')
    expect(await res.json()).toEqual({ id_music: 1 })
  })

  it('404 no formato legado, sem vazar caminho interno', async () => {
    const res = await call('/json_db/nao_existe')
    expect(res.status).toBe(404)
    const body = (await res.json()) as Record<string, unknown>
    expect(body.error).toBe('Arquivo não encontrado!')
    expect(body.details).toContain('/documentation')
    expect(body.documentation).toBe('https://api.test/documentation')
    expect(body.path).toBeUndefined()
  })

  it('caminho que nenhuma rota atende manda o cliente para a documentação', async () => {
    for (const path of ['/nao-existe', '/pt/musics', '/.env', '/json_db/a/b']) {
      const res = await call(path)
      expect(res.status, path).toBe(404)
      expect(res.headers.get('cache-control'), path).toBe('no-store')
      const body = (await res.json()) as Record<string, string>
      expect(body.error, path).toBe('Caminho não encontrado')
      expect(body.details, path).toContain('/documentation')
      expect(body.documentation, path).toBe('https://api.test/documentation')
    }
    // Chave que não existe numa rota que existe segue como "arquivo".
    expect(((await (await call('/json_db/nao_existe')).json()) as { error: string }).error).toBe(
      'Arquivo não encontrado!',
    )
  })

  it('rejeita chave fora do padrão em vez de descer no bucket', async () => {
    expect((await call('/json_db/a.b')).status).toBe(404)
  })

  it('marca o 404 como no-store para não envenenar a borda', async () => {
    const res = await call('/json_db/nao_existe')
    expect(res.headers.get('cache-control')).toBe('no-store')
  })
})

describe('bundles', () => {
  it('serve o bundle bíblico separado do banco completo', async () => {
    const res = await call('/db/bible-bundle')
    expect(res.status).toBe(200)
    expect(res.headers.get('content-type')).toContain('application/zip')
  })

  it('responde HEAD do bundle bíblico sem corpo e com cache longo', async () => {
    const res = await call('/db/bible-bundle', { method: 'HEAD' })
    expect(res.status).toBe(200)
    expect(res.headers.get('etag')).toMatch(/^".+"$/)
    expect(res.headers.get('cache-control')).toBe(
      'public, max-age=86400, stale-while-revalidate=604800',
    )
    expect((await res.arrayBuffer()).byteLength).toBe(0)
  })
})

describe('file', () => {
  it('avisa que o /file será descontinuado em toda resposta', async () => {
    for (const path of ['/file/covers/1992.jpg', '/file/covers/nao-existe.jpg', '/file']) {
      expect((await call(path)).headers.get('deprecation'), path).toBe('true')
    }
    expect((await call('/json_db/music_1')).headers.get('deprecation')).toBeNull()
  })

  it('serve nome com reticências, mas não deixa subir de diretório', async () => {
    await env.FILES.put('musics/pt/A/Mundo...Paz.opus', new Uint8Array(7))
    const res = await call(`/file/musics/pt/A/${encodeURIComponent('Mundo...Paz.opus')}`)
    expect(res.status).toBe(200)
    expect((await res.arrayBuffer()).byteLength).toBe(7)

    expect((await call('/file/musics/pt/A/%2E%2E/B.opus')).status).toBe(404)
    expect((await call('/file/musics/%2E%2E/%2E%2E/json_db/config.json')).status).toBe(404)
  })

  it('resolve .bmp para a capa .jpg convertida', async () => {
    const res = await call('/file/covers/1992.bmp')
    expect(res.status).toBe(200)
    expect(res.headers.get('content-type')).toContain('image/jpeg')
  })

  // Quem pede .mp3 é quem só toca MP3: recebe o original, nunca o Opus.
  it('redireciona MP3 existente para files e nunca troca um MP3 ausente por Opus', async () => {
    const missing = await call('/file/musics/pt/A/B.mp3')
    expect(missing.status).toBe(404)
    expect(missing.headers.get('location')).toBeNull()
    expect(missing.headers.get('cache-control')).toBe('no-store')

    await env.FILES.put('musics/pt/A/B.mp3', new Uint8Array(150))
    const redirect = await call('/file/musics/pt/A/B.mp3')
    expect(redirect.status).toBe(302)
    expect(redirect.headers.get('location')).toBe('https://files.louvorja.com.br/musics/pt/A/B.mp3')
    expect(redirect.headers.get('access-control-allow-origin')).toBe('*')

    await env.FILES.put('musics/pt/Hinário/Santo, Santo.mp3', new Uint8Array(80))
    const accented = await call(
      `/file/musics/pt/${encodeURIComponent('Hinário')}/Santo%2C%20Santo.mp3`,
    )
    expect(accented.headers.get('location')).toBe(
      'https://files.louvorja.com.br/musics/pt/Hin%C3%A1rio/Santo%2C%20Santo.mp3',
    )

    const kept = await call('/file/musics/pt/A/So%20Mp3.mp3')
    expect(kept.status).toBe(302)
    expect(kept.headers.get('location')).toBe(
      'https://files.louvorja.com.br/musics/pt/A/So%20Mp3.mp3',
    )
    expect((await call('/file/musics/pt/nao-existe.mp3')).status).toBe(404)
    expect((await call('/file/covers/nao-existe.jpg')).status).toBe(404)
  })

  it('redireciona adicionais existentes em GET e HEAD com cache de uma hora', async () => {
    for (const key of [
      'bass.dll',
      'config/fontes/din-condensed-bold.ttf',
      'config/server/index.html',
    ]) {
      await env.FILES.put(`system/${key}`, new Uint8Array(5))
      for (const method of ['GET', 'HEAD']) {
        const response = await call(`/file/system/${key}`, { method })
        expect(response.status).toBe(302)
        expect(response.headers.get('location')).toBe(`https://files.louvorja.com.br/system/${key}`)
        expect(response.headers.get('cache-control')).toBe('public, max-age=3600')
        expect(response.headers.get('access-control-allow-origin')).toBe('*')
        expect((await response.arrayBuffer()).byteLength).toBe(0)
      }
    }
    const missing = await call('/file/system/nao-existe.dll')
    expect(missing.status).toBe(404)
    expect(missing.headers.get('location')).toBeNull()
    expect(missing.headers.get('cache-control')).toBe('no-store')
  })

  it('barra caminhos inválidos de adicionais antes de criar um redirect', async () => {
    for (const path of [
      'system/%2e%2e%2fmeta/params.json',
      'system/config/%2e%2e%5c%2e%2e%5cmeta/params.json',
      'system//bass.dll',
      'system/config/%00bass.dll',
      'system/config/%0abass.dll',
      'system/%zz',
      'system/',
    ]) {
      const response = await call(`/file/${path}`)
      expect(response.status).toBe(404)
      expect(response.headers.get('location')).toBeNull()
    }
  })

  it('entrega o .opus do acervo pelo nome exato', async () => {
    const res = await call('/file/musics/pt/A/B.opus')
    expect(res.status).toBe(200)
    expect(res.headers.get('content-type')).toContain('audio/ogg')
  })

  it('resolve .opus para o .mp3 quando a faixa não foi convertida', async () => {
    const res = await call('/file/musics/pt/A/So Mp3.opus')
    expect(res.status).toBe(200)
    expect(res.headers.get('content-type')).toContain('audio/mpeg')
  })

  it('prefere o .opus quando os dois existem', async () => {
    const res = await call('/file/musics/pt/A/B.opus')
    expect(res.status).toBe(200)
    expect(res.headers.get('content-type')).toContain('audio/ogg')
  })

  it('responde 206 a Range', async () => {
    const res = await call('/file/musics/pt/A/B.opus', { headers: { Range: 'bytes=0-9' } })
    expect(res.status).toBe(206)
    expect(res.headers.get('content-range')).toBe('bytes 0-9/100')
  })

  it('não escapa do prefixo do bucket', async () => {
    expect((await call('/file/../meta/params.json')).status).toBe(404)
  })

  it('não serve os prefixos internos do bucket', async () => {
    expect((await call('/file/json_db/config.json')).status).toBe(404)
    expect((await call('/file/meta/params.json')).status).toBe(404)
  })

  it('nomeia arquivo acentuado sem quebrar o header', async () => {
    await env.FILES.put('musics/pt/A/Não Só.opus', new Uint8Array(10))
    const res = await call('/file/musics/pt/A/N%C3%A3o%20S%C3%B3.opus')
    expect(res.status).toBe(200)
    expect(res.headers.get('content-disposition')).toContain("filename*=UTF-8''")
  })

  it('HEAD na raiz responde para o teste de conexão do desktop', async () => {
    expect((await call('/file', { method: 'HEAD' })).status).toBe(200)
  })
})

describe('params', () => {
  it('remove configurações antigas do snapshot no JSON e no INI', async () => {
    await env.FILES.put('meta/params.json', JSON.stringify({
      db_version: 185, conn_ftp: 'https://api.louvorja.com.br/ftp', FTP_HOST: 'old',
    }))
    try {
      expect(await (await call('/params')).json()).toEqual({ db_version: 185 })
      expect(await (await call('/params?type=env')).text()).toBe('db_version=185\r\n')
    } finally {
      await env.FILES.put('meta/params.json', JSON.stringify({ db_version: '185', help: 'x' }))
    }
  })

  it('não atende os endpoints do transporte descontinuado', async () => {
    for (const path of ['/ftp', '/pt/ftp', '/es/ftp']) {
      expect((await call(path)).status).toBe(404)
    }
  })

  it('devolve JSON por padrão', async () => {
    const res = await call('/params')
    expect(await res.json()).toEqual({
      db_version: '185',
      help: 'x',
    })
    expect(res.headers.get('cache-control')).toBe(
      'public, max-age=3600, stale-while-revalidate=86400',
    )
  })

  it('devolve INI com CRLF, como o parser Delphi espera', async () => {
    const res = await call('/params?type=env')
    expect(await res.text()).toBe(
      'db_version=185\r\nhelp=x\r\n',
    )
  })

  it('preserva o tipo dos valores no JSON', async () => {
    await env.FILES.put('meta/params.json', JSON.stringify({ db_version: 185, help: 'x' }))
    const body = (await (await call('/params')).json()) as Record<string, unknown>
    expect(body.db_version).toBe(185)
    expect(typeof body.db_version).toBe('number')
  })

  it('mostra na raiz e em /version a versão do banco por HTTPS, não o valor legado', async () => {
    await env.FILES.put(
      'meta/params.json',
      JSON.stringify({ db_version: 185, https_db_version: 186, help: 'x' }),
    )
    try {
      for (const path of ['/', '/version']) {
        const body = (await (await call(path)).json()) as Record<string, unknown>
        expect(body.db_version, path).toBe(186)
      }
    } finally {
      await env.FILES.put('meta/params.json', JSON.stringify({ db_version: '185', help: 'x' }))
    }
  })
})

describe('cabeçalhos', () => {
  it('libera CORS sem o par inválido origin=* + credentials', async () => {
    const res = await call('/json_db/config')
    expect(res.headers.get('access-control-allow-origin')).toBe('*')
    expect(res.headers.get('access-control-allow-credentials')).toBeNull()
  })
})

describe('REST', () => {
  it('pagina no envelope do Laravel, 15 por página', async () => {
    const res = await call('/pt/albums/category/doxology')
    const body = (await res.json()) as Record<string, unknown>
    expect(body.total).toBe(40)
    expect(body.per_page).toBe(15)
    expect(body.last_page).toBe(3)
    expect((body.data as unknown[]).length).toBe(15)
    expect(body.prev_page_url).toBeNull()
    expect(body.next_page_url).toContain('page=2')
  })

  it('respeita ?page e calcula from/to', async () => {
    const body = (await (await call('/pt/albums/category/doxology?page=3')).json()) as Record<
      string,
      unknown
    >
    expect(body.current_page).toBe(3)
    expect((body.data as unknown[]).length).toBe(10)
    expect(body.from).toBe(31)
    expect(body.to).toBe(40)
    expect(body.next_page_url).toBeNull()
  })

  it('trava página fora do intervalo na última', async () => {
    const body = (await (await call('/pt/albums/category/doxology?page=999')).json()) as Record<
      string,
      unknown
    >
    expect(body.current_page).toBe(3)
  })

  it('rejeita idioma desconhecido', async () => {
    expect((await call('/fr/collections/online')).status).toBe(404)
    expect((await call('/pt/inexistente')).status).toBe(404)
  })

  it('não deixa a rota genérica engolir /json_db e /file', async () => {
    expect((await call('/json_db/config')).status).toBe(200)
    expect((await call('/file/covers/1992.bmp')).status).toBe(200)
  })

  it('serve /db/bundle sem ser engolido pelo curinga /db/:key', async () => {
    await env.FILES.put('meta/bundle.zip', new Uint8Array([80, 75, 3, 4]))
    const res = await call('/db/bundle')
    expect(res.status).toBe(200)
    expect(res.headers.get('content-type')).toContain('zip')
  })

  it('sanitiza o id do player para não injetar HTML', async () => {
    const res = await call("/player?v=abc'></iframe><script>alert(1)</script>")
    const html = await res.text()
    expect(html).not.toContain('<script>')
    expect(html).toContain('https://www.youtube.com/embed/"')
  })

  it('aceita id de vídeo legítimo', async () => {
    const html = await (await call('/player?v=dQw4w9WgXcQ')).text()
    expect(html).toContain('embed/dQw4w9WgXcQ')
  })

  it('não deixa /:lang engolir as rotas de um segmento', async () => {
    expect((await call('/health')).status).toBe(200)
    expect((await call('/version')).status).toBe(200)
    expect((await call('/player')).status).toBe(200)
    expect((await call('/pt')).status).toBe(404)
    expect((await call('/xx')).status).toBe(404)
  })

  it('collections/online devolve JSON, não o dump SQL de /onlinevideos', async () => {
    const res = await call('/pt/collections/online')
    expect(res.headers.get('content-type')).toContain('application/json')
    expect(await res.json()).toEqual({ channels: [], playlists: [], videos: [] })

    // A rota antiga continua servindo o dump, que é outro formato.
    const dump = await call('/onlinevideos')
    expect(await dump.text()).toContain('ONL_CANAIS')
  })

  it('publica o openapi declarando as rotas', async () => {
    const spec = (await (await call('/openapi.json')).json()) as {
      info: { title: string }
      paths: Record<string, unknown>
    }
    expect(spec.info.title).toBe('LouvorJA API')
    expect(Object.keys(spec.paths)).toContain('/file/{path}')
    // O json_db tem uma entrada por chave, com o formato da resposta.
    for (const documented of [
      '/json_db',
      '/json_db/config',
      '/json_db/music_{id}',
      '/json_db/{lang}_musics',
      '/json_db/bible_{version}_{book}_{chapter}',
      '/json_db/{lang}_doxology_albums',
    ]) {
      expect(Object.keys(spec.paths), documented).toContain(documented)
    }
    // Rotas removidas não podem continuar documentadas.
    for (const removed of ['/{lang}/musics', '/{lang}/files', '/{lang}/hymnal', '/{lang}/config']) {
      expect(Object.keys(spec.paths), removed).not.toContain(removed)
    }
  })

  // Sem isso, quem quer a Bíblia inteira só enxerga o caminho capítulo a capítulo.
  it('mostra o pacote da Bíblia na página inicial, na documentação e no capítulo inexistente', async () => {
    const home = (await (await call('/')).json()) as {
      endpoints: string[]
      bulk: Record<string, string>
    }
    expect(home.endpoints).toContain('/db/bible-bundle')
    expect(home.bulk['/db/bible-bundle']).toContain('Bíblia inteira')

    const spec = (await (await call('/openapi.json')).json()) as { paths: Record<string, unknown> }
    expect(Object.keys(spec.paths)).toContain('/db/bible-bundle')

    const missing = await call('/json_db/bible_9_79_3')
    expect(missing.status).toBe(404)
    expect(missing.headers.get('cache-control')).toBe('no-store')
    expect(await missing.json()).toEqual({
      error: 'Arquivo não encontrado!',
      details: 'Para baixar a Bíblia inteira use /db/bible-bundle: um ZIP com todos os capítulos.',
      documentation: 'https://api.test/documentation',
    })
    // Fora da Bíblia o 404 continua com o mesmo `error`, e a dica é outra.
    const other = (await (await call('/json_db/nao_existe')).json()) as Record<string, string>
    expect(other.error).toBe('Arquivo não encontrado!')
    expect(other.details).toContain('/json_db')
  })

  it('health não entra em cache', async () => {
    const res = await call('/health')
    expect(res.headers.get('cache-control')).toBe('no-store')
  })

  // Cada resposta sem cache é uma requisição a mais na cota diária do plano
  // gratuito. Só o que depende de quem pede ou do instante fica sem cache.
  it('deixa o catálogo em cache no cliente e na borda', async () => {
    const cacheOf = async (path: string) => (await call(path)).headers.get('cache-control')
    expect(await cacheOf('/json_db/music_1')).toBe('private, max-age=86400')
    // A versão do banco é a exceção: quem pergunta "há banco novo?" não pode
    // receber a resposta de ontem, com ou sem a data na URL.
    expect(await cacheOf('/json_db/config')).toBe('private, no-cache')
    expect(await cacheOf('/json_db/config?20261011')).toBe('private, no-cache')
    expect(await cacheOf('/db/bible-bundle')).toBe(
      'public, max-age=86400, stale-while-revalidate=604800',
    )
    expect(await cacheOf('/db/config')).toBe('private, max-age=3600')
    expect(await cacheOf('/pt/collections/online')).toBe(
      'public, max-age=3600, stale-while-revalidate=86400',
    )
    expect(await cacheOf('/onlinevideos')).toBe(
      'public, max-age=3600, stale-while-revalidate=86400',
    )
    expect(await cacheOf('/params')).toBe('public, max-age=3600, stale-while-revalidate=86400')
  })
})

describe('catálogo menor e manifestos compartilhados pelos domínios', () => {
  it('entrega os mesmos artefatos atuais mesmo quando existem artefatos legados', async () => {
    for (const variant of ['files', 'legacy']) {
      await env.FILES.put(`meta/catalog-bundle-${variant}.zip`, `catalog-${variant}`)
      await env.FILES.put(`meta/bundle-${variant}.zip`, `full-${variant}`)
      await env.FILES.put(`meta/manifest-${variant}.json`, JSON.stringify([{ hash: variant }]))
    }
    for (const host of ['api.louvorja.workers.dev', 'api.louvorja.com.br']) {
      for (const [path, expected] of [
        ['/db/catalog-bundle', 'catalog-files'],
        ['/db/bundle', 'full-files'],
      ]) {
        const response = await worker.fetch(
          new Request(`https://${host}${path}`),
          env,
          createExecutionContext(),
        )
        expect(new TextDecoder().decode(await response.arrayBuffer())).toBe(expected)
      }
      for (const path of ['/db/manifest', '/json_db']) {
        const response = await worker.fetch(
          new Request(`https://${host}${path}`),
          env,
          createExecutionContext(),
        )
        expect(await response.json()).toEqual([{ hash: 'files' }])
        expect(response.headers.get('cache-control')).toMatch(/^private/)
      }
    }
  })
})
