import { MutationCache, QueryCache, QueryClient } from '@tanstack/react-query'
import { ApiError } from './client'

declare module '@tanstack/react-query' {
  interface Register {
    queryMeta: {
      /** Hears every successful request of this query; the parent learns about new data without an effect. */
      onSuccess?: (data: never) => void
      /** Hears a request of this query that failed for good, after its retries. */
      onError?: (reason: unknown) => void
    }
  }
}

/** A 401 from Glimpse means the session of this area (workspace, admin panel or visitor) ended. */
export function isUnauthorized(reason: unknown): boolean {
  return reason instanceof ApiError && reason.status === 401
}

export function createQueryClient(onUnauthorized?: () => void): QueryClient {
  const onError = (reason: unknown) => {
    if (isUnauthorized(reason)) onUnauthorized?.()
  }
  return new QueryClient({
    queryCache: new QueryCache({
      onError: (reason, query) => {
        onError(reason)
        query.meta?.onError?.(reason)
      },
      // Each query declares the type its own callback expects, so its data arrives as that type.
      onSuccess: (data, query) => query.meta?.onSuccess?.(data as never),
    }),
    mutationCache: new MutationCache({ onError }),
    defaultOptions: {
      // Previews retry a busy platform once (retryBusy in repositories/api.ts), and GitHub and GitLab quotas rule out
      // loading everything again just because the window regained focus.
      queries: { retry: false, refetchOnWindowFocus: false },
    },
  })
}
