import { GitCommitHorizontal } from 'lucide-react'
import type { Preview } from '../api'
import common from '../Repositories.module.css'
import { dateLabel, title } from './format'
import styles from './History.module.css'

/** The commits of one History page, grouped by day, with Newer/Older or cursor paging. */
export function CommitList({
  snapshot,
  selectedSha,
  onCommit,
  onPage,
  onCursor,
}: {
  snapshot: Preview
  selectedSha: string
  onCommit: (sha: string) => void
  onPage: (page: number) => void
  onCursor: (cursor: string) => void
}) {
  const history = snapshot.history!
  const filtered = history.filtered === true
  return (
    <aside className={styles.commits} aria-label="Commit history">
      <h2>Commits</h2>
      <p className={styles.subtitle}>
        {snapshot.path ? `History of ${snapshot.path}` : 'Repository history'}
        <br />
        From {snapshot.commitSha.slice(0, 12)}
      </p>
      {snapshot.path && (
        <p className={styles.notice}>
          History follows this path. Earlier names may have separate history.
        </p>
      )}
      {filtered && (
        <p className={styles.notice}>Only changes to files shared through this link are listed.</p>
      )}
      {history.items.length === 0 && (
        <p className={styles.empty}>
          {filtered
            ? 'No changes to shared files in this part of the history.'
            : `No commits on this page${snapshot.path ? ' for this path' : ''}.`}
        </p>
      )}
      <ol className={styles.timeline}>
        {history.items.map((item, index) => (
          <li key={item.sha}>
            {(index === 0 ||
              dateLabel(item.date) !== dateLabel(history.items[index - 1]!.date)) && (
              <p className={styles.date}>{dateLabel(item.date)}</p>
            )}
            <button
              className={styles.commitButton}
              aria-current={selectedSha === item.sha ? 'true' : undefined}
              onClick={() => onCommit(item.sha)}
            >
              <GitCommitHorizontal size={18} />
              <span>
                <strong>{title(item.message)}</strong>
                <small>
                  {item.author && `${item.author} · `}
                  {item.date
                    ? new Date(item.date).toLocaleTimeString([], {
                        hour: '2-digit',
                        minute: '2-digit',
                      })
                    : 'Unknown time'}{' '}
                  · {item.sha.slice(0, 7)}
                </small>
              </span>
            </button>
          </li>
        ))}
      </ol>
      {history.limited && (
        <p className={styles.notice}>
          The history page limit was reached. Older commits are not listed.
        </p>
      )}
      {history.incomplete && (
        <p className={styles.notice}>
          Only part of the history was checked this time. Continue to look for older changes to
          shared files.
        </p>
      )}
      {history.largeCommitsSkipped && (
        <p className={styles.notice}>
          Some very large commits could not be fully checked and are not listed.
        </p>
      )}
      {filtered ? (
        <div className={styles.pager}>
          <button
            className={common.secondary}
            disabled={!history.cursor}
            onClick={() => onCursor('')}
          >
            Newest
          </button>
          <button
            className={common.secondary}
            disabled={!history.nextCursor}
            onClick={() => onCursor(history.nextCursor!)}
          >
            {history.incomplete ? 'Continue' : 'Older'}
          </button>
        </div>
      ) : (
        <div className={styles.pager}>
          <button
            className={common.secondary}
            disabled={history.page === 1}
            onClick={() => onPage(history.page - 1)}
          >
            Newer
          </button>
          <span>Page {history.page}</span>
          <button
            className={common.secondary}
            disabled={!history.nextPage}
            onClick={() => onPage(history.nextPage!)}
          >
            Older
          </button>
        </div>
      )}
    </aside>
  )
}
