import assert from 'node:assert/strict'
import test from 'node:test'
import { publicParams } from '../src/lib/public-params.ts'

test('remove dados do transporte descontinuado sem alterar versões ou demais parâmetros', () => {
  const original = {
    conn_ftp: 'https://api.louvorja.com.br/ftp',
    FTP_HOST: 'old.example.com',
    download: 'ftp://old.example.com/file',
    old_endpoint: 'https://api.louvorja.com.br/pt/ftp?lang=pt',
    db_version: 185,
    https_db_version: 190,
    'classic-version': '27.0',
    'violin-version': '1.0.0',
    'piano-version': '1.34.0',
    'flute-version': '2.0.2',
    https_db_pt: 'https://files.louvorja.com.br/config/pt_database.db',
  }
  const filtered = publicParams(original)
  assert.deepEqual(Object.keys(filtered), ['db_version', 'https_db_version', 'classic-version', 'violin-version', 'piano-version', 'flute-version', 'https_db_pt'])
  assert.equal(filtered.db_version, 185)
  assert.ok(original.conn_ftp)
})
