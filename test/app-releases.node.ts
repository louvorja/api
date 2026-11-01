import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { fetchAppReleases, parseAppReleases, publishReleaseVersions, releaseUpdates } from '../scripts/lib/app-releases.ts'
import { delphiVersionParams, type GithubRelease } from '../scripts/lib/delphi-version.ts'

const apps = parseAppReleases(JSON.parse(readFileSync(new URL('../config/app-releases.json', import.meta.url), 'utf8')))
const classic = apps.find(app => app.id === 'classic')!
const flute = apps.find(app => app.id === 'flute')!
const release = (tag_name: string): GithubRelease => ({ tag_name, assets: [
  { name: 'LouvorJA_Instalador27.1.exe', browser_download_url: 'https://example.com/pt.exe' },
  { name: 'LoorJA_Instalador27.1.exe', browser_download_url: 'https://example.com/es.exe' },
] })

test('configura os quatro repositórios e aceita adições; rejeita duplicatas e colisões legadas', () => {
  assert.deepEqual(apps.map(app => app.repository), ['louvorja/desktop', 'louvorja/violin-app', 'Piano-Louvor-JA/app', 'louvorja/flute-app'])
  assert.equal(parseAppReleases({ apps: [...apps, { id: 'new', repository: 'org/new', versionKey: 'new-version' }] }).length, 5)
  assert.throws(() => parseAppReleases({ apps: [...apps, classic] }))
  assert.throws(() => parseAppReleases({ apps: [...apps, { ...classic, id: 'other' }] }))
  assert.throws(() => parseAppReleases({ apps: [{ ...classic, versionKey: 'db_version' }] }))
  assert.throws(() => parseAppReleases({ apps: [{ ...classic, repository: 'https://bad' }] }))
})

test('preserva aliases legados do Classic e versão completa nas chaves novas', () => {
  const latest = release('v27.1.3')
  const legacy = delphiVersionParams(latest, {})
  const changes = releaseUpdates([{ app: classic, release: latest }], {})
  assert.deepEqual(changes, { 'classic-version': '27.1.3', ...legacy })
  assert.equal(changes.versaoPT, '27.1.0.0')
  assert.equal(releaseUpdates([{ app: flute, release: { tag_name: 'v1.10.2', assets: [] } }], { 'flute-version': '1.9.9' })['flute-version'], '1.10.2')
})

test('não regride, ignora versões iguais, inválidas e pré-lançamentos; não exige instalador para chave nova', () => {
  for (const tag of ['v1.9', 'v1.10.0', 'banana']) {
    assert.deepEqual(releaseUpdates([{ app: flute, release: release(tag) }], { 'flute-version': '1.10' }), {})
  }
  for (const extra of [{ prerelease: true }, { draft: true }]) {
    assert.deepEqual(releaseUpdates([{ app: flute, release: { ...release('v2.0'), ...extra } }], {}), {})
  }
  assert.deepEqual(releaseUpdates([{ app: classic, release: { tag_name: 'v28.0', assets: [] } }], {}), { 'classic-version': '28.0' })
})

test('isola falhas HTTP, rede e payload inválido de outros aplicativos', async () => {
  const request: typeof fetch = async input => {
    const url = String(input)
    if (url.includes('desktop')) return new Response(JSON.stringify(release('v27.1')), { status: 200 })
    if (url.includes('violin')) return new Response('{}', { status: 404 })
    if (url.includes('Piano')) throw new Error('offline')
    return new Response(JSON.stringify({ tag_name: 'v2.0', assets: [null] }))
  }
  const results = await fetchAppReleases(apps, request)
  assert.equal(results.filter(item => item.error).length, 3)
  assert.ok(releaseUpdates(results, {})['classic-version'])
  assert.equal(releaseUpdates(results, {})['flute-version'], undefined)
})

test('publica uma vez sobre o JSON relido, preserva campos e evita regressão concorrente', async () => {
  let current: Record<string, unknown> = { db_version: 185, https_db_version: 190, custom: 'preserve', 'flute-version': '2.1' }
  let writes = 0
  const storage = {
    get: async () => new TextEncoder().encode(JSON.stringify(current)),
    put: async (_key: string, body: string) => { writes++; current = JSON.parse(body) },
  }
  await publishReleaseVersions([{ app: flute, release: release('v2.0') }], storage)
  assert.equal(writes, 0)
  await publishReleaseVersions([{ app: classic, release: release('v27.1') }, { app: flute, error: 'offline' }], storage)
  assert.equal(writes, 1)
  assert.equal(current.custom, 'preserve')
  assert.equal(current.db_version, 185)
  assert.equal(current.https_db_version, 190)
  assert.equal(current['flute-version'], '2.1')
  await assert.rejects(publishReleaseVersions([], { ...storage, get: async () => null }))
})
