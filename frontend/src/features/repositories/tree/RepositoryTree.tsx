import { useId, useState } from 'react'
import { Dialog as Modal } from 'radix-ui'
import { clsx } from 'clsx'
import { X } from 'lucide-react'
import type { Preview } from '../api'
import { useMediaQuery } from '../../../components/useMediaQuery'
import { useReturnFocus } from '../../../components/useReturnFocus'
import type { Visibility } from '../visibility/useVisibility'
import styles from './RepositoryTree.module.css'
import { Directory } from './Directory'
import { MatchingFiles, VisibilityTools } from './VisibilityTools'

export interface TreeProps {
  endpoint: `/api/${string}`
  snapshot: Preview
  selectedPath: string
  onSelect: (path: string) => void
  /** Close tree, Escape or a click outside the drawer; while editing, it closes editing too. */
  onClose: () => void
  /** Puts the drawer away after a file opens on a phone; editing stays open. */
  onHide: () => void
  onFailure: (reason: unknown) => void
  /** Editing mode: a separate checkbox per file and folder decides what visitors of new links may open. */
  visibility?: Visibility
}

export function RepositoryTree(props: TreeProps) {
  const mobile = useMediaQuery('(max-width: 760px)')
  const headingId = useId()
  const returnFocus = useReturnFocus()

  const [filter, setFilter] = useState('')
  const [onlyVisible, setOnlyVisible] = useState(false)
  const visibility = props.visibility
  const select = (path: string) => {
    props.onSelect(path)
    if (mobile) props.onHide()
  }
  const flat =
    visibility !== undefined &&
    (filter.trim() !== '' || (onlyVisible && visibility.mode === 'selected'))
  const title = visibility ? 'Visible to visitors' : 'Files'
  const contents = (
    <>
      <div className={styles.heading}>
        {/* On phones the tree is a Radix dialog, which is labelled by its own Title. */}
        {mobile ? <Modal.Title>{title}</Modal.Title> : <h2 id={headingId}>{title}</h2>}
        {visibility && (
          <span
            className={clsx(styles.selectionCount, visibility.dirty && styles.selectionPending)}
          >
            {visibility.mode === 'all' ? 'All files' : `${visibility.count} selected`}
            {visibility.dirty ? ' · unsaved' : ''}
          </span>
        )}
        <button type="button" onClick={props.onClose} aria-label="Close tree" title="Close tree">
          <X size={16} />
        </button>
      </div>
      {visibility && (
        <VisibilityTools
          visibility={visibility}
          filter={filter}
          onFilter={setFilter}
          onlyVisible={onlyVisible}
          onOnlyVisible={setOnlyVisible}
        />
      )}
      <nav className={styles.navigation} aria-label="Project files">
        {flat ? (
          <MatchingFiles
            visibility={visibility}
            filter={filter}
            onlyVisible={onlyVisible}
            selectedPath={props.selectedPath}
            onSelect={select}
          />
        ) : (
          <ul className={styles.root}>
            <Directory
              {...props}
              path=""
              name={props.snapshot.repository.fullName.split('/').at(-1) || 'Repository'}
              visible
              onSelect={select}
            />
          </ul>
        )}
      </nav>
    </>
  )
  // On phones the tree is a drawer: Radix keeps focus inside, closes it on Escape or a click
  // outside, locks page scroll and returns focus to the Tree button.
  return mobile ? (
    <Modal.Root
      open
      onOpenChange={open => {
        if (!open) props.onClose()
      }}
    >
      <Modal.Portal>
        <Modal.Overlay className={styles.overlay} />
        <Modal.Content
          className={styles.drawer}
          aria-modal="true"
          aria-describedby={undefined}
          onCloseAutoFocus={returnFocus}
        >
          {contents}
        </Modal.Content>
      </Modal.Portal>
    </Modal.Root>
  ) : (
    <aside className={styles.panel} aria-labelledby={headingId}>
      {contents}
    </aside>
  )
}
