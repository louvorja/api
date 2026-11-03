import { env } from 'cloudflare:test'
import { describe, expect, it, vi } from 'vitest'
import worker from '../src/index.ts'
import { isScannerPath } from '../src/lib/scanner-paths.ts'
import type { Bindings } from '../src/types.ts'

describe('bloqueio dos caminhos sondados por scanners', () => {
  it('reconhece credenciais, arquivos internos e sondagens observadas', () => {
    for (const path of [
      '/.env',
      '/.env2',
      '/.env~',
      '/.env_secret',
      '/.git/config',
      '/.svn',
      '/a/.ssh/id_rsa',
      '/.aws/credentials',
      '/.dev.vars.production',
      '/.next/server/app/page.js',
      '/secrets.json',
      '/service-account.json',
      '/gcp-credentials.json',
      '/env.bak',
      '/docker-compose.production.yml',
      '/phpinfo.php',
      '/mail/phpinfo.php',
      '/admin/phpinfo.php',
      '/admin/_payload.json',
      '/api/proc/self/environ',
      '/api/fs/read',
      '/api/system/fileView',
      '/api/console/api_server',
      '/userfiles/x',
      '/graphql',
      '/wp-admin/install.php',
      '/xmlrpc.php',
    ])
      expect(isScannerPath(path), path).toBe(true)
  })

  it('bloqueia maiúsculas e escapes observados, inclusive bytes inválidos para UTF-8', () => {
    for (const path of [
      '/SECRETS.JSON',
      '/admin%2F.env',
      '/api%2f.env',
      '/%73ecrets.json',
      '/api/uploads/%2e%2e%2f%2e%2e%2f.env',
      '/.env%3F.css',
      '/%2eenv%ff',
    ])
      expect(isScannerPath(path), path).toBe(true)
    expect(isScannerPath('/%invalido')).toBe(false)
  })

  it('preserva rotas públicas, administração, mídias e caminhos desconhecidos comuns', () => {
    for (const path of [
      '/',
      '/params',
      '/version_log',
      '/version',
      '/metadata',
      '/onlinevideos',
      '/health',
      '/openapi.json',
      '/documentation',
      '/json_db/config',
      '/json_db/bible_1_1_1',
      '/db/bible-bundle',
      '/db/catalog-bundle',
      '/file/musics/pt/Album/phpinfo.mp3',
      '/pt/albums/category/doxology',
      '/pt/collections/online',
      '/auth/login',
      '/auth/me',
      '/admin',
      '/admin/',
      '/admin/users',
      '/admin/files',
      '/tasks',
      '/robots.txt',
      '/sitemap.xml',
      '/.well-known/security.txt',
      '/nao-existe',
    ])
      expect(isScannerPath(path), path).toBe(false)
  })

  it('nega antes dos bindings, para qualquer IP e método, nos dois hosts', async () => {
    const storage = vi.fn(() => {
      throw new Error('Scanner não deve consultar armazenamento')
    })
    const limit = vi.fn(() => {
      throw new Error('Scanner não deve consumir o limitador')
    })
    const bindings = {
      ...env,
      FILES: { get: storage, head: storage },
      DB: { prepare: storage },
      RATE_LIMIT_GENERAL: { limit },
    } as unknown as Bindings
    for (const host of ['api.louvorja.com.br', 'api.louvorja.workers.dev']) {
      for (const method of ['GET', 'HEAD', 'POST', 'OPTIONS']) {
        for (const ip of ['192.0.2.1', '192.0.2.2']) {
          const response = await worker.request(
            `https://${host}/api%2F.env`,
            {
              method,
              headers: { 'CF-Connecting-IP': ip },
            },
            bindings,
          )
          expect(response.status).toBe(403)
          expect(response.headers.get('cache-control')).toBe('no-store')
          if (method === 'HEAD') expect(await response.text()).toBe('')
        }
      }
    }
    expect(storage).not.toHaveBeenCalled()
    expect(limit).not.toHaveBeenCalled()
  })

  it('mantém o acesso legítimo do mesmo IP após uma sondagem e ignora a query no filtro', async () => {
    const headers = { 'CF-Connecting-IP': '192.0.2.3' }
    await env.FILES.put('meta/params.json', JSON.stringify({ db_version: '185' }))
    expect((await worker.request('https://api.test/secrets.json', { headers }, env)).status).toBe(
      403,
    )
    for (const path of ['/params?hint=/.env', '/openapi.json', '/documentation']) {
      expect((await worker.request(`https://api.test${path}`, { headers }, env)).status, path).toBe(
        200,
      )
    }
    expect((await worker.request('https://api.test/admin/users', { headers }, env)).status).toBe(
      401,
    )
    expect(
      (await worker.request('https://api.test/params', { method: 'OPTIONS', headers }, env)).status,
    ).toBe(204)
  })
})
