import { post, request } from '../../api/client'
import type { Session } from '../auth/api'

/** A visitor's access link: its session, and the repository views under /api/shares/{id}. */

export const shareKeys = {
  session: (publicId: string) => ['share-session', publicId],
}

/** Opens the link: a first visit exchanges the secret from the URL fragment for a visitor session. */
export function openShare(publicId: string): Promise<Session> {
  const id = encodeURIComponent(publicId)
  const secret = window.location.hash.slice(1)
  if (!secret) return request<Session>(`/api/shares/${id}/session`)
  // The secret leaves the address bar before it is used.
  window.history.replaceState(
    window.history.state,
    '',
    window.location.pathname + window.location.search,
  )
  const exchange = () => post<Session>(`/api/shares/${id}/exchange`, { secret })
  // Serialize first-time grants across tabs so they share the same visitor cookie.
  return navigator.locks ? navigator.locks.request('glimpse-share-exchange', exchange) : exchange()
}

/** The link's repository views; the browser adds the path, branch and kind of each preview. */
export function shareBrowseEndpoint(publicId: string): `/api/${string}` {
  return `/api/shares/${encodeURIComponent(publicId)}/browse`
}

export function endVisit(csrfToken?: string) {
  return post('/api/visitor/logout', {}, csrfToken)
}
