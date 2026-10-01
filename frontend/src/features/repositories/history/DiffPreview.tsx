import { useMemo, useState } from 'react'
import { clsx } from 'clsx'
import type { ChangedFile } from '../api'
import common from '../Repositories.module.css'
import { Tokens } from '../syntax/Tokens'
import { useHighlightedText } from '../syntax/useHighlightedText'
import syntax from '../syntax/CodePreview.module.css'
import { parseDiff, tokenLines } from './diff'
import styles from './History.module.css'

const rowHeight = 24

/** Why a changed file shows no diff, or only part of it. */
const patchNotices: Record<Exclude<ChangedFile['patchState'], 'complete'>, string> = {
  restricted:
    'This file was moved from or to a file that is not shared through this link, so its changes are not shown.',
  limited:
    'This patch exceeds the preview limit. The file is still listed, but its diff is not displayed.',
  incomplete: 'The repository host returned an incomplete patch. Some changes are not shown.',
  unavailable:
    'No text patch is available. This may be a binary file, a rename without text changes, or a diff the repository host omitted.',
}

export function DiffPreview({ file }: { file: ChangedFile }) {
  const rows = useMemo(() => parseDiff(file.patch ?? ''), [file.patch])
  const source = useMemo(() => rows.map(row => row.text).join('\n'), [rows])
  const { tokens, status } = useHighlightedText(file.path, source)
  const highlighted = useMemo(() => (tokens ? tokenLines(tokens) : null), [tokens])
  const [scrollTop, setScrollTop] = useState(0)
  const start = Math.max(0, Math.floor(scrollTop / rowHeight) - 10)
  const visible = rows.slice(start, start + 50)
  const width = useMemo(
    () => Math.max(60, ...rows.map(row => row.text.replace(/\t/g, '    ').length)) + 18,
    [rows],
  )
  return (
    <>
      {file.patchState !== 'complete' && (
        <p className={styles.notice} role="status">
          {patchNotices[file.patchState]}
        </p>
      )}
      {file.patch !== null && (
        <>
          <p className={styles.diffMeta}>{status} · Unified diff · Green + added, red − removed</p>
          <div
            className={clsx(styles.diff, syntax.code)}
            tabIndex={0}
            role="region"
            aria-label={`Diff for ${file.path}`}
            style={{ height: Math.min(440, rows.length * rowHeight + 16) }}
            onScroll={event => setScrollTop(event.currentTarget.scrollTop)}
          >
            <div
              className={styles.diffCanvas}
              style={{ height: rows.length * rowHeight, minWidth: `${width}ch` }}
            >
              <div style={{ transform: `translateY(${start * rowHeight}px)` }}>
                {visible.map((row, index) => {
                  const tokens = highlighted?.[start + index]
                  return (
                    <div key={start + index} className={clsx(styles.diffLine, styles[row.kind])}>
                      <LineNumber label="Old line" value={row.old} />
                      <LineNumber label="New line" value={row.next} />
                      <span className={styles.marker}>{row.marker}</span>
                      <code>{tokens ? <Tokens tokens={tokens} /> : row.text}</code>
                    </div>
                  )
                })}
              </div>
            </div>
          </div>
        </>
      )}
    </>
  )
}

/** A line number as it looks, and as screen readers say it (a label on a plain span would be ignored). */
function LineNumber({ label, value }: { label: string; value: number | null }) {
  return (
    <span className={styles.lineNumber}>
      <span aria-hidden="true">{value}</span>
      {value !== null && (
        <span className={common.visuallyHidden}>
          {label} {value}
        </span>
      )}
    </span>
  )
}
