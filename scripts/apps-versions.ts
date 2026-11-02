/** Sem --write apenas consulta e mostra o plano; o R2 usado é o configurado no .env. */
import { readFileSync } from 'node:fs'
import { fetchAppReleases, parseAppReleases, publishReleaseVersions, releaseUpdates } from './lib/app-releases.ts'
import * as r2 from './lib/r2.ts'

const apps = parseAppReleases(JSON.parse(readFileSync(new URL('../config/app-releases.json', import.meta.url), 'utf8')))
const selected = process.argv.includes('--classic-only') ? apps.filter(app => app.id === 'classic') : apps
if (process.argv.includes('--classic-only') && selected.length === 0) throw new Error('classic não configurado')
// Preserva o override já aceito pelo comando delphi:version.
for (const app of selected) {
  if (app.id === 'classic' && process.env.DELPHI_RELEASES_REPO) app.repository = process.env.DELPHI_RELEASES_REPO
}
const results = await fetchAppReleases(selected, fetch, process.env.GITHUB_TOKEN)
for (const result of results) {
  if (result.error) console.error(`${result.app.id} (${result.app.repository}): ${result.error}; valor preservado.`)
  else if (result.noRelease) console.log(`${result.app.id}: sem release estável disponível; versão vazia.`)
  else console.log(`${result.app.id}: ${result.release!.tag_name}`)
}
const body = await r2.get('meta/params.json')
if (body === null) throw new Error('meta/params.json não existe no bucket')
const params = JSON.parse(new TextDecoder().decode(body)) as Record<string, unknown>
const updates = releaseUpdates(results, params)
const changed = Object.entries(updates).filter(([key, value]) => params[key] !== value)
for (const [key, value] of changed) console.log(`  ${key}: ${String(params[key])} → ${value}`)
if (process.argv.includes('--write')) {
  await publishReleaseVersions(results, r2)
  console.log('Publicação de versões concluída; demais parâmetros preservados.')
} else {
  console.log(changed.length ? 'Simulação: nada foi gravado (use --write).' : 'Nada a anunciar.')
}
// Atualiza os aplicativos disponíveis, mas torna falhas visíveis no workflow.
if (results.some(result => result.error)) process.exitCode = 1
