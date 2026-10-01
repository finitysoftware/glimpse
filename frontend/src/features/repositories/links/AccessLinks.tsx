import { useRef, useState, type Dispatch, type SetStateAction } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, ShieldOff } from 'lucide-react'
import { errorMessage } from '../../../api/client'
import type { Platform } from '../../../api/platforms'
import { deleteShare, linkKeys, loadShares, revokeShare, setSharing, type ShareLink } from './api'
import common from '../Repositories.module.css'
import { AccessLinkRow } from './AccessLinkRow'
import { CreateLinkForm, type LinkDraft } from './CreateLinkForm'
import { EditLinkForm } from './EditLinkForm'
import { ConfirmDialog } from '../../../components/Dialog'
import { ErrorMessage } from '../../../components/ErrorMessage'

/** Revoking a link and deleting a revoked one cannot be undone, so both wait for a confirmation. */
type Removal = { kind: 'revoke' | 'delete'; link: ShareLink }

/** The Access links tab: sharing on or off, a new link, editing one, and the list of links. */
export function AccessLinks({
  base,
  platform,
  csrfToken,
  draft,
  onDraftChange,
  onConfigure,
}: {
  base: `/api/${string}`
  platform: Platform
  csrfToken: string
  draft: LinkDraft
  onDraftChange: Dispatch<SetStateAction<LinkDraft>>
  onConfigure: (branch: string) => void
}) {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const shares = useQuery({
    queryKey: linkKeys.sharesPage(base, page),
    queryFn: ({ signal }) => loadShares(base, page, signal),
  })
  const list = shares.data
  const [editing, setEditing] = useState<ShareLink | null>(null)
  const [createdUrl, setCreatedUrl] = useState('')
  const [copyProblem, setCopyProblem] = useState('')
  const [removing, setRemoving] = useState<Removal | null>(null)
  // The removed link's buttons are gone after the list reloads, so focus returns to the list.
  const listHeading = useRef<HTMLHeadingElement>(null)
  // Every sharing change runs through here: one pending state, one error, then the first page again.
  const update = useMutation({
    mutationFn: (action: () => Promise<unknown>) => action(),
    onMutate: () => {
      setCreatedUrl('')
      setCopyProblem('')
    },
    // The change counts as done once the list shows it.
    onSuccess: () => {
      setPage(1)
      return queryClient.invalidateQueries({ queryKey: linkKeys.shares(base) })
    },
  })
  const run = update.mutate
  const pending = update.isPending
  const updateError = errorMessage(update.error, 'Unable to update sharing.')
  // An open confirmation shows the error of its own action.
  const error =
    (removing ? '' : updateError) ||
    copyProblem ||
    errorMessage(shares.error, 'Unable to load links.')

  function confirmRemoval(removal: Removal) {
    update.reset()
    setRemoving(removal)
  }

  function remove({ kind, link }: Removal) {
    update.mutate(() => (kind === 'revoke' ? revokeShare : deleteShare)(link.publicId, csrfToken), {
      onSuccess: () => {
        setRemoving(null)
        // A revoked link cannot be edited any more.
        setEditing(current => (current?.publicId === link.publicId ? null : current))
      },
    })
  }

  return (
    <>
      <ErrorMessage message={error} className={common.error} />
      {createdUrl && (
        <CreatedLink
          key={createdUrl}
          url={createdUrl}
          onCopyFailed={() => setCopyProblem('Select the link above and copy it manually.')}
          onDismiss={() => setCreatedUrl('')}
        />
      )}
      {shares.isPending && <p role="status">Loading access links…</p>}
      {list && (
        <>
          <section className={common.panel}>
            <div className={common.browserTop}>
              <div>
                <h2>Repository sharing</h2>
                <p>
                  {list.active
                    ? 'Only people with a valid link can open this repository.'
                    : 'All visitor links are currently disabled.'}
                </p>
              </div>
              <button
                className={common.secondary}
                disabled={pending}
                onClick={() => run(() => setSharing(base, !list.active, csrfToken))}
              >
                <ShieldOff size={15} />
                {list.active ? 'Disable sharing' : 'Enable sharing'}
              </button>
            </div>
          </section>
          <CreateLinkForm
            base={base}
            platform={platform}
            csrfToken={csrfToken}
            draft={draft}
            onDraftChange={onDraftChange}
            active={list.active}
            storageConfigured={list.storageConfigured}
            pending={pending}
            run={run}
            onCreated={setCreatedUrl}
            onConfigure={onConfigure}
          />
          {editing && (
            <EditLinkForm
              key={editing.publicId}
              base={base}
              link={editing}
              csrfToken={csrfToken}
              pending={pending}
              run={run}
              onClose={() => setEditing(null)}
              onConfigure={onConfigure}
            />
          )}
          <section className={common.panel}>
            <h2 ref={listHeading} tabIndex={-1}>
              Access links
            </h2>
            {list.items.length === 0 && (
              <p>No links yet. Create one when you are ready to share.</p>
            )}
            {list.items.map(link => (
              <AccessLinkRow
                // Rows start over whenever the list reloads, so no shown URL outlives a change.
                key={`${link.publicId}:${shares.dataUpdatedAt}`}
                link={link}
                active={list.active}
                storageConfigured={list.storageConfigured}
                pending={pending}
                csrfToken={csrfToken}
                onRevoke={() => confirmRemoval({ kind: 'revoke', link })}
                onEdit={() => setEditing(link)}
                onDelete={() => confirmRemoval({ kind: 'delete', link })}
              />
            ))}
            <div className={common.pagination}>
              <button
                className={common.secondary}
                disabled={pending || page === 1}
                onClick={() => setPage(previous => previous - 1)}
              >
                Previous
              </button>
              <span>Page {page}</span>
              <button
                className={common.secondary}
                disabled={pending || !list.nextPage}
                onClick={() => setPage(previous => previous + 1)}
              >
                Next
              </button>
            </div>
          </section>
        </>
      )}
      {removing && (
        <ConfirmDialog
          title={removing.kind === 'revoke' ? 'Revoke link' : 'Delete link'}
          danger
          busy={pending}
          error={updateError}
          confirmLabel={removing.kind === 'revoke' ? 'Revoke' : 'Delete'}
          fallbackFocus={listHeading}
          onCancel={() => setRemoving(null)}
          onConfirm={() => remove(removing)}
          message={
            removing.kind === 'revoke' ? (
              <>
                Revoke <strong>{removing.link.name}</strong>? Visitors lose access immediately and
                the URL can no longer be shown.
              </>
            ) : (
              <>
                Delete <strong>{removing.link.name}</strong>? It is removed from this list for good.
                This cannot be undone.
              </>
            )
          }
        />
      )}
    </>
  )
}

/** A new link's URL, shown once right after it was created. */
function CreatedLink({
  url,
  onCopyFailed,
  onDismiss,
}: {
  url: string
  onCopyFailed: () => void
  onDismiss: () => void
}) {
  const [copied, setCopied] = useState(false)
  return (
    <section className={common.created} aria-live="polite">
      <h2>Your access link is ready</h2>
      <p>Copy this link now, or use Show in the list to copy it again later.</p>
      <label>
        Access URL
        <input readOnly value={url} onFocus={event => event.target.select()} />
      </label>
      <div className={common.actions}>
        <button
          className={common.primary}
          onClick={() => {
            void navigator.clipboard
              .writeText(url)
              .then(() => setCopied(true))
              .catch(onCopyFailed)
          }}
        >
          <Copy size={15} />
          {copied ? 'Copied' : 'Copy link'}
        </button>
        <button className={common.secondary} onClick={onDismiss}>
          Dismiss link
        </button>
      </div>
    </section>
  )
}
