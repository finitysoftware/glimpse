import { useCallback, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Link2 } from 'lucide-react'
import type { Platform } from '../../api/platforms'
import { PageTitle } from '../../components/PageTitle'
import { repositoryBase, type Preview } from './api'
import { placeTitle } from './browser/previewState'
import { AccessLinks } from './links/AccessLinks'
import type { LinkDraft } from './links/CreateLinkForm'
import { RepositoryBrowser } from './RepositoryBrowser'
import styles from './Repositories.module.css'

const emptyDraft: LinkDraft = {
  name: '',
  days: '7',
  permissions: { branches: null, allowHistory: false },
  choices: {},
}

export function RepositoryDetail({ csrfToken }: { csrfToken: string }) {
  const { owner = '', repository = '' } = useParams()
  return (
    <RepositoryWorkspace
      key={`${owner}/${repository}`}
      platform="github"
      csrfToken={csrfToken}
      name={`${owner}/${repository}`}
      eyebrow={owner}
      title={repository}
      base={repositoryBase.github(owner, repository)}
    />
  )
}

/** A GitLab project, addressed by its stable ID; its name comes from the catalogue link and then from the project itself. */
export function GitLabProjectDetail({ csrfToken }: { csrfToken: string }) {
  const { projectId = '' } = useParams()
  const location = useLocation()
  const initial = (location.state as { fullName?: unknown } | null)?.fullName
  const [fullName, setFullName] = useState(typeof initial === 'string' ? initial : '')
  const onRepository = useCallback(
    (repository: Preview['repository']) => setFullName(repository.fullName),
    [],
  )
  if (!/^[1-9][0-9]{0,17}$/.test(projectId))
    return (
      <>
        <PageTitle>Page not found</PageTitle>
        <h1 tabIndex={-1}>Page not found</h1>
        <Link to="/app">Open repositories</Link>
      </>
    )
  const split = fullName.lastIndexOf('/')
  return (
    <RepositoryWorkspace
      key={projectId}
      platform="gitlab"
      csrfToken={csrfToken}
      onRepository={onRepository}
      name={fullName || `Project ${projectId}`}
      eyebrow={`GitLab · ${split > 0 ? fullName.slice(0, split) : 'project ' + projectId}`}
      title={split > 0 ? fullName.slice(split + 1) : fullName || `Project ${projectId}`}
      base={repositoryBase.gitlab(projectId)}
    />
  )
}

/** One repository: its files, with file visibility for new links, and its access links. */
function RepositoryWorkspace({
  platform,
  base,
  name,
  eyebrow,
  title,
  csrfToken,
  onRepository,
}: {
  platform: Platform
  base: `/api/${string}`
  /** The repository in the browser tab, e.g. owner/app. */
  name: string
  eyebrow: string
  title: string
  csrfToken: string
  onRepository?: (repository: Preview['repository']) => void
}) {
  const navigate = useNavigate()
  const location = useLocation()
  // The tab is part of the address, so Back returns to Files and leaving Files with unsaved rules asks first.
  const tab = new URLSearchParams(location.search).get('tab') === 'links' ? 'links' : 'files'
  // A half-made link waits here while its owner checks file visibility on the Files tab.
  const [draft, setDraft] = useState(emptyDraft)

  /** The Files address keeps the preview the owner left; Access links adds tab=links to it. */
  function showTab(next: 'files' | 'links') {
    if (next === tab) return
    const address = new URLSearchParams(location.search)
    if (next === 'links') address.set('tab', 'links')
    else address.delete('tab')
    void navigate({ search: address.toString() })
  }

  function configure(branch: string) {
    void navigate({ search: new URLSearchParams({ branch, visibility: '1' }).toString() })
  }

  return (
    <>
      <Link className={styles.back} to="/app">
        <ArrowLeft size={15} /> All repositories
      </Link>
      <PageTitle>
        {tab === 'links'
          ? `Access links · ${name}`
          : [placeTitle(location.search), name].filter(Boolean).join(' · ')}
      </PageTitle>
      <header className={styles.heading}>
        <div>
          <p className={styles.eyebrow}>{eyebrow}</p>
          <h1 tabIndex={-1}>{title}</h1>
          <p>Browse files and manage who can access this repository.</p>
        </div>
        <button className={styles.primary} onClick={() => showTab('links')}>
          <Link2 size={16} /> Manage links
        </button>
      </header>
      <div className={styles.tabs}>
        <button aria-pressed={tab === 'files'} onClick={() => showTab('files')}>
          Files
        </button>
        <button aria-pressed={tab === 'links'} onClick={() => showTab('links')}>
          Access links
        </button>
      </div>
      {tab === 'files' ? (
        <RepositoryBrowser
          endpoint={`${base}/browse`}
          visibility={{ endpoint: `${base}/visibility`, csrfToken }}
          onRepository={onRepository}
        />
      ) : (
        <AccessLinks
          base={base}
          platform={platform}
          csrfToken={csrfToken}
          draft={draft}
          onDraftChange={setDraft}
          onConfigure={configure}
        />
      )}
    </>
  )
}
