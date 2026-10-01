import { patch, post, put, request } from '../../api/client'
import type { ConnectionState, Platform } from '../../api/platforms'
import type { AuthEndpoints } from '../auth/api'

export interface AdminIdentity {
  id: number
  username: string
  firstName: string | null
  lastName: string | null
  email: string | null
}
export interface AdminSession {
  admin: AdminIdentity
  csrfToken: string
  passwordChangeRequired: boolean
}

export interface AdminUser {
  id: number
  username: string
  firstName: string | null
  lastName: string | null
  email: string | null
  role: 'user' | 'admin'
  active: boolean
  version: number
  github: { status: ConnectionState; login: string | null }
  gitlab: { status: ConnectionState; username: string | null; baseUrl: string | null }
  links: { active: number; total: number }
  lastSignInAt: string | null
  createdAt: string | null
}

export interface AdminUserDetail extends AdminUser {
  mustChangePassword: boolean
  sessions: { workspace: number; admin: number }
  repositories: { active: number; total: number }
  linkStates: {
    active: number
    expired: number
    revoked: number
    selectedFiles: number
    history: number
    opens30Days: number
  }
  connection: {
    configured: boolean
    status: ConnectionState
    login: string | null
    lastCheckedAt: string | null
    errorCode: string | null
    credentialVersion: number
  }
  gitlabConnection: {
    configured: boolean
    status: ConnectionState
    username: string | null
    baseUrl: string | null
    tokenExpiresAt: string | null
    lastCheckedAt: string | null
    errorCode: string | null
    credentialVersion: number
  }
  cache: { entries: number; bytes: number; upstreamCalls: number }
}

export interface Page<T> {
  items: T[]
  page: number
  nextPage: number | null
  total: number
}

export interface AdminShare {
  publicId: string
  name: string
  owner: { id: number; username: string; firstName: string | null; lastName: string | null }
  repository: {
    provider: 'github' | 'gitlab'
    instance: string
    owner: string
    name: string
    active: boolean
  }
  status: 'active' | 'expired' | 'revoked'
  createdAt: string | null
  expiresAt: string | null
  branches: string[]
  allowHistory: boolean
  policies: {
    branch: string
    mode: 'all' | 'selected'
    selectedCount: number
    profileVersion: number | null
  }[]
}

export interface Dashboard {
  generatedAt: string
  users: {
    total: number
    active: number
    disabled: number
    admins: number
    signedInLast7Days: number
    withoutGitHub: number
    withoutGitLab: number
  }
  github: {
    connected: number
    notVerified: number
    credentialsRejected: number
    accessRestricted: number
    temporarilyUnavailable: number
    notConfigured: number
  }
  gitlab: {
    connected: number
    notVerified: number
    credentialsRejected: number
    accessRestricted: number
    temporarilyUnavailable: number
    notConfigured: number
    expiringSoon: number
  }
  links: {
    active: number
    expired: number
    revoked: number
    selectedFiles: number
    history: number
  }
  visitors: { activeSessions: number; opensLast7Days: number; opensLast30Days: number }
  cache: {
    bytes: number
    maxBytes: number
    entries: number
    upstreamCalls: number
    revalidations: number
    evictions: number
    usersInCooldown: number
  }
  system: {
    phpVersion: string
    databaseVersion: string | null
    environment: string
    maintenance: boolean
    migrations: { applied: number; total: number; latest: string | null; pending: string[] }
    workspaceOrigins: string[]
    sharePublicOrigin: string
    shareKeyConfigured: boolean
    githubTokenKeyId: string | null
  }
  series: { day: string; workspaceLogins: number; linkOpens: number; linksCreated: number }[]
}

export const adminAuth: AuthEndpoints = {
  session: '/api/admin/auth/session',
  login: '/api/admin/auth/login',
  logout: '/api/admin/auth/logout',
  password: '/api/admin/auth/password',
}

/** The Users page: search, filters, sort and page, as the URL of the list sends them. */
export interface UserQuery {
  q: string
  status: string
  role: string
  github: string
  gitlab: string
  sort: string
  page: number
}

export interface LinkQuery {
  user: string
  status: string
  q: string
  page: number
}

export const adminKeys = {
  session: ['admin-session'],
  dashboard: ['admin-dashboard'],
  users: ['admin-users'],
  usersPage: (query: UserQuery) => ['admin-users', query],
  links: ['admin-links'],
  linksPage: (query: LinkQuery) => ['admin-links', query],
}

export function loadDashboard(signal: AbortSignal) {
  return request<Dashboard>('/api/admin/dashboard', { signal })
}

export function loadUsers(query: UserQuery, signal: AbortSignal) {
  const params = new URLSearchParams({ page: String(query.page) })
  for (const key of ['q', 'status', 'role', 'github', 'gitlab', 'sort'] as const)
    if (query[key]) params.set(key, query[key])
  return request<Page<AdminUser>>(`/api/admin/users?${params}`, { signal })
}

export function loadUser(id: number) {
  return request<AdminUserDetail>(`/api/admin/users/${id}`)
}

export interface NewUser {
  firstName: string
  lastName: string
  email: string
  username: string
  password: string
  requirePasswordChange: boolean
  role: 'user' | 'admin'
  githubToken?: string
  gitlabUrl?: string
  gitlabToken?: string
}

export function createUser(account: NewUser, csrfToken: string) {
  return post<AdminUserDetail>('/api/admin/users', account, csrfToken)
}

/** Only the changed fields; the version detects edits made by someone else in the meantime. */
export function updateUser(
  id: number,
  changes: { expectedVersion: number } & Record<string, unknown>,
  csrfToken: string,
) {
  return patch<AdminUserDetail>(`/api/admin/users/${id}`, changes, csrfToken)
}

export function replaceGitHubToken(
  id: number,
  token: string,
  expectedVersion: number,
  csrfToken: string,
) {
  return put<AdminUserDetail>(
    `/api/admin/users/${id}/github/token`,
    { token, expectedVersion },
    csrfToken,
  )
}

/** A GitLab token is always saved together with the URL of its instance. */
export function replaceGitLabConnection(
  id: number,
  baseUrl: string,
  token: string,
  expectedVersion: number,
  csrfToken: string,
) {
  return put<AdminUserDetail>(
    `/api/admin/users/${id}/gitlab`,
    { baseUrl, token, expectedVersion },
    csrfToken,
  )
}

export function revealUserToken(id: number, platform: Platform, csrfToken: string) {
  return post<{ token: string | null; baseUrl?: string | null }>(
    `/api/admin/users/${id}/${platform}/token/reveal`,
    {},
    csrfToken,
  )
}

export function removeUserToken(id: number, platform: Platform, csrfToken: string) {
  return post<AdminUserDetail>(
    platform === 'github'
      ? `/api/admin/users/${id}/github/token/remove`
      : `/api/admin/users/${id}/gitlab/remove`,
    {},
    csrfToken,
  )
}

export function deleteUser(user: AdminUser, csrfToken: string) {
  return post(`/api/admin/users/${user.id}/delete`, { expectedVersion: user.version }, csrfToken)
}

/** Ends every workspace session of the account. */
export function signOutUser(id: number, csrfToken: string) {
  return post(`/api/admin/users/${id}/sessions/revoke`, {}, csrfToken)
}

export function loadLinks(query: LinkQuery, signal: AbortSignal) {
  const params = new URLSearchParams({ page: String(query.page) })
  if (query.user) params.set('user', query.user)
  if (query.status) params.set('status', query.status)
  if (query.q) params.set('q', query.q)
  return request<Page<AdminShare>>(`/api/admin/shares?${params}`, { signal })
}

export function revealLink(publicId: string, csrfToken: string) {
  return post<{ url: string }>(`/api/admin/shares/${publicId}/reveal`, {}, csrfToken)
}

export function revokeLink(publicId: string, csrfToken: string) {
  return post(`/api/admin/shares/${publicId}/revoke`, {}, csrfToken)
}
