import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Search, X } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { errorMessage } from '../../api/client'
import common from '../../styles/app.module.css'
import styles from './Admin.module.css'
import { adminKeys, loadLinks, revealLink, revokeLink, type AdminShare } from './api'
import { displayName } from './format'
import { ConfirmDialog } from '../../components/Dialog'
import { useAdminSession } from './session'
import { PageHeading } from '../../components/PageHeading'
import { PageTitle } from '../../components/PageTitle'
import { ErrorMessage } from '../../components/ErrorMessage'
import { LinkRow } from './LinkRow'
import { Pager } from './Pager'

export function Links() {
  const { session } = useAdminSession()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const user = params.get('user') ?? ''
  const status = params.get('status') ?? ''
  const q = params.get('q') ?? ''
  const page = Math.max(1, Number(params.get('page') ?? 1) || 1)
  const [search, setSearch] = useState(q)
  // Back and Forward change the search in the address; the field follows it.
  const [shown, setShown] = useState(q)
  if (q !== shown) {
    setShown(q)
    setSearch(q)
  }
  const [urls, setUrls] = useState<Record<string, string>>({})
  const [revoking, setRevoking] = useState<AdminShare | null>(null)
  const filters = { user, status, q, page }
  const links = useQuery({
    queryKey: adminKeys.linksPage(filters),
    queryFn: ({ signal }) => loadLinks(filters, signal),
  })
  const reveal = useMutation({
    mutationFn: (link: AdminShare) => revealLink(link.publicId, session.csrfToken),
    onSuccess: ({ url }, link) => setUrls(previous => ({ ...previous, [link.publicId]: url })),
  })
  const revoke = useMutation({
    mutationFn: (link: AdminShare) => revokeLink(link.publicId, session.csrfToken),
    onSuccess: (_, link) => {
      hide(link)
      setRevoking(null)
      void queryClient.invalidateQueries({ queryKey: adminKeys.links })
    },
  })
  // The link a Show or Revoke is running for.
  const busy = reveal.isPending
    ? reveal.variables.publicId
    : revoke.isPending
      ? revoke.variables.publicId
      : ''
  const actionError =
    errorMessage(reveal.error, 'Unable to show this link.') ||
    errorMessage(revoke.error, 'Unable to revoke this link.')

  function change(values: Record<string, string>) {
    const next = new URLSearchParams(params)
    for (const [name, value] of Object.entries(values)) {
      if (value) next.set(name, value)
      else next.delete(name)
    }
    if (!('page' in values)) next.delete('page')
    // The same filters again replace their own step, so Back never stays put.
    setParams(next, { replace: next.toString() === params.toString() })
  }

  function submitSearch(event: FormEvent) {
    event.preventDefault()
    change({ q: search.trim() })
  }

  function hide(link: AdminShare) {
    setUrls(previous => {
      const next = { ...previous }
      delete next[link.publicId]
      return next
    })
  }

  function show(link: AdminShare) {
    if (urls[link.publicId]) return hide(link)
    revoke.reset()
    reveal.mutate(link)
  }

  function confirmRevoke(link: AdminShare) {
    reveal.reset()
    revoke.reset()
    setRevoking(link)
  }

  const data = links.data
  const owner = data?.items.find(link => String(link.owner.id) === user)?.owner
  return (
    <>
      <PageTitle>Access links · Admin</PageTitle>
      <PageHeading eyebrow="ADMINISTRATION" title="Access links">
        Every access link of every account. Show reveals a link's URL; Revoke stops it for its
        visitors.
      </PageHeading>
      {/* An open dialog shows the action's error itself. */}
      <ErrorMessage message={revoking ? '' : actionError} />
      <div className={styles.toolbar}>
        <form className={styles.search} onSubmit={submitSearch} role="search">
          <input
            className={common.textField}
            aria-label="Search links"
            placeholder="Search by link name or owner/repository…"
            value={search}
            maxLength={200}
            onChange={event => setSearch(event.target.value)}
          />
          <button className={common.secondary} type="submit">
            <Search size={16} /> Search
          </button>
        </form>
        <select
          className={common.textField}
          aria-label="Status"
          value={status}
          onChange={event => change({ status: event.target.value })}
        >
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="expired">Expired</option>
          <option value="revoked">Revoked</option>
        </select>
        {user && (
          <span className={styles.chip}>
            Links of {owner ? displayName(owner) : `user #${user}`}
            <button
              className={common.iconButton}
              aria-label="Show links of every user"
              onClick={() => change({ user: '' })}
            >
              <X size={15} />
            </button>
          </span>
        )}
      </div>
      <ErrorMessage
        message={errorMessage(links.error, 'Unable to load access links.')}
        onRetry={() => void links.refetch()}
      />
      {links.isPending && (
        <p role="status" className={common.hintText}>
          Loading access links…
        </p>
      )}
      {data && (
        <div className={styles.tableWrap}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th>Link</th>
                <th>Owner</th>
                <th>Repository</th>
                <th>Status</th>
                <th>Expires</th>
                <th>
                  <span className={styles.visuallyHidden}>Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {data.items.length === 0 && (
                <tr>
                  <td colSpan={6} className={styles.emptyCell}>
                    No access links match these filters.
                  </td>
                </tr>
              )}
              {data.items.map(link => (
                <LinkRow
                  key={link.publicId}
                  link={link}
                  url={urls[link.publicId]}
                  busy={busy === link.publicId}
                  onShow={() => show(link)}
                  onRevoke={() => confirmRevoke(link)}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data && (
        <Pager
          total={data.total}
          noun="link"
          page={page}
          hasNext={!!data.nextPage}
          onPage={next => change({ page: String(next) })}
        />
      )}
      {revoking && (
        <ConfirmDialog
          title="Revoke link"
          danger
          busy={busy !== ''}
          error={actionError}
          confirmLabel="Revoke"
          onCancel={() => setRevoking(null)}
          onConfirm={() => revoke.mutate(revoking)}
          message={
            <>
              Revoke <strong>{revoking.name}</strong> of {displayName(revoking.owner)}? Visitors
              lose access immediately and the URL can no longer be shown.
            </>
          }
        />
      )}
    </>
  )
}
