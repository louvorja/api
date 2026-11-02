const escape = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
function inline(text: string, depth = 0): string {
  if (depth > 10) return escape(text)
  let result = ''
  while (text) {
    const match = /^(\x60([^\x60]+)\x60|\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|\*\*(.+?)\*\*|__(.+?)__|~~(.+?)~~|\*([^*]+)\*|_([^_]+)_|\\([\\\x60*_[\]{}()#+.!>-]))/.exec(text)
    if (match) {
      if (match[2]) result += '<code>' + escape(match[2]) + '</code>'
      else if (match[3]) result += '<a href="' + escape(match[4]!) + '">' + inline(match[3], depth + 1) + '</a>'
      else if (match[5] || match[6]) result += '<b>' + inline((match[5] || match[6])!, depth + 1) + '</b>'
      else if (match[7]) result += '<s>' + inline(match[7], depth + 1) + '</s>'
      else if (match[8] || match[9]) result += '<i>' + inline((match[8] || match[9])!, depth + 1) + '</i>'
      else result += escape(match[10]!)
      text = text.slice(match[0].length)
    } else {
      const char = String.fromCodePoint(text.codePointAt(0)!)
      result += escape(char)
      text = text.slice(char.length)
    }
  }
  return result
}
/** Supported Markdown subset; raw HTML is always escaped. */
export function telegramMarkdown(markdown: string): string {
  let fence: string | undefined
  const output: string[] = []
  for (const line of markdown.replace(/\r\n?/g, '\n').split('\n')) {
    const marker = /^\s*(\x60{3,}|~{3,})/.exec(line)
    if (marker && (!fence || marker[1]![0] === fence[0])) {
      if (fence) { output.push('</pre>'); fence = undefined }
      else { fence = marker[1]; output.push('<pre>') }
      continue
    }
    if (fence) { output.push(escape(line)); continue }
    const heading = /^ {0,3}#{1,6}\s+(.+?)(?:\s+#+)?$/.exec(line)
    if (heading) output.push('<b>' + inline(heading[1]!) + '</b>')
    else output.push(inline(line.replace(/^(\s*)[-*+]\s+/, '$1• ')))
  }
  if (fence) output.push('</pre>')
  return output.join('\n')
}
/** Split generated HTML by visible UTF-16 length, closing/reopening tags. */
export function telegramHtmlParts(html: string, limit = 3500): string[] {
  const parts: string[] = []
  const stack: Array<{ tag: string; open: string }> = []
  let part = ''
  let size = 0
  for (const token of html.match(/<[^>]+>|&(?:amp|lt|gt|quot);|[\s\S]/gu) || []) {
    if (token.startsWith('<')) {
      const tag = /^<\/?([a-z]+)/.exec(token)![1]!
      if (token.startsWith('</')) stack.pop()
      else stack.push({ tag, open: token })
      part += token
      continue
    }
    const length = token.startsWith('&') ? 1 : token.length
    if (size + length > limit) {
      parts.push(part + [...stack].reverse().map(t => '</' + t.tag + '>').join(''))
      part = stack.map(t => t.open).join('')
      size = 0
    }
    part += token
    size += length
  }
  if (size) parts.push(part + [...stack].reverse().map(t => '</' + t.tag + '>').join(''))
  return parts
}
