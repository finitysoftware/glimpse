import { ApiError, put, request } from '../../api/client'
import { platformLabels, type Platform } from '../../api/platforms'
import type { Operation, VisibilityProfile } from './visibility/draft'

/**
 * The repository endpoints of the workspace and of access links. Previews take the endpoint of their
 * area (the owner's repository or the visitor's link), so the same views serve both.
 */

export interface Repository {
  provider: Platform
  id: number
  fullName: string
  owner: string
  name: string
  private: boolean
  visibility: 'private' | 'internal' | 'public'
  defaultBranch: string
  description: string
}

export interface Catalogue {
  items: Repository[]
  nextPage: number | null
}

export interface Branches {
  items: string[]
  nextPage: number | null
  defaultBranch: string
  allowHistory: boolean
}

export interface Preview {
  /** When the platform last confirmed access; '' for visitors. */
  checkedAt: string
  /** 'upstream' for visitors, who are never told which platform hosts the repository. */
  contentSource: 'cache' | 'github' | 'gitlab' | 'upstream'
  repository: {
    id: number
    fullName: string
    private: boolean
    defaultBranch: string
    provider?: Platform
    owner?: string
    name?: string
  }
  commitSha: string
  path: string
  kind: 'directory' | 'file' | 'history' | 'commit' | 'search'
  branch: string
  allowHistory: boolean
  /** The commit is older than the head of the branch. */
  historical: boolean
  /** Sizes are null where the platform does not report them (GitLab trees). */
  search?: {
    items: { name: string; path: string; type: string; size: number | null }[]
    page: number
    nextPage: number | null
    limited: boolean
  }
  /** filtered: a link branch that shares selected files; pages continue through nextCursor instead of page numbers. */
  history?: {
    items: CommitSummary[]
    page: number
    nextPage: number | null
    limited: boolean
    filtered?: boolean
    cursor?: string
    nextCursor?: string | null
    incomplete?: boolean
    largeCommitsSkipped?: boolean
  }
  detail?: CommitDetail
  entries?: { name: string; path: string; type: string; size: number | null }[]
  limited?: boolean
  file?: { path: string; commitSha: string; bytes: number; content: string }
}

export interface CommitSummary {
  sha: string
  message: string
  messageLimited: boolean
  author: string
  date: string | null
  parents: string[]
}

export interface ChangedFile {
  path: string
  previousPath: string | null
  status: string
  additions: number
  deletions: number
  patch: string | null
  patchState: 'complete' | 'incomplete' | 'unavailable' | 'limited' | 'restricted'
}

export interface CommitDetail {
  commit: CommitSummary
  files: ChangedFile[]
  page: number
  nextPage: number | null
  filesLimited: boolean
  stats: { additions: number; deletions: number; total: number }
}

type Endpoint = `/api/${string}`

/** The workspace endpoint of one repository: previews under /browse, visibility under /visibility, links under /shares. */
export const repositoryBase = {
  github: (owner: string, name: string): Endpoint =>
    `/api/workspace/repositories/${encodeURIComponent(owner)}/${encodeURIComponent(name)}`,
  gitlab: (projectId: string): Endpoint => `/api/workspace/gitlab/projects/${projectId}`,
}

export const repositoryKeys = {
  catalogues: ['catalogue'],
  catalogue: (platform: Platform, page: number) => ['catalogue', platform, page],
  branches: (endpoint: Endpoint, revision: number) => ['branches', endpoint, revision],
  /** Previews, folders and commits of one endpoint share the 'preview' prefix. */
  preview: (endpoint: Endpoint, query: object) => ['preview', endpoint, query],
  folder: (endpoint: Endpoint, sha: string, branch: string, path: string) => [
    'preview',
    endpoint,
    'folder',
    sha,
    branch,
    path,
  ],
  commit: (endpoint: Endpoint, branch: string, sha: string, page: number) => [
    'preview',
    endpoint,
    'commit',
    branch,
    sha,
    page,
  ],
  visibility: (endpoint: Endpoint, branch: string) => ['visibility', endpoint, branch],
}

/** One catalogue page of one platform; a timeout or a lost connection becomes a readable ApiError. */
export async function loadCatalogue(
  platform: Platform,
  page: number,
  signal: AbortSignal,
): Promise<Catalogue> {
  const controller = new AbortController()
  const abort = () => controller.abort()
  signal.addEventListener('abort', abort, { once: true })
  const timer = window.setTimeout(abort, 20000)
  try {
    return await request<Catalogue>(
      `/api/workspace/repositories?provider=${platform}&page=${page}`,
      {
        signal: controller.signal,
      },
    )
  } catch (reason) {
    if (reason instanceof ApiError || signal.aborted) throw reason
    throw controller.signal.aborted
      ? new ApiError(0, 'request_timeout', `${platformLabels[platform]} did not respond in time.`)
      : new ApiError(0, 'network_error', 'Unable to reach Glimpse. Check your connection.')
  } finally {
    window.clearTimeout(timer)
    signal.removeEventListener('abort', abort)
  }
}

const busy = ['github_busy', 'gitlab_busy', 'upstream_busy']

/**
 * Query options for preview requests: a repository check that is already running on the server is
 * tried once more after its short Retry-After (at most 3 seconds); every other failure is shown.
 */
export const retryBusy = {
  retry: (failures: number, reason: Error) =>
    failures < 1 &&
    reason instanceof ApiError &&
    busy.includes(reason.code) &&
    (reason.retryAfter ?? 2) <= 3,
  retryDelay: (_failures: number, reason: Error) =>
    (reason instanceof ApiError ? (reason.retryAfter ?? 2) : 2) * 1000,
}

/** One file, folder, branch or History request, with a 20 second deadline. */
export async function loadPreview<T = Preview>(
  endpoint: Endpoint,
  params: URLSearchParams,
  signal: AbortSignal,
): Promise<T> {
  const controller = new AbortController()
  const abort = () => controller.abort()
  if (signal.aborted) abort()
  signal.addEventListener('abort', abort, { once: true })
  const timeout = window.setTimeout(abort, 20000)
  try {
    return await request<T>(`${endpoint}?${params}`, { signal: controller.signal })
  } catch (reason) {
    if (controller.signal.aborted && !signal.aborted)
      throw new ApiError(504, 'preview_timeout', 'The preview took too long to load. Try again.')
    throw reason
  } finally {
    window.clearTimeout(timeout)
    signal.removeEventListener('abort', abort)
  }
}

export async function loadBranches(
  endpoint: Endpoint,
  page: number,
  refresh: boolean,
  signal: AbortSignal,
): Promise<Branches> {
  const data = await loadPreview<Branches>(
    endpoint,
    new URLSearchParams({ kind: 'branches', page: String(page), refresh: refresh ? '1' : '0' }),
    signal,
  )
  if (
    !Array.isArray(data.items) ||
    !data.items.every(item => typeof item === 'string') ||
    typeof data.defaultBranch !== 'string' ||
    typeof data.allowHistory !== 'boolean'
  )
    throw new ApiError(
      502,
      'invalid_branches',
      'Unable to read available branches. Try refreshing the preview.',
    )
  return data
}

export async function loadFolder(
  endpoint: Endpoint,
  sha: string,
  branch: string,
  path: string,
  signal: AbortSignal,
): Promise<Preview> {
  const params = new URLSearchParams({ path, kind: 'directory', sha, branch, refresh: '0' })
  const value = await loadPreview(endpoint, params, signal)
  if (value.commitSha !== sha || value.path !== path || value.kind !== 'directory')
    throw new ApiError(
      502,
      'invalid_tree_response',
      'The folder could not be verified. Refresh the preview.',
    )
  return value
}

/** One page of a commit's changed files; a commit never changes, so it is loaded once. */
export async function loadCommit(
  endpoint: Endpoint,
  branch: string,
  sha: string,
  page: number,
  signal: AbortSignal,
): Promise<CommitDetail> {
  const value = await loadPreview(
    endpoint,
    new URLSearchParams({ kind: 'commit', sha, branch, page: String(page) }),
    signal,
  )
  if (value.commitSha !== sha || !value.detail || value.detail.commit.sha !== sha)
    throw new ApiError(502, 'invalid_response', 'The server returned an unexpected commit.')
  return value.detail
}

export function loadVisibility(endpoint: Endpoint, branch: string, signal: AbortSignal) {
  return request<VisibilityProfile>(`${endpoint}?branch=${encodeURIComponent(branch)}`, {
    signal,
  })
}

/** The owner's operations since the loaded version; the server applies them to its own file list. */
export function saveVisibility(
  endpoint: Endpoint,
  change: {
    branch: string
    expectedVersion: number
    sourceSha: string
    mode: 'selected' | 'all'
    operations: Operation[]
  },
  csrfToken: string,
) {
  return put<VisibilityProfile>(endpoint, change, csrfToken)
}
