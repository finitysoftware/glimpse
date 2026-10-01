import { Link } from 'react-router-dom'
import { RefreshCw } from 'lucide-react'
import { ApiError } from '../../api/client'
import { platformLabels, type Platform } from '../../api/platforms'
import { configureLink } from './paths'
import styles from './Repositories.module.css'

/** A failure caused by the saved token, which only a new token fixes; null for any other failure. */
function tokenProblem(platform: Platform, error: ApiError): { title: string; text: string } | null {
  const label = platformLabels[platform]
  switch (error.code) {
    case `${platform}_not_connected`:
      return {
        title: `Connect ${label} to see your repositories.`,
        text: 'Add a personal access token in Connections. Glimpse lists the repositories that token can read.',
      }
    case `${platform}_credentials_rejected`:
      return {
        title: `${label} rejected the saved token.`,
        text: 'It may have expired, been revoked or copied incompletely. Configure a new token to continue.',
      }
    case `${platform}_access_denied`:
      return {
        title: `${label} restricted access for this token.`,
        text: `The token is valid, but ${label} denied the catalogue. Check its access to the repositories you want to share.`,
      }
    default:
      return null
  }
}

function retryLabel(waiting: number): string {
  return waiting > 0 ? `Try again in ${waiting} s` : 'Try again'
}

/** A platform that failed while another one loaded: a short notice above the list. */
export function PlatformNotice({
  platform,
  error,
  waiting,
  onRetry,
}: {
  platform: Platform
  error: ApiError
  waiting: number
  onRetry: () => void
}) {
  const label = platformLabels[platform]
  const problem = tokenProblem(platform, error)
  return (
    <p className={styles.platformNotice} role="alert">
      <strong>{label}:</strong> {problem ? problem.title : error.message}{' '}
      {problem ? (
        <Link to={configureLink(platform)}>Configure {label}</Link>
      ) : (
        <button className={styles.linkButton} disabled={waiting > 0} onClick={onRetry}>
          {retryLabel(waiting)}
        </button>
      )}
    </p>
  )
}

/** Why a platform's catalogue could not be listed, in place of the list. */
export function CatalogueProblem({
  platform,
  error,
  waiting,
  onRetry,
}: {
  platform: Platform
  error: ApiError
  waiting: number
  onRetry: () => void
}) {
  const label = platformLabels[platform]
  const problem = tokenProblem(platform, error)
  if (problem)
    return (
      <section className={styles.panel}>
        <h2>{problem.title}</h2>
        <p>{problem.text}</p>
        <div className={styles.actions}>
          <Link className={styles.primary} to={configureLink(platform)}>
            Configure {label}
          </Link>
        </div>
      </section>
    )
  if (error.status === 429)
    return (
      <section className={styles.panel} role="status">
        <h2>{label} quota is temporarily used up.</h2>
        <p>
          {waiting > 0 ? `Try again in ${waiting} seconds.` : 'You can try again now.'} Your token
          is not affected.
        </p>
        <div className={styles.actions}>
          <button className={styles.secondary} disabled={waiting > 0} onClick={onRetry}>
            <RefreshCw size={15} /> Try again
          </button>
        </div>
      </section>
    )
  return (
    <section className={styles.panel}>
      <p className={styles.error} role="alert">
        {error.message}
      </p>
      <div className={styles.actions}>
        <button className={styles.secondary} disabled={waiting > 0} onClick={onRetry}>
          <RefreshCw size={15} /> {retryLabel(waiting)}
        </button>
        <Link className={styles.secondary} to="/app/connections">
          Check Connections
        </Link>
      </div>
    </section>
  )
}
