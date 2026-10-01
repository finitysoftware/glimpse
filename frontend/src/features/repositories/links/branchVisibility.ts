import { useQueries } from '@tanstack/react-query'
import { errorMessage } from '../../../api/client'
import {
  linkKeys,
  loadBranchSummary,
  type BranchSummary,
  type LinkPolicy,
  type VisibilityEntry,
} from './api'
import { plural } from '../../../format'

export type VisibilityChoice = 'saved' | 'all' | 'keep'
/** What visitors of one branch would see: some selected files or the whole branch. */
export type BranchScope = { mode: 'selected'; count: number } | { mode: 'all' }
export type Summaries = Record<string, { summary?: BranchSummary; error?: string }>

/**
 * Saved visibility of each branch, without the file manifest: exactly what a link would copy now.
 * Empty until every branch answered, so a link is never built from part of its branches.
 * Access links forgets them when it closes: rules saved on the Files tab meanwhile are read again, however the owner
 * returns (the tab, Back or Forward), and a link is never built from an older version.
 */
export function useBranchSummaries(base: `/api/${string}`, branches: string[]): Summaries {
  return useQueries({
    queries: branches.map(branch => ({
      queryKey: linkKeys.branchSummary(base, branch),
      queryFn: ({ signal }: { signal: AbortSignal }) => loadBranchSummary(base, branch, signal),
      gcTime: 0,
    })),
    combine: results =>
      results.some(result => result.isPending)
        ? {}
        : Object.fromEntries(
            results.map((result, index) => [
              branches[index]!,
              result.isSuccess
                ? { summary: result.data }
                : { error: errorMessage(result.error, 'Unable to read file visibility.') },
            ]),
          ),
  })
}

export const usable = (summary?: BranchSummary) =>
  summary?.mode === 'all' || (summary?.mode === 'selected' && summary.selectedCount > 0)

/** The choice in effect for a branch: explicit, or a safe default that never opens all files silently. */
export function effectiveChoice(
  branch: string,
  choices: Record<string, VisibilityChoice>,
  summaries: Summaries,
  existing?: LinkPolicy[],
): VisibilityChoice | null {
  const choice = choices[branch]
  if (choice) return choice
  if (existing?.some(policy => policy.branch === branch)) return 'keep'
  return usable(summaries[branch]?.summary) ? 'saved' : null
}

export function visibilityEntries(
  branches: string[],
  choices: Record<string, VisibilityChoice>,
  summaries: Summaries,
  existing?: LinkPolicy[],
): VisibilityEntry[] | null {
  const entries: VisibilityEntry[] = []
  for (const branch of branches) {
    const choice = effectiveChoice(branch, choices, summaries, existing)
    const summary = summaries[branch]?.summary
    if (choice === 'all') entries.push({ branch, allFiles: true })
    else if (choice === 'keep' && existing?.some(policy => policy.branch === branch))
      entries.push({ branch, keep: true })
    else if (choice === 'saved' && summary && usable(summary))
      entries.push({ branch, profileVersion: summary.version })
    else return null
  }
  return entries
}

/** The files each branch would share with the current choices; NULL while a branch still needs a choice.
 * With history allowed, a branch of selected files shows only their changes, without commit messages. */
export function branchScopes(
  branches: string[],
  choices: Record<string, VisibilityChoice>,
  summaries: Summaries,
  existing?: LinkPolicy[],
): Record<string, BranchScope | null> {
  return Object.fromEntries(
    branches.map(branch => [
      branch,
      branchScope(
        effectiveChoice(branch, choices, summaries, existing),
        summaries[branch]?.summary,
        existing?.find(policy => policy.branch === branch),
      ),
    ]),
  )
}

/** The files one branch would share with a choice: its saved rules, the link's current rules or everything. */
export function branchScope(
  choice: VisibilityChoice | null,
  summary: BranchSummary | undefined,
  current: LinkPolicy | undefined,
): BranchScope | null {
  if (choice === 'all') return { mode: 'all' }
  const rules = choice === 'saved' ? summary : choice === 'keep' ? current : undefined
  if (rules?.mode === 'all') return { mode: 'all' }
  if (rules?.mode === 'selected') return { mode: 'selected', count: rules.selectedCount }
  return null
}

export function describePolicy(policy: { mode: string | null; selectedCount: number }): string {
  if (policy.mode === 'all') return 'All files in this branch'
  if (policy.mode !== 'selected') return 'Not configured'
  if (policy.selectedCount === 0) return 'No files selected'
  return `Selected files only · ${plural(policy.selectedCount, 'file')}`
}
