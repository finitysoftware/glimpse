import { FileCode2, Search } from 'lucide-react'
import { plural } from '../../../format'
import type { Visibility } from '../visibility/useVisibility'
import styles from './RepositoryTree.module.css'
import { VisibilityCheckbox } from './VisibilityCheckbox'

/** Filter, bulk actions and the Only visible switch for the visibility tree. */
export function VisibilityTools({
  visibility,
  filter,
  onFilter,
  onlyVisible,
  onOnlyVisible,
}: {
  visibility: Visibility
  filter: string
  onFilter: (value: string) => void
  onlyVisible: boolean
  onOnlyVisible: (value: boolean) => void
}) {
  const manifest = visibility.profile?.manifest
  const selecting = visibility.mode === 'selected' && !!manifest
  return (
    <div className={styles.tools}>
      <label
        className={styles.filter}
        title={manifest ? undefined : 'Filtering needs the complete file list of this branch.'}
      >
        <Search size={14} />
        <input
          type="search"
          aria-label="Filter files"
          placeholder="Filter files"
          value={filter}
          disabled={!manifest}
          onChange={event => onFilter(event.target.value)}
        />
      </label>
      {selecting && (
        <div className={styles.quick}>
          <button
            type="button"
            disabled={visibility.saving || visibility.state('', true) === 'checked'}
            onClick={() => visibility.apply('select', '', true)}
          >
            Select all
          </button>
          <button
            type="button"
            disabled={visibility.saving || visibility.count === 0}
            onClick={() => visibility.apply('deselect', '', true)}
          >
            Clear
          </button>
          <label className={styles.onlyVisible}>
            <input
              type="checkbox"
              role="switch"
              checked={onlyVisible}
              onChange={event => onOnlyVisible(event.target.checked)}
            />
            <span className={styles.miniTrack} aria-hidden="true" />
            Only visible
          </label>
        </div>
      )}
    </div>
  )
}

/** Files of the complete list that match the filter (or are visible), with the same checkboxes as the tree. */
export function MatchingFiles({
  visibility,
  filter,
  onlyVisible,
  selectedPath,
  onSelect,
}: {
  visibility: Visibility
  filter: string
  onlyVisible: boolean
  selectedPath: string
  onSelect: (path: string) => void
}) {
  const LIMIT = 200
  const needle = filter.trim().toLowerCase()
  const manifest = visibility.profile?.manifest
  const matches = (manifest ?? []).filter(
    path =>
      (!needle || path.toLowerCase().includes(needle)) &&
      (!onlyVisible || visibility.mode === 'all' || visibility.state(path, false) === 'checked'),
  )
  if (matches.length === 0)
    return (
      <p className={styles.message} role="status">
        {onlyVisible && !needle
          ? 'No file is visible to visitors yet.'
          : 'No files match this filter.'}
      </p>
    )
  return (
    <>
      <p className={styles.message} role="status">
        {plural(matches.length, 'file')}
        {matches.length > LIMIT ? `, showing the first ${LIMIT}` : ''}
      </p>
      <ul className={styles.root} aria-label="Matching files">
        {matches.slice(0, LIMIT).map(path => (
          <li key={path} className={styles.checkRow}>
            <VisibilityCheckbox
              visibility={visibility}
              path={path}
              folder={false}
              label={`file ${path}`}
            />
            <button
              className={styles.row}
              type="button"
              title={path}
              aria-current={selectedPath === path ? 'page' : undefined}
              onClick={() => onSelect(path)}
            >
              <FileCode2 size={16} />
              <span>{path}</span>
            </button>
          </li>
        ))}
      </ul>
    </>
  )
}
