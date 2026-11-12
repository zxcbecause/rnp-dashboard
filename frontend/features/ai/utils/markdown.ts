/**
 * Minimal, safe Markdown → HTML for AI texts: paragraphs, "-"/"*"/"1." lists, **bold**, *italic*, `code`.
 * Everything is HTML-escaped first, so model output can never inject markup or scripts.
 */
export function renderMarkdown(source: string): string {
  const blocks: string[] = []
  let list: { ordered: boolean, items: string[] } | null = null
  let paragraph: string[] = []

  const flushParagraph = () => {
    if (paragraph.length) blocks.push(`<p>${paragraph.map(inline).join('<br>')}</p>`)
    paragraph = []
  }
  const flushList = () => {
    if (list) {
      const tag = list.ordered ? 'ol' : 'ul'
      blocks.push(`<${tag}>${list.items.map(item => `<li>${inline(item)}</li>`).join('')}</${tag}>`)
    }
    list = null
  }

  for (const raw of source.replace(/\r\n?/g, '\n').split('\n')) {
    const line = raw.trimEnd()
    const bullet = /^\s*[-*•]\s+(.*)$/.exec(line)
    const numbered = /^\s*\d+[.)]\s+(.*)$/.exec(line)

    if (bullet || numbered) {
      flushParagraph()
      const ordered = numbered !== null && bullet === null
      if (!list || list.ordered !== ordered) {
        flushList()
        list = { ordered, items: [] }
      }
      list.items.push((bullet ?? numbered)![1]!)
    }
    else if (line.trim() === '') {
      flushParagraph()
      flushList()
    }
    else {
      flushList()
      // Headings are rendered as bold paragraphs: AI texts here are short notes, not documents.
      const heading = /^#{1,6}\s+(.*)$/.exec(line)
      paragraph.push(heading ? `**${heading[1]}**` : line)
    }
  }
  flushParagraph()
  flushList()

  return blocks.join('')
}

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function inline(text: string): string {
  return escapeHtml(text)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
}
