import type { ReactNode } from 'react'
import common from '../styles/app.module.css'

/** A page that only says what is loading, e.g. while the session is checked. */
export function StatusPage({ children }: { children: ReactNode }) {
  return (
    <main className={common.loginWrap}>
      <p role="status">{children}</p>
    </main>
  )
}
