import { useId, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { clsx } from 'clsx'
import { ChevronRight, FileCode2, Folder, FolderOpen } from 'lucide-react'
import { loadFolder, repositoryKeys, retryBusy } from '../api'
import styles from './RepositoryTree.module.css'
import type { TreeProps } from './RepositoryTree'
import { VisibilityCheckbox } from './VisibilityCheckbox'

export function Directory({
  endpoint,
  snapshot,
  selectedPath,
  onSelect,
  onFailure,
  path,
  name,
  visible,
  visibility,
}: Omit<TreeProps, 'onClose' | 'onHide'> & { path: string; name: string; visible: boolean }) {
  const [expandedOverride, setExpanded] = useState<boolean | null>(null)
  const expanded =
    expandedOverride ??
    (path === '' || selectedPath === path || selectedPath.startsWith(path + '/'))
  const groupId = useId()
  const sha = snapshot.commitSha
  const branch = snapshot.branch
  // A folder at a fixed commit never changes, so it loads once, when it is first opened.
  const queryClient = useQueryClient()
  const folderKey = repositoryKeys.folder(endpoint, sha, branch, path)
  const folder = useQuery({
    queryKey: folderKey,
    queryFn: ({ signal }) => loadFolder(endpoint, sha, branch, path, signal),
    enabled: visible && expanded,
    initialData: snapshot.kind === 'directory' && snapshot.path === path ? snapshot : undefined,
    staleTime: Infinity,
    ...retryBusy,
    // A folder that cannot be loaded ends the preview with its error, like a failed check.
    meta: { onError: onFailure },
  })
  const listing = folder.data ?? null
  function toggle() {
    // Collapsing stops a read that is still running.
    if (expanded) void queryClient.cancelQueries({ queryKey: folderKey })
    setExpanded(!expanded)
  }

  const folderRow = (
    <button
      className={styles.row}
      type="button"
      title={path || name}
      aria-expanded={expanded}
      aria-controls={groupId}
      aria-current={selectedPath === path ? 'location' : undefined}
      onClick={toggle}
    >
      <ChevronRight className={expanded ? styles.expanded : ''} size={14} />
      {expanded ? <FolderOpen size={16} /> : <Folder size={16} />}
      <span>{name}</span>
    </button>
  )
  const count = visibility?.folderCount(path)
  return (
    <li>
      {visibility ? (
        <div className={styles.checkRow}>
          <VisibilityCheckbox
            visibility={visibility}
            path={path}
            folder
            label={path === '' ? 'all files' : `folder ${path}`}
          />
          {folderRow}
          {count && (
            <span
              className={styles.folderCount}
              title={`${count.selected.toLocaleString()} of ${count.total.toLocaleString()} files visible`}
            >
              {count.selected.toLocaleString()} / {count.total.toLocaleString()}
            </span>
          )}
        </div>
      ) : (
        folderRow
      )}
      <ul id={groupId} className={styles.children} hidden={!expanded}>
        {!listing && expanded && (
          <li className={styles.message} role="status">
            Loading folder…
          </li>
        )}
        {listing?.limited && (
          <li className={styles.warning}>Only the first 1,000 items are available.</li>
        )}
        {listing?.entries?.length === 0 && <li className={styles.message}>Empty folder</li>}
        {listing?.entries?.map(entry =>
          entry.type === 'dir' ? (
            <Directory
              key={entry.path}
              endpoint={endpoint}
              snapshot={snapshot}
              selectedPath={selectedPath}
              onSelect={onSelect}
              onFailure={onFailure}
              path={entry.path}
              name={entry.name}
              visible={visible && expanded}
              visibility={visibility}
            />
          ) : (
            <li key={entry.path} className={visibility ? styles.checkRow : undefined}>
              {visibility && (
                <VisibilityCheckbox
                  visibility={visibility}
                  path={entry.path}
                  folder={false}
                  label={`file ${entry.path}`}
                />
              )}
              <button
                className={clsx(styles.row, !visibility && styles.file)}
                type="button"
                title={entry.path}
                aria-current={selectedPath === entry.path ? 'page' : undefined}
                disabled={entry.type !== 'file'}
                onClick={() => onSelect(entry.path)}
              >
                <FileCode2 size={16} />
                <span>{entry.name}</span>
                {entry.type !== 'file' && <small>{entry.type}</small>}
              </button>
            </li>
          ),
        )}
      </ul>
    </li>
  )
}
