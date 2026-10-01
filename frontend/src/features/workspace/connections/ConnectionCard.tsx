import type { ReactNode, RefObject } from 'react'
import { KeyRound, LoaderCircle, RefreshCw, Unplug } from 'lucide-react'
import { clsx } from 'clsx'
import { connectionLabels, platformLabels, type Platform } from '../../../api/platforms'
import { plural } from '../../../format'
import common from '../../../styles/app.module.css'
import styles from './Connections.module.css'
import { accountOf, type Connection } from './api'

/** A GitLab token that expires within this many days is flagged in Connections. */
const EXPIRY_WARNING_DAYS = 14

/** One platform's connection: its saved state and the Configure, check and Disconnect actions. */
export function ConnectionCard({
  platform,
  card,
  connection,
  description,
  today,
  checking,
  onConfigure,
  onCheck,
  onDisconnect,
}: {
  platform: Platform
  card: RefObject<HTMLElement | null>
  /** null while the saved state loads. */
  connection: Connection | null
  description: string
  today: number
  checking: boolean
  onConfigure: () => void
  onCheck: () => void
  onDisconnect: () => void
}) {
  const label = platformLabels[platform]
  const loaded = connection !== null
  const configured = connection?.configured ?? false
  return (
    <section
      id={`${platform}-connection`}
      ref={card}
      tabIndex={-1}
      className={common.card}
      aria-labelledby={`${platform}-connection-title`}
    >
      <h2 id={`${platform}-connection-title`}>{label} connection</h2>
      <p>{description}</p>
      {connection && <Details platform={platform} connection={connection} today={today} />}
      <div className={common.buttonRow}>
        {/* While the saved state loads, the button itself shows the progress instead of a separate message. */}
        <button
          className={clsx(common.primary, !loaded && common.loadingButton)}
          disabled={!loaded}
          aria-busy={!loaded}
          onClick={onConfigure}
        >
          {loaded ? (
            <KeyRound size={16} />
          ) : (
            <LoaderCircle size={16} className={styles.spinning} aria-hidden="true" />
          )}
          Configure {label}
        </button>
        {configured && (
          <button className={common.secondary} disabled={checking} onClick={onCheck}>
            <RefreshCw size={16} />
            {checking ? 'Checking connection…' : 'Run connection check'}
          </button>
        )}
        {configured && (
          <button className={common.secondary} onClick={onDisconnect}>
            <Unplug size={16} />
            Disconnect
          </button>
        )}
      </div>
    </section>
  )
}

function Details({
  platform,
  connection,
  today,
}: {
  platform: Platform
  connection: Connection
  today: number
}) {
  const gitlab = 'gitlabUsername' in connection ? connection : null
  const account = accountOf(connection)
  return (
    <dl className={common.details}>
      <Detail term="Status">{connectionLabels[connection.status]}</Detail>
      {gitlab?.baseUrl && <Detail term="GitLab URL">{gitlab.baseUrl}</Detail>}
      {connection.maskedToken && (
        <Detail term="Personal access token">
          <code className={styles.token}>{connection.maskedToken}</code>
        </Detail>
      )}
      {account && <Detail term={`${platformLabels[platform]} account`}>{account}</Detail>}
      {gitlab?.configured && (
        <Detail term="Expires">
          <Expiry date={gitlab.tokenExpiresAt} now={today} />
        </Detail>
      )}
      {connection.configured && (
        <Detail term="Last check">
          {connection.lastCheckedAt
            ? new Date(connection.lastCheckedAt).toLocaleString()
            : 'Not checked yet'}
        </Detail>
      )}
    </dl>
  )
}

function Detail({ term, children }: { term: string; children: ReactNode }) {
  return (
    <div>
      <dt>{term}</dt>
      <dd>{children}</dd>
    </div>
  )
}

function Expiry({ date, now }: { date: string | null; now: number }) {
  if (date === null) return <>Not reported</>
  const days = Math.ceil((Date.parse(`${date}T00:00:00Z`) - now) / 86400000)
  const text = new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, { timeZone: 'UTC' })
  if (days < 0) return <span className={styles.warningText}>{text} · expired</span>
  if (days <= EXPIRY_WARNING_DAYS)
    return (
      <span className={styles.warningText}>
        {text} · expires in {plural(days, 'day')}
      </span>
    )
  return <>{text}</>
}
