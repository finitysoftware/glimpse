import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Copy, Eye, EyeOff, Trash2 } from 'lucide-react'
import { errorMessage } from '../../../api/client'
import { revealShare, type ShareLink } from './api'
import { plural } from '../../../format'
import common from '../Repositories.module.css'
import styles from './Links.module.css'
import { ErrorMessage } from '../../../components/ErrorMessage'

/** What the link opens: files per branch, and whether History is allowed. */
function accessSummary(link: ShareLink): string {
  const files = link.policies.map(
    policy =>
      `${policy.branch}: ${policy.mode === 'all' ? 'all files' : plural(policy.selectedCount, 'selected file')}`,
  )
  return [...files, `History ${link.allowHistory ? 'allowed' : 'hidden'}`].join(' · ')
}

export function AccessLinkRow({
  link,
  active,
  storageConfigured,
  pending,
  csrfToken,
  onRevoke,
  onDelete,
  onEdit,
}: {
  link: ShareLink
  active: boolean
  storageConfigured: boolean
  pending: boolean
  csrfToken: string
  onRevoke: () => void
  onDelete: () => void
  onEdit?: () => void
}) {
  const [url, setUrl] = useState('')
  const [copied, setCopied] = useState(false)
  const [copyProblem, setCopyProblem] = useState('')
  const reveal = useMutation({
    mutationFn: () => revealShare(link.publicId, csrfToken),
    onMutate: () => {
      setCopied(false)
      setCopyProblem('')
    },
    onSuccess: response => setUrl(response.url),
  })
  const loading = reveal.isPending
  const error = copyProblem || errorMessage(reveal.error, 'Unable to show this link.')

  async function copy() {
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
      setCopyProblem('')
    } catch {
      setCopyProblem('Select the URL and copy it manually.')
    }
  }

  return (
    <div className={styles.linkItem}>
      <div className={styles.linkRow}>
        <div className={styles.linkDetails}>
          <strong>{link.name}</strong>
          <p>
            {link.expiresAt ? `Expires ${new Date(link.expiresAt).toLocaleString()}` : 'No expiry'}
          </p>
          <p>{accessSummary(link)}</p>
        </div>
        {onEdit && link.status !== 'revoked' && (
          <button className={common.secondary} disabled={pending} onClick={onEdit}>
            Edit access
          </button>
        )}
        <button
          className={common.secondary}
          aria-expanded={Boolean(url)}
          aria-controls={`url-${link.publicId}`}
          disabled={pending || loading || !active || link.status !== 'active' || !storageConfigured}
          onClick={() => {
            if (url) {
              setUrl('')
              setCopied(false)
              setCopyProblem('')
              reveal.reset()
            } else reveal.mutate()
          }}
        >
          {url ? <EyeOff size={15} /> : <Eye size={15} />}
          {url ? 'Hide' : loading ? 'Loading…' : 'Show'}
        </button>
        <span className={common.badge}>
          {link.status === 'active' && !active ? 'disabled' : link.status}
        </span>
        {link.status === 'revoked' ? (
          <button className={common.danger} disabled={pending} onClick={onDelete}>
            <Trash2 size={15} />
            Delete
          </button>
        ) : (
          <button className={common.secondary} disabled={pending} onClick={onRevoke}>
            Revoke
          </button>
        )}
      </div>
      <div id={`url-${link.publicId}`}>
        {url && (
          <div className={styles.revealedUrl}>
            <label>
              Access URL for {link.name}
              <input readOnly value={url} onFocus={event => event.target.select()} />
            </label>
            <button className={common.primary} onClick={() => void copy()}>
              <Copy size={15} />
              {copied ? 'Copied' : 'Copy link'}
            </button>
          </div>
        )}
        <ErrorMessage message={error} className={common.error} />
      </div>
    </div>
  )
}
