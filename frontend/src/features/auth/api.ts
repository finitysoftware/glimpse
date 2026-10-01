import { ApiError, post, request } from '../../api/client'
import type { ConnectionState } from '../../api/platforms'
import { isUnauthorized } from '../../api/queries'

export interface ConnectionSummary {
  configured: boolean
  status: ConnectionState
  credentialVersion: number
}

export interface Session {
  csrfToken: string
  user: { id: number; username: string }
  github: ConnectionSummary
  gitlab: ConnectionSummary
  passwordChangeRequired: boolean
}

/** The sign-in endpoints of one area; the admin panel has its own in admin/api.ts. */
export interface AuthEndpoints {
  session: `/api/${string}`
  login: `/api/${string}`
  logout: `/api/${string}`
  password: `/api/${string}`
}

export const workspaceAuth: AuthEndpoints = {
  session: '/api/auth/session',
  login: '/api/auth/login',
  logout: '/api/auth/logout',
  password: '/api/auth/password',
}

export const workspaceSessionKey = ['workspace-session']

/** The signed-in session of the workspace or the admin panel, or null when nobody is signed in there. */
export async function loadSession<T>(
  path: `/api/${string}`,
  signal: AbortSignal,
): Promise<T | null> {
  try {
    return await request<T>(path, { signal })
  } catch (reason) {
    if (isUnauthorized(reason)) return null
    throw reason
  }
}

/** Why the session could not be loaded: maintenance and an address that serves shared links only explain themselves. */
export function sessionError(reason: unknown): string {
  if (!reason) return ''
  if (reason instanceof ApiError && ['maintenance', 'host_rejected'].includes(reason.code))
    return reason.message
  return 'Unable to reach the service. You can try signing in again.'
}

export function signIn<T>(endpoint: `/api/${string}`, username: string, password: string) {
  return post<T>(endpoint, { username, password })
}

export function endSession(endpoint: `/api/${string}`, csrfToken?: string) {
  return post(endpoint, {}, csrfToken)
}

/** The server answers with the renewed session. */
export function changePassword<T>(
  endpoint: `/api/${string}`,
  currentPassword: string,
  newPassword: string,
  csrfToken: string,
) {
  return post<T>(endpoint, { currentPassword, newPassword }, csrfToken)
}
