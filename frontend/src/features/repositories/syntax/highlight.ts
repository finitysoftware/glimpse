import { common, createLowlight } from 'lowlight'
import powershell from 'highlight.js/lib/languages/powershell'
import dockerfile from 'highlight.js/lib/languages/dockerfile'
import fsharp from 'highlight.js/lib/languages/fsharp'
import apache from 'highlight.js/lib/languages/apache'
import dos from 'highlight.js/lib/languages/dos'
import {
  canHighlight,
  MAX_HIGHLIGHT_TOKENS,
  type HighlightRequest,
  type HighlightResult,
  type HighlightToken,
} from './protocol'

const highlighter = createLowlight({ ...common, powershell, dockerfile, fsharp, apache, dos })
type SyntaxNode = ReturnType<typeof highlighter.highlight>['children'][number]

export function highlight({ language, content }: HighlightRequest): HighlightResult {
  if (!canHighlight(content)) return { tokens: null, reason: 'large' }
  try {
    const tree = highlighter.highlight(language, content)
    const tokens: HighlightToken[] = []
    let limited = false
    function visit(node: SyntaxNode, classes: string[], depth: number) {
      if (tokens.length >= MAX_HIGHLIGHT_TOKENS || depth > 64) {
        limited = true
        return
      }
      if (node.type === 'text') {
        const className = classes.join(' ')
        const previous = tokens.at(-1)
        if (previous?.className === className) previous.text += node.value
        else tokens.push({ text: node.value, className })
      } else if (node.type === 'element') {
        const names = node.properties.className
        const own = Array.isArray(names)
          ? names.filter(
              (name): name is string =>
                typeof name === 'string' && /^(?:hljs-)?[a-z][a-z0-9_-]*$/.test(name),
            )
          : []
        for (const child of node.children) {
          visit(child, own.length > 0 ? own : classes, depth + 1)
          if (limited) break
        }
      }
    }
    for (const child of tree.children) {
      visit(child, [], 0)
      if (limited) break
    }
    if (limited) return { tokens: null, reason: 'large' }
    // Preserve source byte-for-byte as JavaScript text; never emit raw repository HTML.
    if (tokens.map(token => token.text).join('') !== content)
      return { tokens: null, reason: 'unavailable' }
    return { tokens }
  } catch {
    return { tokens: null, reason: 'unavailable' }
  }
}
