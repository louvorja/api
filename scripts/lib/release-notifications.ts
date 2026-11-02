import { telegramMarkdown, telegramHtmlParts } from './telegram-markdown.ts'
import type { ReleaseResult } from './app-releases.ts'
import { compareVersions, parseVersion } from './delphi-version.ts'

type Storage = {
  get(key: string): Promise<Uint8Array | null>
  put(key: string, body: string, type: string): Promise<unknown>
}
type Config = { token?: string; chatId?: string; threadId?: string }
type Entry = { repository: string; version: string; releaseId?: number; sentAt: string }
const key = 'meta/release-notifications.json'

/** Compose Markdown; conversion to safe Telegram HTML happens before sending. */
export function releaseMessage(result: ReleaseResult): string {
  const { app, release } = result
  if (!release) throw new Error('release ausente')
  const body = release.body?.trim()
  const link = app.downloadUrl || release.html_url ||
    `https://github.com/${app.repository}/releases/tag/${encodeURIComponent(release.tag_name)}`
  return `# 🎉 ${app.name || app.id} | Nova versão disponível\n\n🏷 Versão ${release.tag_name.replace(/^v/i, '')}\n\n` +
    (body ? `## Novidades desta versão:\n\n${body}\n\n` : '') +
    `## 🔗 Baixe aqui:\n${link}`
}

/** Keep Unicode characters intact; Telegram allows at most 4096 characters. */
export function messageParts(text: string): string[] {
  const parts: string[] = []
  let part = ''
  for (const char of text) {
    if (part.length + char.length > 3500) { parts.push(part); part = '' }
    part += char
  }
  if (part) parts.push(part)
  return parts
}

export async function notifyReleases(
  results: ReleaseResult[], storage: Storage, config: Config, write = false,
  request: typeof fetch = fetch,
): Promise<{ planned: string[]; errors: string[] }> {
  const report = { planned: [] as string[], errors: [] as string[] }
  if (!config.token || !config.chatId) return report
  const thread = config.threadId?.trim()
  if (thread && (!/^\d+$/.test(thread) || !Number.isSafeInteger(Number(thread)) || Number(thread) <= 0)) {
    throw new Error('TELEGRAM_RELEASES_MESSAGE_THREAD_ID deve ser um inteiro positivo')
  }
  const raw = await storage.get(key)
  const state: Record<string, Entry> = raw ? JSON.parse(new TextDecoder().decode(raw)) : {}
  if (!state || typeof state !== 'object' || Array.isArray(state)) throw new Error('controle de anúncios inválido')
  for (const result of results) {
    const { app, release } = result
    if (!app.notifyRelease || !release || result.error || release.draft || release.prerelease) continue
    const next = parseVersion(release.tag_name)
    if (!next) continue
    const previous = Object.hasOwn(state, app.id) ? state[app.id] : undefined
    if (previous) {
      const version = parseVersion(previous.version)
      if (!version || typeof previous.repository !== 'string') throw new Error('registro de anúncio inválido')
      if (previous.repository === app.repository && compareVersions(next, version) <= 0) continue
    }
    report.planned.push(app.id)
    if (!write) {
      console.log(releaseMessage(result))
      continue
    }
    try {
      for (const text of telegramHtmlParts(telegramMarkdown(releaseMessage(result)))) {
        const response = await request(`https://api.telegram.org/bot${config.token}/sendMessage`, {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({
            chat_id: config.chatId,
            text,
            parse_mode: 'HTML',
            ...(thread ? { message_thread_id: Number(thread) } : {}),
          }),
          signal: AbortSignal.timeout(20_000),
        })
        const payload = await response.json() as { ok?: boolean }
        if (!response.ok || payload.ok !== true) throw new Error(`Telegram recusou envio (HTTP ${response.status})`)
      }
    } catch {
      // Never log fetch errors containing the bot token in the request URL.
      report.errors.push(`${app.id}: falha ao enviar; nova tentativa na próxima manutenção`)
      continue
    }
    state[app.id] = {
      repository: app.repository, version: release.tag_name,
      releaseId: release.id, sentAt: new Date().toISOString(),
    }
    // Stop on storage failure: more sends would have no durable confirmation.
    await storage.put(key, JSON.stringify(state), 'application/json')
  }
  return report
}
