/** O prefixo cobre também ?type=env e ?type=json, sem limpar a zona inteira. */
export const PARAMS_CACHE_PREFIX = 'api.louvorja.com.br/params'

export async function purgeParamsCache(
  env: Record<string, string | undefined>,
  request: typeof fetch = fetch,
): Promise<void> {
  const zone = env.CLOUDFLARE_ZONE_ID?.trim()
  const token = env.CLOUDFLARE_CACHE_PURGE_TOKEN?.trim()
  if (!zone || !/^[a-f0-9]{32}$/i.test(zone)) {
    throw new Error(
      'Configure CLOUDFLARE_ZONE_ID com o Zone ID de louvorja.com.br (32 caracteres hexadecimais).',
    )
  }
  if (!token) {
    throw new Error(
      'Configure CLOUDFLARE_CACHE_PURGE_TOKEN com permissão Zone > Cache Purge para louvorja.com.br.',
    )
  }
  const response = await request(`https://api.cloudflare.com/client/v4/zones/${zone}/purge_cache`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ prefixes: [PARAMS_CACHE_PREFIX] }),
    signal: AbortSignal.timeout(20_000),
  })
  // Não registrar o corpo da resposta nem credenciais nos logs.
  if (!response.ok) {
    throw new Error(
      `Limpeza de /params recusada pela Cloudflare (HTTP ${response.status}). Confira zona e permissão Cache Purge; execute novamente a manutenção.`,
    )
  }
  const result = (await response.json()) as { success?: boolean }
  if (result.success !== true) {
    throw new Error(
      'Cloudflare não confirmou a limpeza de /params. Confira zona e token; execute novamente a manutenção.',
    )
  }
}
