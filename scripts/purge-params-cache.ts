import { PARAMS_CACHE_PREFIX, purgeParamsCache } from './lib/params-cache.ts'

await purgeParamsCache(process.env)
console.log(
  `Cloudflare aceitou a limpeza do prefixo ${PARAMS_CACHE_PREFIX} (incluindo query strings).`,
)
