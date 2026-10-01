import type { HighlightToken } from './protocol'

/** Highlighted source: each token as text, in a span with the highlighter's classes where it has any. */
export function Tokens({ tokens }: { tokens: HighlightToken[] }) {
  return tokens.map((token, index) =>
    token.className ? (
      <span key={index} className={token.className}>
        {token.text}
      </span>
    ) : (
      token.text
    ),
  )
}
