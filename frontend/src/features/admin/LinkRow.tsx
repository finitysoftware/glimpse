import { useState } from 'react'
import { Copy, Eye, EyeOff, ShieldOff } from 'lucide-react'
import { clsx } from 'clsx'
import { platformLabels } from '../../api/platforms'
import common from '../../styles/app.module.css'
import styles from './Admin.module.css'
import { type AdminShare } from './api'
import { displayName, formatDate } from './format'

const statusLabels: Record<AdminShare['status'], string> = {
  active: 'Active',
  expired: 'Expired',
  revoked: 'Revoked',
}

function access(link: AdminShare): string {
  const files = link.policies.map(policy =>
    policy.mode === 'all'
      ? `${policy.branch}: all files`
      : `${policy.branch}: ${policy.selectedCount} selected`,
  )
  return [link.branches.join(', '), ...files, `History ${link.allowHistory ? 'on' : 'off'}`].join(
    ' · ',
  )
}

/** One link of the table; Show adds a second row with its URL. */
export function LinkRow({
  link,
  url,
  busy,
  onShow,
  onRevoke,
}: {
  link: AdminShare
  /** The URL once Show revealed it. */
  url: string | undefined
  busy: boolean
  onShow: () => void
  onRevoke: () => void
}) {
  const active = link.status === 'active'
  return (
    <>
      <tr className={active ? undefined : styles.inactive}>
        <td>
          <strong>{link.name}</strong>
          <small className={styles.sub}>{access(link)}</small>
        </td>
        <td>
          {displayName(link.owner)}
          <small className={styles.sub}>{link.owner.username}</small>
        </td>
        <td>
          <span className={styles.platform}>{platformLabels[link.repository.provider]}</span>{' '}
          {link.repository.owner}/{link.repository.name}
          {link.repository.provider === 'gitlab' && (
            <small className={styles.sub}>{link.repository.instance}</small>
          )}
          {!link.repository.active && <small className={styles.sub}>Sharing disabled</small>}
        </td>
        <td>
          <span className={styles.status}>
            <i className={active ? styles.dotActive : styles.dotInactive} />
            {statusLabels[link.status]}
          </span>
        </td>
        <td>{link.expiresAt ? formatDate(link.expiresAt) : 'No expiry'}</td>
        <td>
          <div className={styles.rowActions}>
            <button className={common.secondary} disabled={busy || !active} onClick={onShow}>
              {url ? <EyeOff size={15} /> : <Eye size={15} />} {url ? 'Hide' : 'Show'}
            </button>
            {active && (
              <button className={common.secondary} disabled={busy} onClick={onRevoke}>
                <ShieldOff size={15} /> Revoke
              </button>
            )}
          </div>
        </td>
      </tr>
      {url && <UrlRow url={url} />}
    </>
  )
}

function UrlRow({ url }: { url: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <tr className={styles.urlRow}>
      <td colSpan={6}>
        <label className={styles.urlLabel}>
          Access URL
          <input
            className={clsx(common.textField, styles.token)}
            readOnly
            value={url}
            onFocus={event => event.target.select()}
          />
        </label>
        <button
          className={common.secondary}
          onClick={() => void navigator.clipboard.writeText(url).then(() => setCopied(true))}
        >
          <Copy size={15} /> {copied ? 'Copied' : 'Copy'}
        </button>
      </td>
    </tr>
  )
}
