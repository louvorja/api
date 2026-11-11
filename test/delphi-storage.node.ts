import assert from 'node:assert/strict'
import test from 'node:test'
import {
  DELPHI_PARAMS_KEY,
  delphiHttpsParams,
  previousDelphiDatabase,
  publishDelphiDatabases,
} from '../scripts/lib/delphi-storage.ts'

const bytes = (value: string) => new TextEncoder().encode(value)
const databases = { pt: bytes('banco pt'), es: bytes('banco es') }

function storageFixture(initial: Record<string, Uint8Array> = {}, failWrite?: number) {
  const objects = new Map(Object.entries(initial))
  const reads: string[] = []
  const writes: string[] = []
  return {
    objects,
    reads,
    writes,
    ORIGINALS_BUCKET: 'files-original',
    async get(key: string, bucket = 'files') {
      reads.push(`${bucket}/${key}`)
      return objects.get(`${bucket}/${key}`) ?? null
    },
    async put(key: string, body: Uint8Array | string, _contentType: string, bucket = 'files') {
      writes.push(`${bucket}/${key}`)
      if (writes.length === failWrite) throw new Error('falha de escrita')
      objects.set(`${bucket}/${key}`, typeof body === 'string' ? bytes(body) : body)
    },
  }
}

test('prefere o banco canônico ao arquivo anterior', async () => {
  const storage = storageFixture({
    'files/config/pt_database.db': databases.pt,
    'files-original/config/pt_database.db': bytes('banco anterior'),
  })
  assert.deepEqual(await previousDelphiDatabase('pt', storage), databases.pt)
  assert.deepEqual(storage.reads, ['files/config/pt_database.db'])
})

test('usa a cópia anterior somente quando o banco canônico está ausente', async () => {
  const storage = storageFixture({ 'files-original/config/pt_database.db': databases.pt })
  assert.deepEqual(await previousDelphiDatabase('pt', storage), databases.pt)
  assert.deepEqual(storage.reads, [
    'files/config/pt_database.db',
    'files-original/config/pt_database.db',
  ])
  await assert.rejects(previousDelphiDatabase('es', storage), /preservar a Bíblia/)
})

test('não mascara falha de leitura como banco ausente', async () => {
  const storage = storageFixture()
  storage.get = async () => {
    throw new Error('GET -> 503')
  }
  await assert.rejects(previousDelphiDatabase('pt', storage), /GET -> 503/)
})

test('a troca de URLs não altera versões ou outros parâmetros', () => {
  assert.deepEqual(delphiHttpsParams(), {
    https_files: 'https://files.louvorja.com.br',
    https_db_pt: 'https://files.louvorja.com.br/config/pt_database.db',
    https_db_es: 'https://files.louvorja.com.br/config/es_database.db',
  })
})

test('publica os dois bancos e suas cópias antes de anunciar a versão HTTPS', async () => {
  const latest = { db_version: 185, version_number: 189, pt_version: '26.12', custom: 'novo' }
  const storage = storageFixture({ [`files/${DELPHI_PARAMS_KEY}`]: bytes(JSON.stringify(latest)) })
  await publishDelphiDatabases(databases, 189, { ...latest, custom: 'antigo' }, storage)
  assert.deepEqual(storage.writes, [
    'files/config/pt_database.db',
    'files-original/config/pt_database.db',
    'files/config/es_database.db',
    'files-original/config/es_database.db',
    `files/${DELPHI_PARAMS_KEY}`,
  ])
  for (const language of ['pt', 'es'] as const) {
    for (const bucket of ['files', 'files-original']) {
      assert.deepEqual(
        storage.objects.get(`${bucket}/config/${language}_database.db`),
        databases[language],
      )
    }
  }
  assert.deepEqual(
    JSON.parse(new TextDecoder().decode(storage.objects.get(`files/${DELPHI_PARAMS_KEY}`))),
    { ...latest, ...delphiHttpsParams(), db_version: 189, https_db_version: 189 },
  )
})

test('falha em qualquer banco impede anúncio da versão e mantém params anteriores', async () => {
  for (const failWrite of [1, 2, 3, 4]) {
    const previous = bytes(JSON.stringify({ https_db_version: 185, db_version: 185 }))
    const storage = storageFixture({ [`files/${DELPHI_PARAMS_KEY}`]: previous }, failWrite)
    await assert.rejects(publishDelphiDatabases(databases, 189, {}, storage), /falha de escrita/)
    assert.deepEqual(storage.objects.get(`files/${DELPHI_PARAMS_KEY}`), previous)
    assert.equal(storage.writes.includes(`files/${DELPHI_PARAMS_KEY}`), false)
  }
})

test('republica stored na versão 185, remove a base antiga e preserva originais e configurações', async () => {
  const old = bytes('banco MP3 versão 185')
  const latest = {
    db_version: 185,
    https_db_version: 185,
    https_system_files: 'https://files.louvorja.com.br/system',
    pt_version: '26.11',
    custom: 'atual',
  }
  const storage = storageFixture({
    'files-original/config/pt_database.db': old,
    'files-original/config/es_database.db': old,
    [`files/${DELPHI_PARAMS_KEY}`]: bytes(JSON.stringify(latest)),
  })
  await publishDelphiDatabases(databases, 185, { ...latest, custom: 'antigo' }, storage, false)
  assert.deepEqual(storage.writes, [
    'files/config/pt_database.db',
    'files/config/es_database.db',
    `files/${DELPHI_PARAMS_KEY}`,
  ])
  assert.deepEqual(storage.objects.get('files-original/config/pt_database.db'), old)
  assert.deepEqual(storage.objects.get('files-original/config/es_database.db'), old)
  assert.deepEqual(
    JSON.parse(new TextDecoder().decode(storage.objects.get(`files/${DELPHI_PARAMS_KEY}`))),
    {
      db_version: 185,
      https_db_version: 185,
      pt_version: '26.11',
      custom: 'atual',
      ...delphiHttpsParams(),
    },
  )
})

test('remove a base antiga também dos parâmetros de reserva sem alterar a entrada', async () => {
  const fallback = Object.freeze({
    db_version: 185,
    https_system_files: 'https://files.louvorja.com.br/system',
    custom: 'preservado',
  })
  const storage = storageFixture()
  await publishDelphiDatabases(databases, 185, fallback, storage, false)
  assert.deepEqual(
    JSON.parse(new TextDecoder().decode(storage.objects.get(`files/${DELPHI_PARAMS_KEY}`))),
    {
      db_version: 185,
      custom: 'preservado',
      ...delphiHttpsParams(),
      https_db_version: 185,
    },
  )
  assert.equal(fallback.https_system_files, 'https://files.louvorja.com.br/system')
})
