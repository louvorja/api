import type { Context, MiddlewareHandler } from 'hono'
import type { App } from '../../types.ts'
import { type Claims, sign, TTL_SECONDS, verify } from './jwt.ts'
import { presentUser, type UserRow } from './users.ts'

const now = () => Math.floor(Date.now() / 1000)

export function unauthorized(c: Context<App>) {
  return c.json({ error: 'Token inválido ou expirado.', code: 401 }, 401)
}

export async function issueToken(c: Context<App>, user: UserRow): Promise<string> {
  const issuedAt = now()
  return sign(
    {
      iss: `${new URL(c.req.url).origin}/auth/login`,
      iat: issuedAt,
      exp: issuedAt + TTL_SECONDS,
      nbf: issuedAt,
      jti: crypto.randomUUID(),
      sub: String(user.id),
      name: user.name,
      username: user.username,
      id: user.id,
    },
    c.env.JWT_SECRET,
  )
}

/**
 * Encerra um token antes do vencimento. `grace` existe por causa da renovação:
 * o painel renova a cada troca de tela enquanto as tabelas ainda carregam com o
 * token antigo, e derrubá-lo na hora faria essas requisições voltarem 401.
 */
export function blockToken(db: D1Database, claims: Claims, grace = 0): D1PreparedStatement[] {
  return [
    db.prepare('DELETE FROM jwt_blacklist WHERE expires_at < ?').bind(now()),
    db
      .prepare(
        'INSERT INTO jwt_blacklist (jti, blocked_after, expires_at) VALUES (?, ?, ?) ' +
          'ON CONFLICT (jti) DO UPDATE SET blocked_after = min(blocked_after, excluded.blocked_after)',
      )
      .bind(claims.jti, now() + grace, claims.exp),
  ]
}

export const requireAuth: MiddlewareHandler<App> = async (c, next) => {
  const header = c.req.header('Authorization') ?? ''
  const token = header.startsWith('Bearer ') ? header.slice(7).trim() : ''
  const claims = token ? await verify(token, c.env.JWT_SECRET, now()) : null
  // Só tokens de usuário com identificador numérico chegam ao banco.
  if (!claims || !/^\d+$/.test(claims.sub)) return unauthorized(c)

  const [blocked, found] = await c.env.DB.batch<Record<string, unknown>>([
    c.env.DB.prepare('SELECT blocked_after FROM jwt_blacklist WHERE jti = ?').bind(claims.jti),
    c.env.DB.prepare('SELECT * FROM users WHERE id = ?').bind(claims.sub),
  ])

  const blockedAfter = blocked?.results[0]?.blocked_after
  if (typeof blockedAfter === 'number' && blockedAfter <= now()) return unauthorized(c)

  const row = found?.results[0] as UserRow | undefined
  if (!row) return unauthorized(c)

  c.set('user', presentUser(row))
  c.set('claims', claims)
  await next()
}

/** Sem o segredo configurado, qualquer um poderia forjar um token assinado com "". */
export const requireSecret: MiddlewareHandler<App> = async (c, next) => {
  if (!c.env.JWT_SECRET) {
    console.error('JWT_SECRET não configurado')
    return c.json({ error: 'Erro interno do servidor.', code: 500 }, 500)
  }
  await next()
}

export async function readBody(c: Context<App>): Promise<Record<string, unknown>> {
  try {
    const body = (await c.req.json()) as unknown
    return body !== null && typeof body === 'object' && !Array.isArray(body)
      ? (body as Record<string, unknown>)
      : {}
  } catch {
    return {}
  }
}
