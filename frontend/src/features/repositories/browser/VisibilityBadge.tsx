import { Eye, EyeOff } from 'lucide-react'
import { clsx } from 'clsx'
import type { Visibility } from '../visibility/useVisibility'
import styles from './Browser.module.css'

/** What visitors of new links would get for one row of the file list while visibility is being edited. */
export function VisibilityBadge({
  visibility,
  path,
  type,
}: {
  visibility: Visibility
  path: string
  type: string
}) {
  if (type !== 'dir' && type !== 'file') return null
  const pending = visibility.changed(path, type === 'dir')
  if (type === 'dir') {
    const count = visibility.folderCount(path)
    if (!count) return null
    const text =
      count.selected === count.total
        ? 'All visible'
        : `${count.selected.toLocaleString()} of ${count.total.toLocaleString()} visible`
    const tone =
      count.selected === 0
        ? styles.badgeHidden
        : count.selected === count.total
          ? styles.badgeVisible
          : styles.badgePartial
    return (
      <span className={clsx(styles.visibilityBadge, pending ? styles.badgePending : tone)}>
        {count.selected === 0 ? <EyeOff size={13} /> : <Eye size={13} />}
        {text}
      </span>
    )
  }
  const state = visibility.state(path, false)
  if (state !== 'checked' && state !== 'unchecked') return null
  const visible = state === 'checked'
  return (
    <span
      className={clsx(
        styles.visibilityBadge,
        pending ? styles.badgePending : visible ? styles.badgeVisible : styles.badgeHidden,
      )}
    >
      {visible ? <Eye size={13} /> : <EyeOff size={13} />}
      {visible ? 'Visible' : 'Hidden'}
      {pending ? ' · unsaved' : ''}
    </span>
  )
}
