import { useState, type RefObject } from 'react'
import { ArrowLeft, Copy, FileCode2 } from 'lucide-react'
import type { CommitDetail as Detail } from '../api'
import common from '../Repositories.module.css'
import { DiffPreview } from './DiffPreview'
import { dateLabel, title } from './format'
import styles from './History.module.css'

function parentsLabel(parents: string[]): string {
  if (parents.length === 0) return 'Initial commit · No parent'
  if (parents.length === 1) return `Parent ${parents[0]!.slice(0, 12)}`
  return `Merge commit · ${parents.length} parents · Diff from the repository host`
}

/** One commit: message, parents, changed files page by page and the diff of the chosen file. */
export function CommitDetail({
  detail,
  filtered,
  heading,
  onOpen,
  onFilesPage,
}: {
  detail: Detail
  /** Only the link's shared files are counted and listed. */
  filtered: boolean
  heading: RefObject<HTMLHeadingElement | null>
  onOpen: (sha: string, path?: string) => void
  onFilesPage: (page: number) => void
}) {
  const [selectedPath, setSelectedPath] = useState('')
  const [copied, setCopied] = useState(false)
  const [copyError, setCopyError] = useState(false)
  const file = detail.files.find(item => item.path === selectedPath) ?? detail.files[0]
  return (
    <>
      <header className={styles.detailHeader}>
        <div>
          <h2 ref={heading} tabIndex={-1}>
            {title(detail.commit.message)}
          </h2>
          <p>
            {detail.commit.author && `${detail.commit.author} · `}
            {dateLabel(detail.commit.date, true)}
          </p>
          <button
            className={common.secondary}
            title={detail.commit.sha}
            aria-label="Copy commit SHA"
            onClick={() => {
              void navigator.clipboard
                .writeText(detail.commit.sha)
                .then(() => setCopied(true))
                .catch(() => setCopyError(true))
            }}
          >
            <code>{detail.commit.sha.slice(0, 12)}</code>
            <Copy size={14} />
            {copied && 'Copied'}
          </button>
          {/* The button keeps its name, so screen readers hear the copy here. */}
          <span role="status" className={common.visuallyHidden}>
            {copied ? 'Commit SHA copied.' : ''}
          </span>
          {copyError && (
            <p role="status">
              Copy this SHA: <code>{detail.commit.sha}</code>
            </p>
          )}
        </div>
        <button className={common.secondary} onClick={() => onOpen(detail.commit.sha)}>
          Browse this revision
        </button>
      </header>
      {detail.commit.message.includes('\n') && (
        <p className={styles.message}>
          {detail.commit.message.split('\n').slice(1).join('\n').trim()}
        </p>
      )}
      {detail.commit.messageLimited && (
        <p className={styles.notice}>The commit message was shortened for display.</p>
      )}
      <p className={styles.parents}>{parentsLabel(detail.commit.parents)}</p>
      {detail.commit.parents.length > 1 && (
        <p className={styles.notice}>
          Parents: {detail.commit.parents.map(sha => sha.slice(0, 12)).join(', ')}. This is the diff
          provided by the repository host, not a separate comparison with each parent.
        </p>
      )}
      <div className={styles.summary}>
        <strong>{detail.files.length} files on this page</strong>
        <span
          title={filtered ? 'Additions in shared files' : 'Total additions in this commit'}
          className={styles.positive}
        >
          +{detail.stats.additions.toLocaleString()}
        </span>
        <span
          title={filtered ? 'Deletions in shared files' : 'Total deletions in this commit'}
          className={styles.negative}
        >
          −{detail.stats.deletions.toLocaleString()}
        </span>
      </div>
      <div className={styles.changedFiles} role="group" aria-label="Changed files">
        {detail.files.map(item => (
          <button
            key={item.path}
            className={styles.changedFile}
            aria-pressed={item.path === file?.path}
            onClick={() => setSelectedPath(item.path)}
          >
            <FileCode2 size={16} />
            <span>
              {item.path}
              {item.previousPath && (
                <small>
                  {item.previousPath} → {item.path}
                </small>
              )}
            </span>
            <small>{item.status}</small>
            <span className={styles.positive}>+{item.additions}</span>
            <span className={styles.negative}>−{item.deletions}</span>
          </button>
        ))}
      </div>
      {detail.files.length === 0 && (
        <p className={styles.empty}>No changed files were returned on this page.</p>
      )}
      {(detail.nextPage || detail.page > 1) && (
        <div className={styles.pager}>
          <button
            className={common.secondary}
            disabled={detail.page === 1}
            onClick={() => onFilesPage(detail.page - 1)}
          >
            Previous files
          </button>
          <span>Files page {detail.page}</span>
          <button
            className={common.secondary}
            disabled={!detail.nextPage}
            onClick={() => onFilesPage(detail.nextPage!)}
          >
            Next files
          </button>
        </div>
      )}
      {detail.filesLimited && (
        <p className={styles.notice}>
          Up to 3,000 changed files are shown. This commit's file list may be incomplete.
        </p>
      )}
      {file && (
        <div className={styles.patch}>
          <div className={styles.patchHeader}>
            <strong>{file.path}</strong>
            <button
              className={common.secondary}
              disabled={file.status === 'removed' && !detail.commit.parents[0]}
              onClick={() =>
                onOpen(
                  file.status === 'removed' ? detail.commit.parents[0]! : detail.commit.sha,
                  file.previousPath && file.status === 'removed' ? file.previousPath : file.path,
                )
              }
            >
              {file.status === 'removed' ? (
                <>
                  <ArrowLeft size={14} /> View before deletion
                </>
              ) : (
                'View file'
              )}
            </button>
          </div>
          <DiffPreview key={`${detail.commit.sha}:${file.path}:${detail.page}`} file={file} />
        </div>
      )}
    </>
  )
}
