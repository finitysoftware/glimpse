import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { loadCommit, repositoryKeys, retryBusy, type Preview } from '../api'
import { useResizablePane } from '../useResizablePane'
import { CommitDetail } from './CommitDetail'
import { CommitList } from './CommitList'
import styles from './History.module.css'

interface Props {
  endpoint: `/api/${string}`
  snapshot: Preview
  initialCommit?: string
  onPage: (page: number) => void
  onCursor: (cursor: string) => void
  onOpen: (sha: string, path?: string) => void
  onFailure: (reason: unknown) => void
  /** Opens a commit through the address; the preview starts again with it selected. */
  onCommit: (sha: string) => void
}

/** History: the commit list next to the chosen commit's details. */
export function History({
  endpoint,
  snapshot,
  initialCommit,
  onPage,
  onCursor,
  onOpen,
  onFailure,
  onCommit,
}: Props) {
  const history = snapshot.history!
  const { container, paneStyle, separator } = useResizablePane('Resize commits panel', 320)
  const [selection, setSelection] = useState({
    sha: initialCommit || history.items[0]?.sha || '',
    page: 1,
  })
  const detailHeading = useRef<HTMLHeadingElement>(null)
  // A commit opened by its address takes the focus once its details arrive.
  const focusDetail = useRef(!!initialCommit)
  const branch = snapshot.branch
  const commit = useQuery({
    queryKey: repositoryKeys.commit(endpoint, branch, selection.sha, selection.page),
    queryFn: ({ signal }) => loadCommit(endpoint, branch, selection.sha, selection.page, signal),
    enabled: selection.sha !== '',
    ...retryBusy,
    staleTime: Infinity,
    // A commit that cannot be loaded ends the preview with its error.
    meta: { onError: onFailure },
  })
  const detail = commit.data ?? null
  useEffect(() => {
    if (detail && focusDetail.current) {
      detailHeading.current?.focus()
      focusDetail.current = false
    }
  }, [detail])

  return (
    <div ref={container} style={paneStyle} className={styles.layout}>
      <CommitList
        snapshot={snapshot}
        selectedSha={selection.sha}
        onCommit={onCommit}
        onPage={onPage}
        onCursor={onCursor}
      />
      {separator}
      <section className={styles.details} aria-label="Commit details">
        {!selection.sha && <p className={styles.empty}>Choose a commit to view its changes.</p>}
        {selection.sha && !detail && (
          <p role="status" className={styles.empty}>
            Loading commit…
          </p>
        )}
        {detail && (
          <CommitDetail
            key={detail.commit.sha}
            detail={detail}
            filtered={history.filtered === true}
            heading={detailHeading}
            onOpen={onOpen}
            onFilesPage={page => setSelection(previous => ({ ...previous, page }))}
          />
        )}
      </section>
    </div>
  )
}
