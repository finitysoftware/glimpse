import type { ReactNode } from 'react'
import styles from './PageHeading.module.css'

/** Eyebrow, title and one line of description at the top of a page, with optional actions beside it. */
export function PageHeading({
  eyebrow,
  title,
  children,
  actions,
}: {
  eyebrow: string
  title: ReactNode
  children?: ReactNode
  actions?: ReactNode
}) {
  return (
    <div className={styles.heading}>
      <div>
        <p className={styles.eyebrow}>{eyebrow}</p>
        <h1 tabIndex={-1}>{title}</h1>
        {children && <p>{children}</p>}
      </div>
      {actions}
    </div>
  )
}
