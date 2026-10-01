import type { FormEvent } from 'react'
import { FolderTree, ListChecks, RefreshCw } from 'lucide-react'
import { clsx } from 'clsx'
import type { Branches } from '../api'
import common from '../Repositories.module.css'
import styles from './Browser.module.css'

interface Toggle {
  pressed: boolean
  disabled: boolean
  onToggle: () => void
}

/** Repository name with Refresh, Tree and (for the owner) File visibility. */
export function PreviewToolbar({
  title,
  refreshDisabled,
  onRefresh,
  tree,
  fileVisibility,
}: {
  title: string
  refreshDisabled: boolean
  onRefresh: () => void
  tree: Toggle & { open: boolean }
  fileVisibility?: Toggle
}) {
  return (
    <div className={common.browserTop}>
      <div>
        <strong>{title}</strong>
      </div>
      <div className={styles.previewActions}>
        <button className={common.secondary} disabled={refreshDisabled} onClick={onRefresh}>
          <RefreshCw size={15} /> Refresh
        </button>
        <button
          className={clsx(common.secondary, styles.treeToggle)}
          aria-pressed={tree.pressed}
          title={tree.open ? 'Hide project tree' : 'Show project tree'}
          disabled={tree.disabled}
          onClick={tree.onToggle}
        >
          <FolderTree size={16} /> Tree
        </button>
        {fileVisibility && (
          <button
            className={clsx(common.secondary, styles.treeToggle)}
            aria-pressed={fileVisibility.pressed}
            disabled={fileVisibility.disabled}
            onClick={fileVisibility.onToggle}
          >
            <ListChecks size={16} /> File visibility
          </button>
        )}
      </div>
    </div>
  )
}

/** Branch choice (with Load more) and the file search of one branch. */
export function BranchSearchForm({
  branches,
  branch,
  branchDisabled,
  search,
  searchDisabled,
  searchLocked,
  onBranch,
  onSearchChange,
  onSearch,
}: {
  branches: Branches | undefined
  branch: string
  branchDisabled: boolean
  search: string
  /** The Search button waits for a loaded check. */
  searchDisabled: boolean
  /** File visibility editing keeps the list on the whole branch. */
  searchLocked: boolean
  onBranch: (branch: string) => void
  onSearchChange: (value: string) => void
  onSearch: (event: FormEvent) => void
}) {
  return (
    <form className={common.revision} onSubmit={onSearch}>
      <select
        aria-label="Branch"
        value={branch}
        disabled={branchDisabled}
        onChange={event => onBranch(event.target.value)}
      >
        {(!branches || (branch && !branches.items.includes(branch))) && (
          <option value={branch}>{branch || 'Loading branches…'}</option>
        )}
        {branches?.items.map(item => (
          <option key={item} value={item}>
            {item}
          </option>
        ))}
        {branches?.nextPage && <option value="~more">Load more branches…</option>}
      </select>
      <input
        aria-label="Search files"
        value={search}
        placeholder="Search files by name or path…"
        onChange={event => onSearchChange(event.target.value)}
        maxLength={200}
        disabled={searchLocked}
      />
      <button
        className={common.secondary}
        disabled={searchLocked || searchDisabled || !search.trim()}
      >
        Search
      </button>
    </form>
  )
}
