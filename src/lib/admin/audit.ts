import type { Bindings } from '../../types.ts'
import type { User } from '../auth/users.ts'
import { sqlNow } from '../sql.ts'

type Values = Record<string, unknown>
export type Action = 'insert' | 'update' | 'delete'

/** Comando que grava a linha de auditoria, para entrar no mesmo lote da alteração. */
export function logStatement(
  db: D1Database,
  table: string,
  action: Action,
  oldValues: Values,
  newValues: Values,
  user: User,
): D1PreparedStatement {
  const now = sqlNow()
  return db
    .prepare(
      'INSERT INTO logs ("table", action, old_values, new_values, user_id, user, created_at, updated_at) ' +
        'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    )
    .bind(
      table,
      action,
      JSON.stringify(action === 'insert' ? [] : oldValues),
      JSON.stringify(action === 'delete' ? [] : newValues),
      user.id,
      JSON.stringify(user),
      now,
      now,
    )
}

const escape = (value: unknown) =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')

/**
 * Aviso no grupo do Telegram a cada alteração, no texto que a origem mandava.
 * Sem as credenciais configuradas não faz nada — é o caso do ambiente local.
 */
export async function notifyTelegram(
  env: Bindings,
  table: string,
  action: Action,
  oldValues: Values,
  newValues: Values,
  user: User,
): Promise<void> {
  if (!env.TELEGRAM_BOT_TOKEN || !env.TELEGRAM_CHAT_ID) return

  const thread = env.TELEGRAM_MESSAGE_THREAD_ID?.trim()
  const threadId = thread ? Number(thread) : undefined
  if (thread && (!/^\d+$/.test(thread) || !Number.isSafeInteger(threadId) || threadId! <= 0)) {
    console.error('telegram: TELEGRAM_MESSAGE_THREAD_ID deve ser um inteiro positivo')
    return
  }

  const title =
    action === 'insert'
      ? '🟩🟩🟩 Novo Registro 🟩🟩🟩'
      : action === 'update'
        ? '🟨🟨🟨 Registro Alterado 🟨🟨🟨'
        : '🟥🟥🟥 Registro Removido 🟥🟥🟥'
  const bullet = action === 'insert' ? '🟢' : action === 'update' ? '🟡' : '🔴'

  let detail = ''
  if (action === 'update') {
    for (const [key, value] of Object.entries(newValues)) {
      detail += `🟡 <b>${escape(key)}</b>\n<blockquote><s>${escape(oldValues[key])}</s></blockquote>\n<blockquote>${escape(value)}</blockquote>\n\n`
    }
  } else {
    const mark = action === 'insert' ? '✔️' : '❌'
    for (const [key, value] of Object.entries(action === 'insert' ? newValues : oldValues)) {
      detail += `${mark} <b>${escape(key)}</b>\n<blockquote>${escape(value)}</blockquote>\n\n`
    }
  }

  const pretty = (value: unknown) => escape(JSON.stringify(value, null, 4))
  const text =
    `<b>${title}</b>\n\n🏷️ ${escape(table)}\n${bullet} ${action}\n👤 ${escape(user.name)}\n\n` +
    `${detail}\n<b>Resumo:</b>\n` +
    `Antes: <pre>${pretty(action === 'insert' ? [] : oldValues)}</pre>\n\n` +
    `Depois: <pre>${pretty(action === 'delete' ? [] : newValues)}</pre>\n\n` +
    `Usuário: <pre>${pretty(user)}</pre>\n\n`

  try {
    await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      // O Telegram recusa mensagens acima de 4096 caracteres.
      body: JSON.stringify({
        chat_id: env.TELEGRAM_CHAT_ID,
        ...(threadId === undefined ? {} : { message_thread_id: threadId }),
        text: text.slice(0, 4096),
        parse_mode: 'HTML',
      }),
    })
  } catch (error) {
    console.error('telegram', error)
  }
}
