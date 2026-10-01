import type { Dispatch, FormEvent, SetStateAction } from 'react'
import { platformLabels, type Platform } from '../../../api/platforms'
import { createShare } from './api'
import common from '../Repositories.module.css'
import { BranchPermissions, type Permissions } from './BranchPermissions'
import {
  branchScopes,
  useBranchSummaries,
  visibilityEntries,
  type VisibilityChoice,
} from './branchVisibility'
import { LinkVisibility } from './LinkVisibility'
import { useChosenBranches } from '../useBranches'

/** What the owner has chosen so far for a new link; it survives a visit to the Files tab. */
export interface LinkDraft {
  name: string
  days: string
  permissions: Permissions
  choices: Record<string, VisibilityChoice>
}

export function CreateLinkForm({
  base,
  platform,
  csrfToken,
  draft,
  onDraftChange,
  active,
  storageConfigured,
  pending,
  run,
  onCreated,
  onConfigure,
}: {
  base: `/api/${string}`
  platform: Platform
  csrfToken: string
  draft: LinkDraft
  onDraftChange: Dispatch<SetStateAction<LinkDraft>>
  active: boolean
  storageConfigured: boolean
  pending: boolean
  /** Runs a sharing change with the page's pending state and error. */
  run: (action: () => Promise<unknown>) => void
  onCreated: (url: string) => void
  onConfigure: (branch: string) => void
}) {
  const branches = useChosenBranches(`${base}/browse`, draft.permissions.branches)
  const summaries = useBranchSummaries(base, branches)
  const entries = visibilityEntries(branches, draft.choices, summaries)
  const change = (values: Partial<LinkDraft>) =>
    onDraftChange(previous => ({ ...previous, ...values }))

  function create(event: FormEvent) {
    event.preventDefault()
    run(async () => {
      if (!entries) throw new Error('Choose file visibility for every branch of the link.')
      const response = await createShare(
        base,
        {
          name: draft.name,
          expiresInDays: draft.days === 'never' ? null : Number(draft.days),
          ...draft.permissions,
          branches,
          visibility: entries,
        },
        csrfToken,
      )
      onCreated(response.url)
      change({ name: '', choices: {} })
    })
  }

  return (
    <form className={common.panel} onSubmit={create}>
      <h2>Create access link</h2>
      <p>The link opens this repository without a {platformLabels[platform]} account.</p>
      {!storageConfigured && (
        <p role="status" className={common.notice}>
          Access URL storage needs setup on the server. Configure the private share encryption key
          to create and show links.
        </p>
      )}
      <div className={common.createForm}>
        <label>
          Link name
          <input
            required
            maxLength={160}
            value={draft.name}
            onChange={event => change({ name: event.target.value })}
            placeholder="Client review"
            disabled={pending}
          />
        </label>
        <label>
          Expires
          <select
            value={draft.days}
            onChange={event => change({ days: event.target.value })}
            disabled={pending}
          >
            <option value="1">In 1 day</option>
            <option value="7">In 7 days</option>
            <option value="30">In 30 days</option>
            <option value="never">No expiry</option>
          </select>
        </label>
        <button
          className={common.primary}
          disabled={pending || !active || !storageConfigured || !branches.length || !entries}
        >
          {pending ? 'Saving…' : 'Create link'}
        </button>
      </div>
      <BranchPermissions
        endpoint={`${base}/browse`}
        value={draft.permissions}
        onChange={permissions => change({ permissions })}
        disabled={pending}
        scopes={branchScopes(branches, draft.choices, summaries)}
      />
      {branches.length > 0 && (
        <LinkVisibility
          branches={branches}
          summaries={summaries}
          choices={draft.choices}
          onChange={choices => change({ choices })}
          disabled={pending}
          onConfigure={onConfigure}
          allowHistory={draft.permissions.allowHistory}
        />
      )}
    </form>
  )
}
