import assert from 'node:assert/strict'
import test from 'node:test'
import { purgeParamsCache } from '../scripts/lib/params-cache.ts'

const env = {
  CLOUDFLARE_ZONE_ID: 'a'.repeat(32),
  CLOUDFLARE_CACHE_PURGE_TOKEN: 'test-token',
}

test('limpa somente o prefixo de params, incluindo todas as query strings', async () => {
  let calls = 0
  const request: typeof fetch = async (url, init) => {
    calls++
    assert.equal(
      url,
      `https://api.cloudflare.com/client/v4/zones/${env.CLOUDFLARE_ZONE_ID}/purge_cache`,
    )
    assert.equal(init?.method, 'POST')
    assert.equal(new Headers(init?.headers).get('Authorization'), 'Bearer test-token')
    assert.deepEqual(JSON.parse(String(init?.body)), { prefixes: ['api.louvorja.com.br/params'] })
    assert.ok(init?.signal)
    return Response.json({ success: true })
  }
  await purgeParamsCache(env, request)
  assert.equal(calls, 1)
})

test('configuração ausente ou inválida falha antes de acessar a rede', async () => {
  const request: typeof fetch = async () => {
    throw new Error('não deve chamar a rede')
  }
  await assert.rejects(purgeParamsCache({}, request), /CLOUDFLARE_ZONE_ID/)
  await assert.rejects(
    purgeParamsCache({ ...env, CLOUDFLARE_ZONE_ID: '../account' }, request),
    /CLOUDFLARE_ZONE_ID/,
  )
  await assert.rejects(
    purgeParamsCache({ ...env, CLOUDFLARE_CACHE_PURGE_TOKEN: '' }, request),
    /CLOUDFLARE_CACHE_PURGE_TOKEN/,
  )
})

test('HTTP de erro ou success false não é reportado como limpeza concluída', async () => {
  for (const status of [403, 429, 500]) {
    await assert.rejects(
      purgeParamsCache(env, async () => new Response('erro', { status })),
      new RegExp(`HTTP ${status}`),
    )
  }
  await assert.rejects(
    purgeParamsCache(env, async () => Response.json({ success: false })),
    /não confirmou/,
  )
  await assert.rejects(
    purgeParamsCache(env, async () => Response.json({})),
    /não confirmou/,
  )
  await assert.rejects(
    purgeParamsCache(env, async () => {
      throw new Error('timeout')
    }),
    /timeout/,
  )
})
