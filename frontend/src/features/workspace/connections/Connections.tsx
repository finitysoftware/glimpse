import { useEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { errorMessage } from '../../../api/client'
import {
  platformLabels,
  platforms,
  type ConnectionState,
  type Platform,
} from '../../../api/platforms'
import { useWorkspaceSession } from '../../auth/session'
import common from '../../../styles/app.module.css'
import { ConfigureDialog } from './ConfigureDialog'
import {
  accountOf,
  checkConnection,
  connectionKeys,
  loadConnection,
  loadPreviewCache,
  type Connection,
} from './api'
import { ConnectionCard } from './ConnectionCard'
import { DisconnectDialog } from './DisconnectDialog'
import { PreviewCacheCard } from './PreviewCacheCard'
import { PageHeading } from '../../../components/PageHeading'
import { PageTitle } from '../../../components/PageTitle'
import { ErrorMessage } from '../../../components/ErrorMessage'

const descriptions: Record<Platform, string> = {
  github:
    'Repositories come from your own GitHub personal access token. It is different from your Glimpse password.',
  gitlab:
    'Projects from GitLab.com or your own GitLab instance, through your GitLab personal access token.',
}

const checkMessages = (platform: Platform): Record<ConnectionState, string> => {
  const label = platformLabels[platform]
  return {
    not_configured: `${label} is not configured.`,
    not_verified: 'The saved token has not been verified yet.',
    connected: `${label} connected.`,
    credentials_rejected: `${label} rejected the saved token. Configure a new token.`,
    access_restricted:
      platform === 'github'
        ? 'GitHub restricted access for the saved token. Check its resource owner, repository access and any organization approval.'
        : 'GitLab restricted access for the saved token. Check its read_api scope and your access to the projects.',
    temporarily_unavailable: `${label} is temporarily unavailable. Try the check again later.`,
  }
}

const connected = (platform: Platform, connection: Connection) => {
  const account = accountOf(connection)
  return `${platformLabels[platform]} connected${account ? ` as ${account}` : ''}.`
}

export function Connections() {
  const { session, updateConnection } = useWorkspaceSession()
  const location = useLocation()
  const queryClient = useQueryClient()
  const githubQuery = useQuery({
    queryKey: connectionKeys.platform('github'),
    queryFn: ({ signal }) => loadConnection('github', signal),
  })
  const gitlabQuery = useQuery({
    queryKey: connectionKeys.platform('gitlab'),
    queryFn: ({ signal }) => loadConnection('gitlab', signal),
  })
  const cacheQuery = useQuery({
    queryKey: connectionKeys.previewCache,
    queryFn: ({ signal }) => loadPreviewCache(signal),
  })
  const connections: Record<Platform, Connection | null> = {
    github: githubQuery.data ?? null,
    gitlab: gitlabQuery.data ?? null,
  }
  const [notice, setNotice] = useState<{ text: string; openRepositories: boolean } | null>(null)
  const [dialog, setDialog] = useState<{
    platform: Platform
    kind: 'configure' | 'disconnect'
  } | null>(null)
  const githubCard = useRef<HTMLElement>(null)
  const gitlabCard = useRef<HTMLElement>(null)
  const cardOf = (platform: Platform) => (platform === 'github' ? githubCard : gitlabCard)
  // Read once for the expiry notice; the page is reloaded often enough for day precision.
  const [today] = useState(() => Date.now())

  const reload = () => void queryClient.invalidateQueries({ queryKey: connectionKeys.all })
  const check = useMutation({
    mutationFn: (platform: Platform) => checkConnection(platform, session.csrfToken),
    onMutate: () => setNotice(null),
    onSuccess: (checked, platform) => {
      apply(platform, checked)
      setNotice({
        text:
          checked.status === 'connected'
            ? connected(platform, checked)
            : checkMessages(platform)[checked.status],
        openRepositories: false,
      })
      reload()
    },
  })
  const checking = check.isPending ? check.variables : null
  const loadError = githubQuery.error ?? gitlabQuery.error ?? cacheQuery.error
  const error =
    (check.isError &&
      errorMessage(
        check.error,
        `Unable to check the ${platformLabels[check.variables]} connection.`,
      )) ||
    errorMessage(loadError, 'Unable to read the connections.')

  // Configure links (e.g. from Repositories) open this page at one platform's card.
  const loaded = connections.github !== null && connections.gitlab !== null
  useEffect(() => {
    const card =
      location.hash === '#github-connection'
        ? githubCard.current
        : location.hash === '#gitlab-connection'
          ? gitlabCard.current
          : null
    if (loaded && card) {
      card.scrollIntoView?.({ block: 'start' })
      card.focus()
    }
  }, [location.hash, loaded])

  function apply(platform: Platform, saved: Connection) {
    queryClient.setQueryData(connectionKeys.platform(platform), saved)
    updateConnection(platform, {
      configured: saved.configured,
      status: saved.status,
      credentialVersion: saved.credentialVersion,
    })
  }

  function open(platform: Platform, kind: 'configure' | 'disconnect') {
    setNotice(null)
    setDialog({ platform, kind })
  }

  function done(platform: Platform, saved: Connection, text: string, openRepositories: boolean) {
    apply(platform, saved)
    setNotice({ text, openRepositories })
    setDialog(null)
    reload()
  }

  const configuring = dialog?.kind === 'configure' ? connections[dialog.platform] : null
  return (
    <>
      <PageTitle>Connections</PageTitle>
      <PageHeading eyebrow="WORKSPACE" title="Connections">
        Your GitHub and GitLab connections and the preview cache used by your workspace and access
        links.
      </PageHeading>
      <ErrorMessage message={error} />
      {notice && (
        <p className={common.noticeLine} role="status">
          {notice.text}
          {notice.openRepositories && (
            <>
              {' '}
              <Link to="/app">Open repositories</Link>
            </>
          )}
        </p>
      )}
      {platforms.map(platform => (
        <ConnectionCard
          key={platform}
          platform={platform}
          card={cardOf(platform)}
          connection={connections[platform]}
          description={descriptions[platform]}
          today={today}
          checking={checking === platform}
          onConfigure={() => open(platform, 'configure')}
          onCheck={() => check.mutate(platform)}
          onDisconnect={() => open(platform, 'disconnect')}
        />
      ))}
      {cacheQuery.data && (
        <PreviewCacheCard
          cache={cacheQuery.data}
          gitlabConfigured={connections.gitlab?.configured ?? false}
        />
      )}
      {dialog && configuring && (
        <ConfigureDialog
          platform={dialog.platform}
          connection={configuring}
          csrfToken={session.csrfToken}
          onClose={() => setDialog(null)}
          onConflict={reload}
          onSaved={saved => done(dialog.platform, saved, connected(dialog.platform, saved), true)}
        />
      )}
      {dialog?.kind === 'disconnect' && (
        <DisconnectDialog
          platform={dialog.platform}
          card={cardOf(dialog.platform)}
          csrfToken={session.csrfToken}
          onClose={() => setDialog(null)}
          onDone={saved =>
            done(
              dialog.platform,
              saved,
              `${platformLabels[dialog.platform]} disconnected. Your links and file visibility settings are kept.`,
              false,
            )
          }
        />
      )}
    </>
  )
}
