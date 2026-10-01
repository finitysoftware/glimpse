import { useCallback, useState } from 'react'
import { useLocation, useParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Eye, LockKeyhole } from 'lucide-react'
import { errorMessage } from '../../api/client'
import { QueryScope } from '../../api/QueryScope'
import { ErrorBoundary } from '../../components/ErrorBoundary'
import { PageTitle } from '../../components/PageTitle'
import type { Preview } from '../repositories/api'
import { placeTitle } from '../repositories/browser/previewState'
import { RepositoryBrowser } from '../repositories/RepositoryBrowser'
import common from '../repositories/Repositories.module.css'
import app from '../../styles/app.module.css'
import styles from './Sharing.module.css'
import { endVisit, openShare, shareBrowseEndpoint, shareKeys } from './api'

/** Every access link gets its own query cache, so nothing of one link shows under another. */
export function SharePage() {
  const { publicId = '' } = useParams()
  return (
    <QueryScope key={publicId}>
      <SharedRepository publicId={publicId} />
    </QueryScope>
  )
}

function SharedRepository({ publicId }: { publicId: string }) {
  // The exchange runs once: the secret is gone from the address afterwards.
  const session = useQuery({
    queryKey: shareKeys.session(publicId),
    queryFn: () => openShare(publicId),
    staleTime: Infinity,
  })
  const location = useLocation()
  // The browser tab names the shared repository once its first preview arrived.
  const [name, setName] = useState('')
  const onRepository = useCallback(
    (repository: Preview['repository']) => setName(repository.fullName),
    [],
  )
  const [ended, setEnded] = useState('')
  const end = useMutation({
    mutationFn: () => endVisit(session.data?.csrfToken),
    onSuccess: () => setEnded('Your session has ended. Open the original access link to return.'),
    onError: reason => setEnded(errorMessage(reason, 'Unable to end the session.')),
  })
  const error = ended || errorMessage(session.error, 'This link could not be opened.')
  const open = session.data && !ended

  return (
    <main className={styles.sharedPage}>
      <PageTitle>
        {error
          ? 'Repository unavailable'
          : [placeTitle(location.search), name || 'Shared repository'].filter(Boolean).join(' · ')}
      </PageTitle>
      <header className={styles.sharedHeader}>
        <span className={styles.wordmark}>
          <span className={app.brandMark}>
            <Eye size={23} />
          </span>
          Glimpse
        </span>
        <span>
          <LockKeyhole size={14} /> Shared repository
        </span>
        {open && (
          <button
            className={common.secondary}
            disabled={end.isPending}
            onClick={() => end.mutate()}
          >
            End session
          </button>
        )}
      </header>
      {session.isPending && (
        <p role="status" className={common.empty}>
          Opening your access link…
        </p>
      )}
      {error && (
        <section className={common.panel}>
          <h1>Repository unavailable</h1>
          <p role="alert">{error}</p>
        </section>
      )}
      {open && (
        <ErrorBoundary resetKey={location.search}>
          <RepositoryBrowser endpoint={shareBrowseEndpoint(publicId)} onRepository={onRepository} />
        </ErrorBoundary>
      )}
      <footer className={styles.sharedFooter}>
        Read-only access · Provided by the repository owner
      </footer>
    </main>
  )
}
