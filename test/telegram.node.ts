import assert from 'node:assert/strict'
import test from 'node:test'
import { notifyTelegram } from '../src/lib/admin/audit.ts'
import type { Bindings } from '../src/types.ts'
import type { User } from '../src/lib/auth/users.ts'

const user = { id: 1, name: 'Teste' } as User
const base = { TELEGRAM_BOT_TOKEN: 'test-token', TELEGRAM_CHAT_ID: '-100123' }
const send = (config: Record<string, string | undefined>) =>
  notifyTelegram(config as Bindings, 'musics', 'update', { name: 'Antes' }, { name: 'Depois' }, user)

test('envio geral omite o tópico ausente, vazio ou somente espaços', async t => {
  const bodies: Record<string, unknown>[] = []
  t.mock.method(globalThis, 'fetch', async (_url: unknown, init: RequestInit) => {
    bodies.push(JSON.parse(String(init.body)))
    return new Response('{}')
  })
  for (const thread of [undefined, '', '  ']) await send({ ...base, TELEGRAM_MESSAGE_THREAD_ID: thread })
  assert.equal(bodies.length, 3)
  for (const body of bodies) {
    assert.equal(Object.hasOwn(body, 'message_thread_id'), false)
    assert.equal(body.chat_id, base.TELEGRAM_CHAT_ID)
    assert.equal(body.parse_mode, 'HTML')
    assert.match(String(body.text), /Registro Alterado/)
  }
})

test('envio para tópico inclui seu ID como número', async t => {
  const mock = t.mock.method(globalThis, 'fetch', async (_url: unknown, init: RequestInit) => {
    const body = JSON.parse(String(init.body))
    assert.equal(body.message_thread_id, 123)
    assert.equal(body.chat_id, base.TELEGRAM_CHAT_ID)
    return new Response('{}')
  })
  await send({ ...base, TELEGRAM_MESSAGE_THREAD_ID: ' 123 ' })
  assert.equal(mock.mock.callCount(), 1)
})

test('sem token ou grupo não envia, mesmo com tópico', async t => {
  const mock = t.mock.method(globalThis, 'fetch', async () => new Response('{}'))
  for (const key of ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID']) {
    await send({ ...base, TELEGRAM_MESSAGE_THREAD_ID: '123', [key]: undefined })
  }
  assert.equal(mock.mock.callCount(), 0)
})

test('tópico inválido não desvia a mensagem para envio geral', async t => {
  const mock = t.mock.method(globalThis, 'fetch', async () => new Response('{}'))
  const log = t.mock.method(console, 'error', () => {})
  for (const thread of ['0', '-1', '1.5', 'abc', '1e3', '9007199254740992']) {
    await send({ ...base, TELEGRAM_MESSAGE_THREAD_ID: thread })
  }
  assert.equal(mock.mock.callCount(), 0)
  assert.equal(log.mock.callCount(), 6)
})
