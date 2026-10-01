import type { HighlightToken } from '../syntax/protocol'

export interface DiffLine {
  old: number | null
  next: number | null
  marker: string
  text: string
  kind: 'context' | 'added' | 'removed' | 'hunk'
}

export function parseDiff(patch: string): DiffLine[] {
  let old = 0,
    next = 0
  return patch
    .replace(/\n$/, '')
    .split('\n')
    .map(line => {
      const hunk = /^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/.exec(line)
      if (hunk) {
        old = Number(hunk[1])
        next = Number(hunk[2])
        return { old: null, next: null, marker: '', text: line, kind: 'hunk' }
      }
      if (line.startsWith('+'))
        return { old: null, next: next++, marker: '+', text: line.slice(1), kind: 'added' }
      if (line.startsWith('-'))
        return { old: old++, next: null, marker: '−', text: line.slice(1), kind: 'removed' }
      if (line.startsWith(' '))
        return { old: old++, next: next++, marker: ' ', text: line.slice(1), kind: 'context' }
      return { old: null, next: null, marker: '', text: line, kind: 'hunk' }
    })
}

export function tokenLines(tokens: HighlightToken[]): HighlightToken[][] {
  const lines: HighlightToken[][] = [[]]
  for (const token of tokens) {
    const parts = token.text.split('\n')
    parts.forEach((text, index) => {
      if (index) lines.push([])
      if (text) lines[lines.length - 1]!.push({ text, className: token.className })
    })
  }
  return lines
}
