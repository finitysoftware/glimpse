import { Tokens } from './Tokens'
import { useHighlightedText } from './useHighlightedText'
import styles from './CodePreview.module.css'

export function CodePreview({
  path,
  content,
  bytes,
}: {
  path: string
  content: string
  bytes: number
}) {
  const { tokens, status, label } = useHighlightedText(path, content)
  return (
    <>
      <div className={styles.meta}>
        {bytes.toLocaleString()} bytes · {status}
      </div>
      <pre className={styles.code} tabIndex={0} aria-label={`${label} source code`}>
        <code>{tokens ? <Tokens tokens={tokens} /> : content}</code>
      </pre>
    </>
  )
}
