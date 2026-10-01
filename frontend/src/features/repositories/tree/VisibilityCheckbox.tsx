import { useEffect, useRef } from 'react'
import { clsx } from 'clsx'
import type { Visibility } from '../visibility/useVisibility'
import styles from './RepositoryTree.module.css'

/** Separate from the row button, so choosing visibility never opens a file or toggles a folder. The mixed state is exposed through the indeterminate property. */
export function VisibilityCheckbox({
  visibility,
  path,
  folder,
  label,
}: {
  visibility: Visibility
  path: string
  folder: boolean
  label: string
}) {
  const input = useRef<HTMLInputElement>(null)
  const state = visibility.state(path, folder)
  useEffect(() => {
    if (input.current) input.current.indeterminate = state === 'mixed'
  }, [state])
  const changed = visibility.changed(path, folder)
  return (
    <input
      ref={input}
      className={clsx(styles.check, changed && styles.checkPending)}
      title={changed ? 'Unsaved change' : undefined}
      type="checkbox"
      checked={state === 'checked'}
      aria-checked={state === 'mixed' ? 'mixed' : undefined}
      disabled={
        state === 'unavailable' ||
        visibility.mode === 'all' ||
        visibility.saving ||
        !visibility.profile
      }
      aria-label={`Visible to visitors: ${label}`}
      onChange={() => visibility.toggle(path, folder)}
    />
  )
}
