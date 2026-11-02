/**
 * Versão do LouvorJA Delphi anunciada em /params.
 *
 * O programa compara a versão dele com estas chaves e, se houver uma maior,
 * oferece o instalador. Quem informava o número era o painel antigo; agora ele
 * sai do último lançamento final do repositório do Delphi no GitHub, cujos
 * instaladores seguem o mesmo padrão de nome que as chaves usam.
 */
export type GithubRelease = {
  id?: number
  body?: string | null
  html_url?: string
  tag_name: string
  draft?: boolean
  prerelease?: boolean
  assets: Array<{ name: string; browser_download_url: string }>
}

const INSTALLER: Record<'pt' | 'es', RegExp> = {
  pt: /^LouvorJA_Instalador[\d.]+\.exe$/,
  es: /^LoorJA_Instalador[\d.]+\.exe$/,
}

/** "v26.10" -> [26, 10]. Devolve null para o que não é versão. */
export function parseVersion(value: unknown): number[] | null {
  const text = String(value ?? '').replace(/^v/i, '')
  if (!/^\d+(\.\d+)*$/.test(text)) return null
  return text.split('.').map(Number)
}

export function compareVersions(a: number[], b: number[]): number {
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const diff = (a[i] ?? 0) - (b[i] ?? 0)
    if (diff !== 0) return diff
  }
  return 0
}

function languageKeys(language: 'pt' | 'es', version: string, name: string, url: string) {
  const upper = language.toUpperCase()
  return {
    [`versao${upper}`]: `${version}.0.0`,
    [`instalador${upper}`]: `setup\\Output\\${name}`,
    [`${language}_version`]: version,
    [`${language}_version_software`]: version,
    [`${language}_setup_name`]: name,
    [`setup_name${upper}`]: name,
    [`${language}_download`]: url,
    [`download${upper}`]: url,
  }
}

/**
 * Chaves de /params a atualizar para anunciar `release`. Vazio quando não há o
 * que mudar: lançamento não final, sem instalador, ou versão que não passa da
 * que já está anunciada — a versão nunca volta para trás.
 */
export function delphiVersionParams(
  release: GithubRelease,
  current: Record<string, unknown>,
): Record<string, string> {
  if (release.draft || release.prerelease) return {}
  const next = parseVersion(release.tag_name)
  if (next === null) return {}
  const version = next.slice(0, 2).join('.')

  const updates: Record<string, string> = {}
  for (const language of ['pt', 'es'] as const) {
    const asset = release.assets.find((item) => INSTALLER[language].test(item.name))
    if (!asset) continue
    const announced = parseVersion(current[`${language}_version`])
    if (announced !== null && compareVersions(next, announced) <= 0) continue
    Object.assign(updates, languageKeys(language, version, asset.name, asset.browser_download_url))
    // As chaves sem idioma são as do português.
    if (language === 'pt') {
      Object.assign(updates, {
        versao: `${version}.0.0`,
        instalador: `setup\\Output\\${asset.name}`,
        download: asset.browser_download_url,
        setup_name: asset.name,
      })
    }
  }
  return updates
}
