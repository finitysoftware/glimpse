import { plural } from '../../format'
import common from '../../styles/app.module.css'
import styles from './Admin.module.css'

/** Total and Previous / Page / Next under an admin table. */
export function Pager({
  total,
  noun,
  page,
  hasNext,
  onPage,
}: {
  total: number
  /** Singular, e.g. 'user'. */
  noun: string
  page: number
  hasNext: boolean
  onPage: (page: number) => void
}) {
  return (
    <div className={styles.pager}>
      <span>{plural(total, noun)}</span>
      <button className={common.secondary} disabled={page === 1} onClick={() => onPage(page - 1)}>
        Previous
      </button>
      <span>Page {page}</span>
      <button className={common.secondary} disabled={!hasNext} onClick={() => onPage(page + 1)}>
        Next
      </button>
    </div>
  )
}
