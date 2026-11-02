import assert from 'node:assert/strict'
import test from 'node:test'
import { notifyReleases, releaseMessage, messageParts } from '../scripts/lib/release-notifications.ts'
import { parseAppReleases } from '../scripts/lib/app-releases.ts'
const app = { id: 'flute', name: 'LouvorJA Flute', repository: 'louvorja/flute-app', versionKey: 'flute_version', notifyRelease: true }
const item = { app, release: { id: 1, tag_name: 'v1.0.5', body: '- ajuste abc', html_url: 'https://github.com/release', assets: [] } }
function setup() {
  let data: Uint8Array | null = null
  let writes = 0
  const storage = { get: async () => data, put: async (_k: string, body: string) => { data = new TextEncoder().encode(body); writes++ } }
  return { storage, writes: () => writes }
}
const config = { token: 'fake', chatId: '-100' }
test('carga inicial envia e grava; repetição e regressão não enviam', async () => {
  const s = setup(); let calls = 0
  const request: typeof fetch = async () => { calls++; return Response.json({ ok: true }) }
  await notifyReleases([item], s.storage, config, true, request)
  await notifyReleases([item], s.storage, config, true, request)
  await notifyReleases([{ ...item, release: { ...item.release, tag_name: 'v1.0.4' } }], s.storage, config, true, request)
  assert.equal(calls, 1); assert.equal(s.writes(), 1)
  await notifyReleases([{ ...item, release: { ...item.release, tag_name: 'v1.0.6' } }], s.storage, config, true, request)
  assert.equal(calls, 2)
})
test('falha não grava, permite outros apps e retenta', async () => {
  const s = setup(); let calls = 0
  const request: typeof fetch = async () => { calls++; return Response.json({ ok: calls !== 1 }) }
  const other = { ...item, app: { ...app, id: 'piano' } }
  const report = await notifyReleases([item, other], s.storage, config, true, request)
  assert.equal(report.errors.length, 1); assert.equal(s.writes(), 1)
  await notifyReleases([item, other], s.storage, config, true, request)
  assert.equal(calls, 3); assert.equal(s.writes(), 2)
})
test('simulação, desativado, sem release e prerelease não enviam nem gravam', async () => {
  const s = setup(); const request: typeof fetch = async () => { throw new Error('não deve chamar') }
  assert.deepEqual((await notifyReleases([item], s.storage, config, false, request)).planned, ['flute'])
  await notifyReleases([item], s.storage, {}, true, request)
  await notifyReleases([{ app, noRelease: true }, { ...item, release: { ...item.release, prerelease: true } }, { ...item, app: { ...app, notifyRelease: false } }], s.storage, config, true, request)
  assert.equal(s.writes(), 0)
})
test('template usa link próprio ou fallback e omite novidades vazias', () => {
  assert.match(releaseMessage(item), /LouvorJA Flute \| Nova versão disponível/)
  assert.ok(releaseMessage(item).includes(item.release.body))
  assert.ok(releaseMessage({ ...item, app: { ...app, downloadUrl: 'https://site/download' } }).endsWith('https://site/download'))
  assert.ok(releaseMessage(item).endsWith(item.release.html_url))
  assert.ok(!releaseMessage({ ...item, release: { ...item.release, body: '' } }).includes('Novidades desta versão'))
  const text = '😀'.repeat(8000)
  assert.equal(messageParts(text).join(''), text)
  assert.ok(messageParts(text).every(p => p.length <= 3500))
})
test('tópico opcional e resposta HTTP são verificados', async () => {
  for (const threadId of [undefined, '123']) {
    const s = setup()
    await notifyReleases([item], s.storage, { ...config, threadId }, true, async (_url, init) => {
      assert.equal(JSON.parse(String(init!.body)).message_thread_id, threadId ? 123 : undefined)
      return Response.json({ ok: true })
    })
  }
  const s = setup()
  const report = await notifyReleases([item], s.storage, config, true, async () => Response.json({ ok: false }, { status: 429 }))
  assert.equal(report.errors.length, 1); assert.equal(s.writes(), 0)
  await assert.rejects(notifyReleases([item], s.storage, { ...config, threadId: 'abc' }, true))
})
test('configuração valida metadados e URL; armazenamento inválido não dispara carga inicial', async () => {
  assert.equal(parseAppReleases({ apps: [{ ...app, downloadUrl: 'https://site/download' }] })[0]!.name, app.name)
  assert.throws(() => parseAppReleases({ apps: [{ ...app, downloadUrl: 'javascript:alert(1)' }] }))
  await assert.rejects(notifyReleases([item], { get: async () => new TextEncoder().encode('broken'), put: async () => {} }, config, true))
})


import { telegramMarkdown, telegramHtmlParts } from '../scripts/lib/telegram-markdown.ts'
test('formata títulos, negrito, listas, links, itálico e código sem aceitar HTML bruto', () => {
  assert.equal(telegramMarkdown('### 🎨 Melhorias\n\n- **Pesquisa:** ajuste & <script>\n[Site](https://site.com/?a=1&b=2)\n*Itálico* e ~~removido~~'),
    '<b>🎨 Melhorias</b>\n\n• <b>Pesquisa:</b> ajuste &amp; &lt;script&gt;\n<a href="https://site.com/?a=1&amp;b=2">Site</a>\n<i>Itálico</i> e <s>removido</s>')
  assert.equal(telegramMarkdown('\x60a < b\x60'), '<code>a &lt; b</code>')
  assert.ok(!telegramMarkdown('[ruim](javascript:alert)').includes('<a'))
  assert.equal(telegramMarkdown('\x60\x60\x60ts\nconst a = "<b>"\n\x60\x60\x60'), '<pre>\nconst a = &quot;&lt;b&gt;&quot;\n</pre>')
})
test('divide conteúdo longo preservando texto, entidades, emojis e tags balanceadas', () => {
  const html = telegramMarkdown('**' + '😀 & teste '.repeat(1000) + '**')
  const parts = telegramHtmlParts(html)
  const visible = (s: string) => s.replace(/<[^>]*>/g, '').replace(/&amp;/g, '&')
  assert.equal(parts.map(visible).join(''), visible(html))
  assert.ok(parts.length > 1)
  for (const part of parts) {
    assert.ok(visible(part).length <= 3500)
    assert.ok(part.startsWith('<b>') && part.endsWith('</b>'))
  }
})
test('envio real do template usa HTML convertido com rótulos e texto da release', async () => {
  const s = setup()
  await notifyReleases([{ ...item, release: { ...item.release, body: '### Melhorias\n- **Pesquisa:** corrigida' } }], s.storage, config, true, async (_url, init) => {
    const payload = JSON.parse(String(init!.body))
    assert.equal(payload.parse_mode, 'HTML')
    assert.match(payload.text, /<b>🎉 LouvorJA Flute/)
    assert.ok(payload.text.includes('<b>Melhorias</b>\n• <b>Pesquisa:</b> corrigida'))
    assert.ok(payload.text.includes('<b>🔗 Baixe aqui:</b>'))
    return Response.json({ ok: true })
  })
})
