/** Compatibilidade: mantém o comando antigo restrito ao Classic e seus aliases. */
process.argv.push('--classic-only')
await import('./apps-versions.ts')
export {}
