import { post, request } from '../../../api/client'

/** The owner's access links of one repository; base is the repository's workspace endpoint. */

export interface LinkPolicy {
  branch: string
  mode: 'selected' | 'all'
  selectedCount: number
  profileVersion: number | null
}

export interface ShareLink {
  publicId: string
  name: string
  createdAt: string
  expiresAt: string | null
  status: 'active' | 'expired' | 'revoked'
  branches: string[]
  allowHistory: boolean
  policyVersion: number
  policies: LinkPolicy[]
}

export interface ShareList {
  items: ShareLink[]
  active: boolean
  nextPage: number | null
  storageConfigured: boolean
}

/** Saved visibility of one branch, without the file manifest: exactly what a link would copy now. */
export interface BranchSummary {
  branch: string
  mode: 'selected' | 'all' | null
  version: number
  selectedCount: number
  preview: string[]
}

/** How a link takes each branch's files: a saved profile version, all files, or its current rules. */
export type VisibilityEntry =
  | { branch: string; profileVersion: number }
  | { branch: string; allFiles: true }
  | { branch: string; keep: true }

type Base = `/api/${string}`

export const linkKeys = {
  shares: (base: Base) => ['shares', base],
  sharesPage: (base: Base, page: number) => ['shares', base, page],
  branchSummary: (base: Base, branch: string) => ['branch-summary', base, branch],
}

export function loadShares(base: Base, page: number, signal: AbortSignal) {
  return request<ShareList>(`${base}/shares?page=${page}`, { signal })
}

export function loadBranchSummary(base: Base, branch: string, signal: AbortSignal) {
  return request<BranchSummary>(
    `${base}/visibility?branch=${encodeURIComponent(branch)}&summary=1`,
    { signal },
  )
}

/** Turns all visitor links of the repository on or off. */
export function setSharing(base: Base, active: boolean, csrfToken: string) {
  return post(`${base}/availability`, { active }, csrfToken)
}

export function createShare(
  base: Base,
  link: {
    name: string
    expiresInDays: number | null
    allowHistory: boolean
    branches: string[]
    visibility: VisibilityEntry[]
  },
  csrfToken: string,
) {
  return post<{ url: string }>(`${base}/shares`, link, csrfToken)
}

export function updateShare(
  publicId: string,
  change: {
    allowHistory: boolean
    branches: string[]
    visibility: VisibilityEntry[]
    expectedPolicyVersion: number
  },
  csrfToken: string,
) {
  return post(`/api/workspace/shares/${publicId}/permissions`, change, csrfToken)
}

export function revealShare(publicId: string, csrfToken: string) {
  return post<{ url: string }>(`/api/workspace/shares/${publicId}/reveal`, {}, csrfToken)
}

export function revokeShare(publicId: string, csrfToken: string) {
  return post(`/api/workspace/shares/${publicId}/revoke`, {}, csrfToken)
}

export function deleteShare(publicId: string, csrfToken: string) {
  return post(`/api/workspace/shares/${publicId}/delete`, {}, csrfToken)
}
