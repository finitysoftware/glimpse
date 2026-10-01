import { ChevronRight, CornerLeftUp, FileCode2, Folder } from 'lucide-react'
import type { Preview } from '../api'
import { CodePreview } from '../syntax/CodePreview'
import type { Visibility } from '../visibility/useVisibility'
import common from '../Repositories.module.css'
import styles from './Browser.module.css'
import { VisibilityBadge } from './VisibilityBadge'

/** The size of a file, 'File' where the platform does not report it, or the type of anything else (e.g. a submodule). */
function entryLabel(entry: { type: string; size: number | null }): string {
  if (entry.type !== 'file') return entry.type
  return entry.size === null ? 'File' : `${entry.size.toLocaleString()} bytes`
}

/** The path, and below it a folder's entries, a file or search results. */
export function PreviewContent({
  data,
  path,
  search,
  loading,
  visibility,
  onOpen,
  onClearSearch,
  onSearchPage,
}: {
  data: Preview | undefined
  path: string
  search: { q: string; page: number }
  loading: boolean
  /** While file visibility is edited, each entry shows what visitors of new links would get. */
  visibility?: Visibility
  onOpen: (path: string, kind?: 'directory' | 'file') => void
  onClearSearch: () => void
  onSearchPage: (page: number) => void
}) {
  const parts = path.split('/').filter(Boolean)
  return (
    <div className={common.previewContent}>
      <nav className={common.breadcrumb} aria-label="File path">
        <button onClick={() => onOpen('')} disabled={loading}>
          Root
        </button>
        {parts.map((part, index) => (
          <span key={index}>
            <ChevronRight size={13} />
            {index === parts.length - 1 ? (
              part
            ) : (
              <button
                disabled={loading}
                onClick={() => onOpen(parts.slice(0, index + 1).join('/'))}
              >
                {part}
              </button>
            )}
          </span>
        ))}
      </nav>
      {loading && (
        <p role="status" className={common.empty}>
          Loading preview…
        </p>
      )}
      {data?.limited && (
        <p className={common.notice}>
          At most 1,000 entries are shown here. This folder may be incomplete.
        </p>
      )}
      {data?.kind === 'directory' && (
        <div className={common.files}>
          {path && (
            <button className={styles.fileRow} onClick={() => onOpen(parts.slice(0, -1).join('/'))}>
              <CornerLeftUp size={16} /> Parent folder
            </button>
          )}
          {data.entries?.length === 0 && <p className={common.empty}>This folder is empty.</p>}
          {data.entries?.map(entry => (
            <button
              key={entry.path}
              className={styles.fileRow}
              disabled={!['dir', 'file'].includes(entry.type)}
              onClick={() => onOpen(entry.path, entry.type === 'dir' ? 'directory' : 'file')}
            >
              {entry.type === 'dir' ? <Folder size={17} /> : <FileCode2 size={17} />}
              <span>{entry.name}</span>
              <small>{entry.type === 'dir' ? 'Folder' : entryLabel(entry)}</small>
              {visibility && (
                <VisibilityBadge visibility={visibility} path={entry.path} type={entry.type} />
              )}
            </button>
          ))}
        </div>
      )}
      {data?.file && (
        <CodePreview path={data.file.path} content={data.file.content} bytes={data.file.bytes} />
      )}
      {data?.search && (
        <div className={common.files}>
          <p className={styles.searchSummary}>
            Files matching “{search.q}”{' '}
            <button className={common.secondary} onClick={onClearSearch}>
              Clear search
            </button>
          </p>
          {data.search.limited && (
            <p className={common.notice}>
              The repository host returned a partial file index. Results may be incomplete.
            </p>
          )}
          {data.search.items.length === 0 && <p className={common.empty}>No matching files.</p>}
          {data.search.items.map(entry => (
            <button
              key={entry.path}
              className={styles.fileRow}
              onClick={() => onOpen(entry.path, 'file')}
            >
              <FileCode2 size={17} />
              <span>{entry.path}</span>
              <small>{entryLabel(entry)}</small>
            </button>
          ))}
          <div className={styles.searchPager}>
            <button
              className={common.secondary}
              disabled={search.page === 1}
              onClick={() => onSearchPage(search.page - 1)}
            >
              Previous results
            </button>
            <span>Page {search.page}</span>
            <button
              className={common.secondary}
              disabled={!data.search.nextPage}
              onClick={() => onSearchPage(data.search!.nextPage!)}
            >
              Next results
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
