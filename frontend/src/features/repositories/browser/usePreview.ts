import { useCallback, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ApiError } from '../../../api/client'
import { loadPreview, repositoryKeys, retryBusy, type Preview } from '../api'
import { useSecondsLeft } from '../useSecondsLeft'

/** What the preview shows: a folder, a file, search results or History, at one revision. */
export interface PreviewQuery {
  path: string
  kind: string
  sha: string
  ref: string
  branch: string
  q: string
  page: number
  cursor: string
  /** Refresh and Try again count up; Refresh also skips the server cache once. */
  revision: number
  refresh: boolean
}

function previewParams(query: PreviewQuery): URLSearchParams {
  const params = new URLSearchParams({
    path: query.path,
    kind: query.kind,
    sha: query.sha,
    ref: query.ref,
    branch: query.branch,
    q: query.q,
    refresh: query.refresh ? '1' : '0',
    page: String(query.page),
  })
  if (query.kind === 'history' && query.cursor) params.set('cursor', query.cursor)
  return params
}

/**
 * Loads one check of the preview. While the next check loads, the current tree stays on screen
 * (not during a refresh). A failure, from the check itself or reported by History or the tree,
 * replaces the preview with its message; Retry-After holds Try again until the service accepts checks.
 */
export function usePreview(
  endpoint: `/api/${string}`,
  query: PreviewQuery,
  initial: URLSearchParams,
  /** Hears the repository fields of every preview the server sends, e.g. for the page heading. */
  onRepository?: (repository: Preview['repository']) => void,
) {
  const [reportedFailure, setReportedFailure] = useState<{
    query: PreviewQuery
    reason: unknown
    at: number
  } | null>(null)
  const preview = useQuery({
    queryKey: repositoryKeys.preview(endpoint, query),
    queryFn: ({ signal }) => loadPreview(endpoint, previewParams(query), signal),
    ...retryBusy,
    placeholderData: previous => (query.refresh ? undefined : previous),
    meta: { onSuccess: (preview: Preview) => onRepository?.(preview.repository) },
  })
  const checked: Preview | undefined = preview.isPlaceholderData ? undefined : preview.data
  // A directly opened History URL, and History after Refresh, move to the revision the server checked.
  const moved = Boolean(
    checked &&
    query.kind === 'history' &&
    (initial.get('sha') !== checked.commitSha ||
      (initial.get('ref') ?? '') !== query.ref ||
      Number(initial.get('page') ?? 1) !== query.page ||
      (query.revision > 0 && initial.get('commit'))),
  )
  const reported = reportedFailure?.query === query ? reportedFailure : null
  const failure = preview.error
    ? { reason: preview.error as unknown, at: preview.errorUpdatedAt }
    : reported
  const error = !failure
    ? ''
    : failure.reason instanceof ApiError
      ? failure.reason.message
      : 'Unable to reach the service. Try again.'
  const snapshot = failure || moved ? undefined : preview.data
  const data = preview.isPlaceholderData ? undefined : snapshot
  const delay = failure?.reason instanceof ApiError ? failure.reason.retryAfter : null
  const cooldown = useSecondsLeft(
    failure && delay ? failure.at + delay * 1000 : 0,
    failure?.at ?? 0,
  )
  // Stable for one check: History and the tree hand it to their queries, which call it when a request fails for good.
  const fail = useCallback(
    (reason: unknown) => setReportedFailure({ query, reason, at: Date.now() }),
    [query],
  )

  return {
    /** This check's result, once it arrived. */
    data,
    /** The tree on screen: this check's, or the previous one while this check loads. */
    snapshot,
    /** The result that sends History to another revision. */
    moved: moved ? checked : undefined,
    loading: !failure && !data,
    error,
    cooldown,
    /** For History and the tree: a failure of theirs ends this check too. */
    fail,
  }
}
