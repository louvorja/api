import { publicParams } from '../../src/lib/public-params.ts'
import { FILES_URL } from '../../src/lib/media-urls.ts'

export const DELPHI_LANGUAGES = ['pt', 'es'] as const
export const DELPHI_PARAMS_KEY = 'meta/params.json'
export type DelphiLanguage = (typeof DELPHI_LANGUAGES)[number]

type DelphiStorage = Pick<typeof import('./r2.ts'), 'get' | 'put' | 'ORIGINALS_BUCKET'>

export const delphiDbKey = (language: string) => `config/${language}_database.db`
export const delphiDbUrl = (language: string) => `${FILES_URL}/${delphiDbKey(language)}`

export function delphiHttpsParams() {
  return {
    https_files: FILES_URL,
    https_db_pt: delphiDbUrl('pt'),
    https_db_es: delphiDbUrl('es'),
  }
}

export async function previousDelphiDatabase(language: DelphiLanguage, storage: DelphiStorage) {
  const key = delphiDbKey(language)
  const body = (await storage.get(key)) ?? (await storage.get(key, storage.ORIGINALS_BUCKET))
  if (body === null) {
    throw new Error(
      `${key} não existe em files nem em ${storage.ORIGINALS_BUCKET}: sem o banco anterior ` +
        'não há de onde preservar a Bíblia',
    )
  }
  return body
}

/** Só anuncia a versão depois de publicar ambos os bancos. */
export async function publishDelphiDatabases(
  databases: Record<DelphiLanguage, Uint8Array>,
  version: number,
  fallbackParams: Record<string, unknown>,
  storage: DelphiStorage,
  updateOriginals = true,
) {
  for (const language of DELPHI_LANGUAGES) {
    const key = delphiDbKey(language)
    await storage.put(key, databases[language], 'application/vnd.sqlite3')
    if (updateOriginals) {
      await storage.put(
        key,
        databases[language],
        'application/vnd.sqlite3',
        storage.ORIGINALS_BUCKET,
      )
    }
  }

  // Relê para preservar alterações de outros publicadores durante a geração.
  const freshBody = await storage.get(DELPHI_PARAMS_KEY)
  const fresh =
    freshBody === null
      ? fallbackParams
      : (JSON.parse(new TextDecoder().decode(freshBody)) as Record<string, unknown>)
  const params: Record<string, unknown> = {
    ...publicParams(fresh),
    ...delphiHttpsParams(),
    https_db_version: version,
  }
  delete params.https_system_files
  await storage.put(DELPHI_PARAMS_KEY, JSON.stringify(params), 'application/json')
}
