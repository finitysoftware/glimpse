import { post, put, request } from '../../../api/client'
import type { ConnectionState, Platform } from '../../../api/platforms'

export interface GitHubConnection {
  configured: boolean
  status: ConnectionState
  maskedToken: string | null
  credentialVersion: number
  githubLogin: string | null
  lastCheckedAt: string | null
  errorCode: string | null
}

export interface GitLabConnection {
  configured: boolean
  status: ConnectionState
  baseUrl: string | null
  defaultUrl: string
  maskedToken: string | null
  credentialVersion: number
  gitlabUsername: string | null
  tokenExpiresAt: string | null
  scopes: string[] | null
  lastCheckedAt: string | null
  errorCode: string | null
}

export type Connection = GitHubConnection | GitLabConnection

/** The account the saved token belongs to, as the platform reported it. */
export function accountOf(connection: Connection): string | null {
  return 'githubLogin' in connection ? connection.githubLogin : connection.gitlabUsername
}

export interface PlatformQuota {
  rateLimit: number | null
  rateRemaining: number | null
  rateReset: number | null
  rateCheckedAt: string | null
}

export interface PreviewCacheStatus {
  entries: number
  bytes: number
  maxBytes: number
  maxEntries: number
  hits: number
  misses: number
  upstreamCalls: number
  revalidations: number
  evictions: number
  providers: Record<Platform, PlatformQuota>
}

export const connectionKeys = {
  /** Both connections and the cache: checks, saved tokens and conflicts read all of them again. */
  all: ['connections'],
  platform: (platform: Platform) => ['connections', platform],
  previewCache: ['connections', 'preview-cache'],
}

export function loadConnection(platform: Platform, signal: AbortSignal) {
  return request<Connection>(`/api/workspace/connections/${platform}`, { signal })
}

export function loadPreviewCache(signal: AbortSignal) {
  return request<PreviewCacheStatus>('/api/workspace/preview-cache', { signal })
}

/** Saves and verifies a new token; GitLab also needs the address of its instance. */
export function saveConnection(
  platform: Platform,
  change: { baseUrl?: string; token: string; expectedVersion: number },
  csrfToken: string,
) {
  return put<Connection>(`/api/workspace/connections/${platform}`, change, csrfToken)
}

export function checkConnection(platform: Platform, csrfToken: string) {
  return post<Connection>(`/api/workspace/connections/${platform}/probe`, {}, csrfToken)
}

export function disconnectPlatform(platform: Platform, csrfToken: string) {
  return post<Connection>(`/api/workspace/connections/${platform}/disconnect`, {}, csrfToken)
}
