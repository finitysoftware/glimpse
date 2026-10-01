import { createContext, useContext } from 'react'
import type { Platform } from '../../api/platforms'
import type { ConnectionSummary, Session } from './api'

export interface WorkspaceSession {
  session: Session
  /** Ends the private workspace in this tab after the server rejected the Glimpse session. */
  expire: () => void
  /** Updates a platform's saved state after Connections changed it; dependent views remount with the new credential version. */
  updateConnection: (platform: Platform, summary: ConnectionSummary) => void
  /** Takes over the renewed session after the password changed (new CSRF token, cleared password requirement). */
  replace: (session: Session) => void
}

export const WorkspaceSessionContext = createContext<WorkspaceSession | null>(null)

/**
 * Sign out is no navigation, so the router's blocker never holds it. A page with unsaved work holds it here instead:
 * signing out then asks that page, which signs out once the work was saved or discarded.
 */
export class SignOutHold {
  private ask: ((signOut: () => void) => void) | null = null

  /** Holds sign out while the page has unsaved work; the returned function lets it go again. */
  hold(ask: (signOut: () => void) => void): () => void {
    this.ask = ask
    return () => {
      if (this.ask === ask) this.ask = null
    }
  }

  /** Signs out right away, or hands the sign out to the page that holds it. */
  signOut(signOut: () => void) {
    if (this.ask) this.ask(signOut)
    else signOut()
  }
}

/** The workspace's hold; pages outside the workspace (a visitor's link) have none. */
export const SignOutHoldContext = createContext<SignOutHold | null>(null)

export function useWorkspaceSession(): WorkspaceSession {
  const value = useContext(WorkspaceSessionContext)
  if (value === null) throw new Error('Workspace views need a signed-in session.')
  return value
}

/** Only local workspace paths are accepted after sign-in; anything else falls back to the repositories page. */
export function safeReturnTo(value: string | null): string {
  if (value === null || !value.startsWith('/app') || value.startsWith('//')) return '/app'
  try {
    const url = new URL(value, window.location.origin)
    if (url.origin !== window.location.origin || !/^\/app(?:\/|$)/.test(url.pathname)) return '/app'
    return url.pathname + url.search + url.hash
  } catch {
    return '/app'
  }
}
