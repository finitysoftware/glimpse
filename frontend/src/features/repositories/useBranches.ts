import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query'
import { errorMessage } from '../../api/client'
import { loadBranches, repositoryKeys, retryBusy, type Branches } from './api'

/** Branches page by page; a new revision (Refresh) starts again from page 1 without the server cache. */
export function useBranches(endpoint: `/api/${string}`, revision = 0) {
  const queryClient = useQueryClient()
  const queryKey = repositoryKeys.branches(endpoint, revision)
  const branches = useInfiniteQuery({
    queryKey,
    queryFn: ({ pageParam, signal }) => loadBranches(endpoint, pageParam, revision > 0, signal),
    initialPageParam: 1,
    getNextPageParam: last => last.nextPage ?? undefined,
    ...retryBusy,
  })
  const pages = branches.data?.pages ?? []
  const last = pages.at(-1)
  return {
    // A failed page replaces the list with its error and Try again, like a failed first page.
    data:
      branches.isError || !last
        ? undefined
        : { ...last, items: [...new Set(pages.flatMap(page => page.items))] },
    error: errorMessage(branches.error, 'Unable to load branches.'),
    loading: branches.isPending || branches.isFetchingNextPage,
    loadMore: () => {
      if (branches.hasNextPage && !branches.isFetching) void branches.fetchNextPage()
    },
    retry: () => void queryClient.resetQueries({ queryKey, exact: true }),
  }
}

/** The branches a link would share: the owner's choice, or the default branch until they choose. */
export function chosenOrDefault(chosen: string[] | null, branches: Branches | undefined): string[] {
  return chosen ?? (branches ? [branches.defaultBranch] : [])
}

/** chosenOrDefault with the branches of one repository, from the same cached query as the list. */
export function useChosenBranches(endpoint: `/api/${string}`, chosen: string[] | null): string[] {
  return chosenOrDefault(chosen, useBranches(endpoint).data)
}
