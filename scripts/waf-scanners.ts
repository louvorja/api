import { readFileSync, writeFileSync } from 'node:fs'
import { URL } from 'node:url'

// Fonte única para o WAF e o middleware do Worker. Este script não publica regras.
const paths = JSON.parse(
  readFileSync(new URL('../config/scanner-paths.json', import.meta.url), 'utf8'),
) as { contains: string[]; endsWith: string[]; exact: string[] }
const field = 'lower(url_decode(http.request.uri.path))'
const conditions = [
  ...paths.contains.map((part) => `${field} contains ${JSON.stringify(part)}`),
  ...paths.endsWith.map((suffix) => `ends_with(${field}, ${JSON.stringify(suffix)})`),
]
const expression = (patterns: string[], exact: string[]) => {
  const clauses = [...patterns]
  if (exact.length)
    clauses.push(`${field} in {${exact.map((path) => JSON.stringify(path)).join(' ')}}`)
  return `(http.host eq "api.louvorja.com.br" and (${clauses.join(' or ')}))`
}

// Cloudflare limita cada expressão a 4096 caracteres; preserva o ID da regra existente.
const expressions: string[] = []
let patterns = conditions
let exact: string[] = []
for (const path of paths.exact) {
  if (expression(patterns, [...exact, path]).length > 4096) {
    expressions.push(expression(patterns, exact))
    patterns = []
    exact = []
  }
  exact.push(path)
}
expressions.push(expression(patterns, exact))
const rules = expressions.map((expression, index) => ({
  ...(index === 0 ? { id: '027cf2a60f9d4c949f89cbf9b14964d7' } : {}),
  ref: index === 0 ? '027cf2a60f9d4c949f89cbf9b14964d7' : `api-scanner-paths-${index + 1}`,
  description: `API - bloquear caminhos de scanner${index === 0 ? '' : ` (${index + 1})`}`,
  action: 'block',
  enabled: true,
  expression,
}))
const output = `${JSON.stringify({ rules }, null, 2)}\n`
const destination = new URL('../config/waf-api-scanners.json', import.meta.url)
if (process.argv.includes('--check')) {
  if (readFileSync(destination, 'utf8') !== output) {
    throw new Error('WAF desatualizado: execute npm run waf:generate')
  }
} else {
  writeFileSync(destination, output)
}
console.log(
  `${rules.length} regras WAF; expressões: ${expressions.map((item) => item.length).join(', ')} caracteres`,
)
