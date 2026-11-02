/** Remove configurações de transporte descontinuado ainda presentes nos snapshots. */
export function publicParams(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(params).filter(
      ([key, value]) =>
        !/ftp/i.test(key) &&
        !(typeof value === 'string' && /(?:s?ftps?:\/\/|\/ftp(?:[/?#]|$))/i.test(value)),
    ),
  )
}
