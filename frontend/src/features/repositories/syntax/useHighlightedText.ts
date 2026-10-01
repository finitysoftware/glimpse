import { useEffect, useState } from 'react'
import { detectLanguage, languageLabels, type Language } from './languages'
import { canHighlight, HIGHLIGHT_TIMEOUT_MS, type HighlightResult } from './protocol'

export function useHighlightedText(path: string, content: string) {
  const language = detectLanguage(path)
  const eligible = language !== null && canHighlight(content)
  const [result, setResult] = useState<{
    path: string
    content: string
    value: HighlightResult
  } | null>(null)
  const current = result?.path === path && result.content === content ? result.value : null
  useEffect(() => {
    if (!eligible || language === null) return
    let worker: Worker | undefined
    let active = true
    function finish(value: HighlightResult) {
      if (!active) return
      active = false
      clearTimeout(timer)
      worker?.terminate()
      setResult({ path, content, value })
    }
    const timer = setTimeout(
      () => finish({ tokens: null, reason: 'unavailable' }),
      HIGHLIGHT_TIMEOUT_MS,
    )
    try {
      worker = new Worker(new URL('./highlight-worker.ts', import.meta.url), { type: 'module' })
      worker.onmessage = (event: MessageEvent<HighlightResult>) => finish(event.data)
      worker.onerror = () => finish({ tokens: null, reason: 'unavailable' })
      worker.onmessageerror = () => finish({ tokens: null, reason: 'unavailable' })
      worker.postMessage({ language, content })
    } catch {
      queueMicrotask(() => finish({ tokens: null, reason: 'unavailable' }))
    }
    return () => {
      active = false
      clearTimeout(timer)
      worker?.terminate()
    }
  }, [path, content, language, eligible])
  return {
    tokens: current?.tokens,
    status: statusOf(language, eligible, current),
    label: language === null ? 'Text' : languageLabels[language],
  }
}

/** The language shown above the code, and why it is plain text when it is. */
function statusOf(language: Language | null, eligible: boolean, result: HighlightResult | null) {
  if (language === null) return 'Plain text'
  const label = languageLabels[language]
  if (!eligible || result?.reason === 'large') return `${label} · Plain text for large file`
  if (result?.reason === 'unavailable') return `${label} · Plain text`
  return label
}
