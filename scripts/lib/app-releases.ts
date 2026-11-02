import { publicParams } from '../../src/lib/public-params.ts'
import { compareVersions, delphiVersionParams, type GithubRelease, parseVersion } from './delphi-version.ts'

export type AppRelease = { id: string; repository: string; versionKey: string }
type Params = Record<string, unknown>
export type ReleaseResult = { app: AppRelease; release?: GithubRelease; error?: string }

/** Chaves novas não podem sobrescrever configurações ou aliases legados. */
export function parseAppReleases(value: unknown): AppRelease[] {
  if (!value || typeof value !== 'object' || !('apps' in value) || !Array.isArray(value.apps)) {
    throw new Error('configuração deve conter apps: []')
  }
  const ids = new Set<string>()
  const keys = new Set<string>()
  return value.apps.map((item: unknown) => {
    if (!item || typeof item !== 'object') throw new Error('aplicativo inválido')
    const { id, repository, versionKey } = item as Record<string, unknown>
    if (typeof id !== 'string' || !/^[a-z][a-z0-9-]*$/.test(id) ||
        typeof repository !== 'string' || !/^[\w.-]+\/[\w.-]+$/.test(repository) ||
        typeof versionKey !== 'string' || !/^[a-z][a-z0-9_-]*[-_]version$/.test(versionKey) ||
        ['db_version', 'https_db_version', 'pt_version', 'es_version'].includes(String(versionKey))) {
      throw new Error('aplicativo exige id, repository (owner/repo) e versionKey (*_version ou *-version, sem sobrescrever chaves legadas) válidos')
    }
    if (ids.has(id) || keys.has(versionKey)) throw new Error(`id ou versionKey duplicado: ${id}`)
    ids.add(id)
    keys.add(versionKey)
    return { id, repository, versionKey }
  })
}

export function releaseUpdates(results: ReleaseResult[], current: Params): Record<string, string> {
  const updates: Record<string, string> = {}
  for (const { app, release } of results) {
    if (!release || release.draft || release.prerelease) continue
    const next = parseVersion(release.tag_name)
    if (!next) continue
    const previous = parseVersion(current[app.versionKey])
    if (!previous || compareVersions(next, previous) > 0) {
      updates[app.versionKey] = release.tag_name.replace(/^v/i, '')
    }
    // Mantém as regras e os nomes históricos do Delphi, inclusive os instaladores.
    if (app.id === 'classic') Object.assign(updates, delphiVersionParams(release, current))
  }
  return updates
}

export async function fetchAppReleases(
  apps: AppRelease[],
  request: typeof fetch = fetch,
  token?: string,
): Promise<ReleaseResult[]> {
  return Promise.all(apps.map(async (app): Promise<ReleaseResult> => {
    try {
      const response = await request(`https://api.github.com/repos/${app.repository}/releases/latest`, {
        headers: {
          Accept: 'application/vnd.github+json',
          'User-Agent': 'louvorja-api-workers',
          'X-GitHub-Api-Version': '2022-11-28',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        signal: AbortSignal.timeout(20_000),
      })
      if (!response.ok) throw new Error(`GitHub respondeu ${response.status}`)
      const release = await response.json() as GithubRelease
      if (typeof release.tag_name !== 'string' || !Array.isArray(release.assets) ||
          release.assets.some(asset => !asset || typeof asset.name !== 'string' || typeof asset.browser_download_url !== 'string') ||
          !parseVersion(release.tag_name) || release.draft || release.prerelease) {
        throw new Error('release estável inválido ou versão não numérica')
      }
      return { app, release }
    } catch (error) {
      return { app, error: error instanceof Error ? error.message : String(error) }
    }
  }))
}

type Storage = {
  get: (key: string) => Promise<Uint8Array | null>
  put: (key: string, body: string, type: string) => Promise<unknown>
}

/** Relê antes da única gravação para preservar campos alterados durante as consultas. */
export async function publishReleaseVersions(results: ReleaseResult[], storage: Storage): Promise<Params> {
  const key = 'meta/params.json'
  const body = await storage.get(key)
  if (body === null) throw new Error(`${key} não existe no bucket`)
  const stored = JSON.parse(new TextDecoder().decode(body)) as Params
  const current = publicParams(stored)
  const updates = releaseUpdates(results, current)
  if (Object.keys(stored).length !== Object.keys(current).length || Object.entries(updates).some(([name, value]) => current[name] !== value)) {
    await storage.put(key, JSON.stringify({ ...current, ...updates }), 'application/json')
  }
  return updates
}
