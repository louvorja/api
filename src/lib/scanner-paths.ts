import type { MiddlewareHandler } from 'hono'
import paths from '../../config/scanner-paths.json'
import type { App } from '../types.ts'

const exactPaths = new Set(paths.exact)

/** Mesma decodificação de bytes de url_decode(path) no WAF, sem ler a query. */
export function isScannerPath(path: string): boolean {
  const decoded = path
    .replace(/\+/g, ' ')
    .replace(/%([\da-f]{2})/gi, (_, hex: string) => String.fromCharCode(Number.parseInt(hex, 16)))
    .toLowerCase()
  return (
    exactPaths.has(decoded) ||
    paths.contains.some((part) => decoded.includes(part)) ||
    paths.endsWith.some((suffix) => decoded.endsWith(suffix))
  )
}

/** Cobre também workers.dev e previews, que não passam pelo WAF da zona. */
export const blockScannerPaths: MiddlewareHandler<App> = async (c, next) => {
  if (isScannerPath(c.req.path)) {
    c.header('Cache-Control', 'no-store')
    return c.json({ error: 'Forbidden' }, 403)
  }
  await next()
}
