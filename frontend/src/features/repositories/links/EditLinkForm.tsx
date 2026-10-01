import { useState, type FormEvent } from 'react'
import { updateShare, type ShareLink } from './api'
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

/** Changes the branches, history and file visibility of an existing link; its URL stays the same. */
export function EditLinkForm({
  base,
  link,
  csrfToken,
  pending,
  run,
  onClose,
  onConfigure,
}: {
  base: `/api/${string}`
  link: ShareLink
  csrfToken: string
  pending: boolean
  /** Runs a sharing change with the page's pending state and error. */
  run: (action: () => Promise<unknown>) => void
  /** After saving, and on Cancel. */
  onClose: () => void
  onConfigure: (branch: string) => void
}) {
  const [edited, setEdited] = useState<Permissions>({
    branches: link.branches,
    allowHistory: link.allowHistory,
  })
  const [choices, setChoices] = useState<Record<string, VisibilityChoice>>({})
  const branches = useChosenBranches(`${base}/browse`, edited.branches)
  const policies = link.policies
  const summaries = useBranchSummaries(base, branches)
  const entries = visibilityEntries(branches, choices, summaries, policies)

  function save(event: FormEvent) {
    event.preventDefault()
    run(async () => {
      if (!entries) throw new Error('Choose file visibility for every branch of the link.')
      await updateShare(
        link.publicId,
        { ...edited, branches, visibility: entries, expectedPolicyVersion: link.policyVersion },
        csrfToken,
      )
      onClose()
    })
  }

  return (
    <form className={common.panel} aria-label={`Edit access for ${link.name}`} onSubmit={save}>
      <h2>Edit access for {link.name}</h2>
      <p>
        The URL stays the same. Saved changes apply to open visitor sessions on their next request.
      </p>
      <BranchPermissions
        endpoint={`${base}/browse`}
        value={edited}
        onChange={setEdited}
        disabled={pending}
        scopes={branchScopes(branches, choices, summaries, policies)}
      />
      {branches.length > 0 && (
        <LinkVisibility
          branches={branches}
          summaries={summaries}
          choices={choices}
          onChange={setChoices}
          existing={link.policies}
          disabled={pending}
          onConfigure={onConfigure}
          allowHistory={edited.allowHistory}
        />
      )}
      <div className={common.actions}>
        <button className={common.primary} disabled={pending || !branches.length || !entries}>
          Save access
        </button>
        <button type="button" className={common.secondary} disabled={pending} onClick={onClose}>
          Cancel
        </button>
      </div>
    </form>
  )
}
