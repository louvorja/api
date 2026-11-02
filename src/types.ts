import type { Claims } from './lib/auth/jwt.ts'
import type { User } from './lib/auth/users.ts'
import type { WindowCounter } from './lib/window-counter.ts'

export type Bindings = {
  FILES: R2Bucket
  FILES_ORIGINALS: R2Bucket
  DB: D1Database
  RATE_LIMIT_FILES: RateLimit
  RATE_LIMIT_METADATA: RateLimit
  RATE_LIMIT_GENERAL: RateLimit
  BIBLE_LIMITER: DurableObjectNamespace<WindowCounter>
  /** Capítulos da Bíblia por minuto e por IP, antes do 429. */
  BIBLE_LIMIT_PER_MINUTE?: string
  /** Milissegundos de espera antes de responder 429 num capítulo da Bíblia. */
  BIBLE_THROTTLE_MS?: string
  JWT_SECRET: string
  GITHUB_TASK_TOKEN?: string
  GITHUB_TASK_REF?: string
  GITHUB_TASK_REPO?: string
  TELEGRAM_BOT_TOKEN?: string
  TELEGRAM_CHAT_ID?: string
  /** Tópico opcional do grupo; ausente ou vazio mantém o envio geral. */
  TELEGRAM_MESSAGE_THREAD_ID?: string
}

export type Variables = {
  user: User
  claims: Claims
}

export type App = { Bindings: Bindings; Variables: Variables }
