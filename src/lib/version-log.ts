export const DESKTOP_RELEASES_REPO = 'louvorja/desktop'

/** O último componente exibido pelo Delphi é a versão do catálogo. */
export function desktopSoftwareVersion(version: string): string | null {
  return /^\d+\.\d+(?:\.\d+)*$/.test(version) ? version.split('.').slice(0, 2).join('.') : null
}

export function versionLogKey(softwareVersion: string): string {
  return `meta/version_log/v${softwareVersion}.txt`
}
