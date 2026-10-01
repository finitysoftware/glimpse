import type { Language } from './languages'

export const MAX_HIGHLIGHT_CHARACTERS = 256 * 1024
export const MAX_HIGHLIGHT_LINES = 5000
export const MAX_HIGHLIGHT_TOKENS = 20000
export const HIGHLIGHT_TIMEOUT_MS = 3000

export interface HighlightToken {
  text: string
  className: string
}
export interface HighlightRequest {
  language: Language
  content: string
}
export type HighlightResult =
  { tokens: HighlightToken[]; reason?: never } | { tokens: null; reason: 'large' | 'unavailable' }

export function canHighlight(content: string): boolean {
  return (
    content.length <= MAX_HIGHLIGHT_CHARACTERS &&
    content.split('\n', MAX_HIGHLIGHT_LINES + 1).length <= MAX_HIGHLIGHT_LINES
  )
}
