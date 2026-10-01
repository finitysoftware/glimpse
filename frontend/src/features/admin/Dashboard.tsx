import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Activity,
  Database,
  GitBranch,
  Link2,
  RefreshCw,
  Server,
  Users as UsersIcon,
} from 'lucide-react'
import { errorMessage } from '../../api/client'
import common from '../../styles/app.module.css'
import styles from './Admin.module.css'
import { adminKeys, loadDashboard } from './api'
import { formatBytes } from './format'
import { useAdminSession } from './session'
import { PageHeading } from '../../components/PageHeading'
import { PageTitle } from '../../components/PageTitle'
import { ErrorMessage } from '../../components/ErrorMessage'

function greeting(name: string): string {
  const hour = new Date().getHours()
  if (hour < 12) return `Good morning, ${name}`
  if (hour < 18) return `Good afternoon, ${name}`
  return `Good evening, ${name}`
}

/** Bar height in percent of the busiest day; a day with activity stays visible, an empty one is a thin line. */
function barHeight(count: number, peak: number): string {
  return `${count ? Math.max(6, (count / peak) * 100) : 2}%`
}

export function Dashboard() {
  const { session } = useAdminSession()
  const dashboard = useQuery({
    queryKey: adminKeys.dashboard,
    queryFn: ({ signal }) => loadDashboard(signal),
  })
  const data = dashboard.data
  const error = errorMessage(dashboard.error, 'Unable to load the dashboard.')

  const name = session.admin.firstName ?? session.admin.username
  const peak = Math.max(
    1,
    ...(data?.series ?? []).flatMap(day => [day.workspaceLogins, day.linkOpens]),
  )
  return (
    <>
      <PageTitle>Dashboard · Admin</PageTitle>
      <PageHeading
        eyebrow="ADMINISTRATION"
        title={greeting(name)}
        actions={
          <button className={common.secondary} onClick={() => void dashboard.refetch()}>
            <RefreshCw size={16} /> Refresh
          </button>
        }
      >
        {data
          ? `Platform overview as of ${new Date(data.generatedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}.`
          : 'Platform overview.'}
      </PageHeading>
      <ErrorMessage message={error} onRetry={() => void dashboard.refetch()} />
      {!data && !error && (
        <p role="status" className={common.hintText}>
          Loading dashboard…
        </p>
      )}
      {data && (
        <>
          <div className={styles.cards}>
            <Card
              icon={<UsersIcon size={17} />}
              title="Users"
              value={data.users.total}
              detail={`${data.users.active} active · ${data.users.disabled} disabled · ${data.users.admins} admins · ${data.users.signedInLast7Days} signed in this week`}
            />
            <Card
              icon={<GitBranch size={17} />}
              title="GitHub connections"
              value={data.github.connected}
              detail={`connected · ${data.github.notVerified} not verified · ${data.github.credentialsRejected + data.github.accessRestricted} with problems · ${data.github.notConfigured} not configured`}
            />
            <Card
              icon={<GitBranch size={17} />}
              title="GitLab connections"
              value={data.gitlab.connected}
              detail={`connected · ${data.gitlab.notVerified} not verified · ${data.gitlab.credentialsRejected + data.gitlab.accessRestricted} with problems · ${data.gitlab.expiringSoon} expiring within 14 days · ${data.gitlab.notConfigured} not configured`}
            />
            <Card
              icon={<Link2 size={17} />}
              title="Access links"
              value={data.links.active}
              detail={`active · ${data.links.selectedFiles} with selected files · ${data.links.history} with history · ${data.links.expired} expired · ${data.links.revoked} revoked`}
            />
            <Card
              icon={<Activity size={17} />}
              title="Visitors"
              value={data.visitors.opensLast7Days}
              detail={`link opens this week · ${data.visitors.opensLast30Days} in 30 days · ${data.visitors.activeSessions} active visitor sessions`}
            />
            <Card
              icon={<Database size={17} />}
              title="Preview cache"
              value={formatBytes(data.cache.bytes)}
              detail={`of ${formatBytes(data.cache.maxBytes)} · ${data.cache.entries} entries · ${data.cache.upstreamCalls} upstream calls · ${data.cache.usersInCooldown} users in rate-limit pause`}
            />
            <Card
              icon={<Server size={17} />}
              title="System"
              value={
                data.system.migrations.pending.length === 0
                  ? 'Up to date'
                  : `${data.system.migrations.pending.length} pending`
              }
              detail={`PHP ${data.system.phpVersion} · ${data.system.databaseVersion ?? 'database'} · ${data.system.migrations.applied}/${data.system.migrations.total} migrations${data.system.maintenance ? ' · maintenance on' : ''}`}
            />
          </div>
          <section className={common.card} aria-labelledby="activity-heading">
            <div className={styles.chartHead}>
              <h2 id="activity-heading">Last 14 days</h2>
              <span className={styles.legend}>
                <i className={styles.legendLogins} /> Workspace sign-ins{' '}
                <i className={styles.legendOpens} /> Link opens
              </span>
            </div>
            <div
              className={styles.chart}
              role="img"
              aria-label={`Workspace sign-ins and link opens over the last 14 days: ${data.series.map(day => `${day.day} ${day.workspaceLogins} sign-ins, ${day.linkOpens} opens`).join('; ')}`}
            >
              {data.series.map(day => (
                <div
                  key={day.day}
                  className={styles.chartDay}
                  title={`${day.day}: ${day.workspaceLogins} sign-ins, ${day.linkOpens} link opens, ${day.linksCreated} new links`}
                >
                  <div className={styles.chartBars}>
                    <span
                      className={styles.barLogins}
                      style={{ height: barHeight(day.workspaceLogins, peak) }}
                    />
                    <span
                      className={styles.barOpens}
                      style={{ height: barHeight(day.linkOpens, peak) }}
                    />
                  </div>
                  <small>
                    {new Date(`${day.day}T00:00:00Z`).toLocaleDateString(undefined, {
                      weekday: 'short',
                      timeZone: 'UTC',
                    })}
                  </small>
                </div>
              ))}
            </div>
          </section>
          <section className={common.card} aria-labelledby="system-heading">
            <h2 id="system-heading">Configuration</h2>
            <dl className={common.details}>
              <div>
                <dt>Workspace addresses</dt>
                <dd>{data.system.workspaceOrigins.join(', ')}</dd>
              </div>
              <div>
                <dt>Address in new share links</dt>
                <dd>{data.system.sharePublicOrigin}</dd>
              </div>
              <div>
                <dt>Share URL storage</dt>
                <dd>{data.system.shareKeyConfigured ? 'Configured' : 'Not configured'}</dd>
              </div>
              <div>
                <dt>GitHub token key</dt>
                <dd>{data.system.githubTokenKeyId ?? 'Not configured'}</dd>
              </div>
              <div>
                <dt>Latest migration</dt>
                <dd>
                  {data.system.migrations.latest ?? '-'}
                  {data.system.migrations.pending.length > 0
                    ? ` (pending: ${data.system.migrations.pending.join(', ')})`
                    : ''}
                </dd>
              </div>
              <div>
                <dt>Environment</dt>
                <dd>{data.system.environment}</dd>
              </div>
            </dl>
          </section>
        </>
      )}
    </>
  )
}

function Card({
  icon,
  title,
  value,
  detail,
}: {
  icon: ReactNode
  title: string
  value: number | string
  detail: string
}) {
  return (
    <section className={common.card} aria-label={title}>
      <div className={styles.cardTop}>
        <span>{title}</span>
        {icon}
      </div>
      <div className={styles.cardValue}>
        {typeof value === 'number' ? value.toLocaleString() : value}
      </div>
      <p>{detail}</p>
    </section>
  )
}
