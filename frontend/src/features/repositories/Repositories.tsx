import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQueries, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, RefreshCw } from 'lucide-react'
import type { ApiError } from '../../api/client'
import { platformLabels, platforms, type Platform } from '../../api/platforms'
import { PageTitle } from '../../components/PageTitle'
import { loadCatalogue, repositoryKeys } from './api'
import { useWorkspaceSession } from '../auth/session'
import { CatalogueProblem, PlatformNotice } from './CatalogueProblem'
import { configureLink } from './paths'
import { PlatformFilter } from './PlatformFilter'
import { RepositoryCard } from './RepositoryCard'
import { useSecondsLeft } from './useSecondsLeft'
import styles from './Repositories.module.css'

/** Platforms chosen in the URL (`?platforms=gitlab`), limited to connected ones; all connected platforms by default. */
function chosenPlatforms(value: string | null, available: Platform[]): Platform[] {
  const chosen = available.filter(platform => (value ?? '').split(',').includes(platform))
  return chosen.length > 0 ? chosen : available
}

/** One catalogue page merged from every selected platform. Each platform loads in parallel, so a slow or failing one never hides the other. */
export function Repositories() {
  const { session } = useWorkspaceSession()
  const queryClient = useQueryClient()
  const available = platforms.filter(platform => session[platform].configured)
  const [params, setParams] = useSearchParams()
  const selected = chosenPlatforms(params.get('platforms'), available)
  // The page is in the address too, so Back from a repository returns to it.
  const page = Math.max(1, Number(params.get('page') ?? 1) || 1)
  const [filter, setFilter] = useState('')
  const catalogues = useQueries({
    queries: selected.map(platform => ({
      queryKey: repositoryKeys.catalogue(platform, page),
      queryFn: ({ signal }: { signal: AbortSignal }) => loadCatalogue(platform, page, signal),
    })),
  })
  // The page shows once every selected platform answered, with or without an error.
  const settled = catalogues.every(catalogue => !catalogue.isPending)
  const results = settled
    ? selected.map((platform, index) => ({ platform, ...catalogues[index]! }))
    : []
  const loaded = results.filter(result => result.isSuccess)
  const failed = results.flatMap(({ platform, error, errorUpdatedAt }) =>
    error ? [{ platform, error: error as ApiError, at: errorUpdatedAt }] : [],
  )
  // Retry-After disables Try again until the platform's quota window has passed.
  const retryAt = Math.max(0, ...failed.map(({ error, at }) => at + (error.retryAfter ?? 0) * 1000))
  const failedAt = Math.max(0, ...failed.map(({ at }) => at))
  const waiting = useSecondsLeft(retryAt, failedAt)
  const show = (next: number) => {
    setFilter('')
    setParams(previous => {
      const updated = new URLSearchParams(previous)
      if (next === 1) updated.delete('page')
      else updated.set('page', String(next))
      return updated
    })
  }
  const retry = () => {
    setFilter('')
    void queryClient.refetchQueries({ queryKey: repositoryKeys.catalogues, type: 'active' })
  }
  const refresh = () => (page === 1 ? retry() : show(1))
  // Another platform selection starts again from page 1, in the same change of the address.
  const choose = (next: Platform[]) => {
    setFilter('')
    setParams(
      previous => {
        const updated = new URLSearchParams(previous)
        updated.delete('page')
        if (next.length === available.length) updated.delete('platforms')
        else updated.set('platforms', next.join(','))
        return updated
      },
      { replace: true },
    )
  }
  const items = loaded
    .flatMap(result => result.data?.items ?? [])
    .sort(
      (a, b) =>
        a.fullName.localeCompare(b.fullName, undefined, { sensitivity: 'base' }) ||
        a.provider.localeCompare(b.provider),
    )
  const nextPage = loaded.some(result => result.data?.nextPage)
  const empty = loaded.length > 0 && items.length === 0 && page === 1 && !nextPage
  const matches = items.filter(repo => repo.fullName.toLowerCase().includes(filter.toLowerCase()))
  const several = available.length > 1

  if (available.length === 0)
    return (
      <>
        <Heading />
        <ConnectFirst />
      </>
    )

  return (
    <>
      <Heading onRefresh={loaded.length > 0 ? refresh : undefined} />
      {/* The platform menu stays while a new selection loads, and also when every selected platform failed. */}
      {(several || loaded.length > 0) && (
        <div className={styles.toolbar}>
          {loaded.length > 0 && (
            <>
              <label htmlFor="repository-filter">Filter this page</label>
              <input
                id="repository-filter"
                placeholder="Repository name…"
                value={filter}
                onChange={event => setFilter(event.target.value)}
              />
            </>
          )}
          {several ? (
            <PlatformFilter available={available} selected={selected} onChange={choose} />
          ) : (
            <span>Available through your {platformLabels[available[0]!]} connection</span>
          )}
        </div>
      )}
      {!settled && (
        <p role="status" className={styles.empty}>
          Loading repositories…
        </p>
      )}
      {loaded.length === 0 &&
        failed.map(({ platform, error }) => (
          <CatalogueProblem
            key={platform}
            platform={platform}
            error={error}
            waiting={waiting}
            onRetry={retry}
          />
        ))}
      {loaded.length > 0 && (
        <>
          {failed.map(({ platform, error }) => (
            <PlatformNotice
              key={platform}
              platform={platform}
              error={error}
              waiting={waiting}
              onRetry={retry}
            />
          ))}
          {empty ? (
            <NoRepositories platform={several ? null : loaded[0]!.platform} />
          ) : (
            <>
              <div className={styles.repositoryList}>
                {matches.map(repo => (
                  <RepositoryCard
                    key={`${repo.provider}:${repo.id}`}
                    repository={repo}
                    showPlatform={several}
                  />
                ))}
              </div>
              {items.length === 0 && (
                <p className={styles.empty}>No repositories are available on this page.</p>
              )}
              {items.length > 0 && matches.length === 0 && (
                <p className={styles.empty}>No matches on this page.</p>
              )}
              <div className={styles.pagination}>
                <button
                  className={styles.secondary}
                  disabled={page === 1}
                  onClick={() => show(page - 1)}
                >
                  <ArrowLeft size={15} /> Previous
                </button>
                <span>Page {page}</span>
                <button
                  className={styles.secondary}
                  disabled={!nextPage}
                  onClick={() => show(page + 1)}
                >
                  Next <ArrowRight size={15} />
                </button>
              </div>
            </>
          )}
        </>
      )}
    </>
  )
}

function Heading({ onRefresh }: { onRefresh?: () => void }) {
  return (
    <header className={styles.heading}>
      <PageTitle>Repositories</PageTitle>
      <div>
        <p className={styles.eyebrow}>YOUR WORKSPACE</p>
        <h1 tabIndex={-1}>Repositories</h1>
        <p>Choose a repository. Share it on your terms.</p>
      </div>
      {onRefresh && (
        <button className={styles.secondary} onClick={onRefresh}>
          <RefreshCw size={16} /> Refresh repositories
        </button>
      )}
    </header>
  )
}

function ConnectFirst() {
  return (
    <section className={styles.panel}>
      <h2>Connect GitHub or GitLab to see your repositories.</h2>
      <p>
        Add a personal access token in Connections. Glimpse lists the repositories that token can
        read.
      </p>
      <div className={styles.actions}>
        <Link className={styles.primary} to={configureLink('github')}>
          Configure GitHub
        </Link>
        <Link className={styles.secondary} to={configureLink('gitlab')}>
          Configure GitLab
        </Link>
      </div>
    </section>
  )
}

/** Every platform answered with an empty first page; `platform` is null when several are selected. */
function NoRepositories({ platform }: { platform: Platform | null }) {
  return (
    <section className={styles.panel}>
      <h2>
        {platform
          ? 'No repositories available for this token.'
          : 'No repositories available on the selected platforms.'}
      </h2>
      <p>
        {platform
          ? `${platformLabels[platform]} accepted the token, but it does not list any repositories. Check the token’s access, then update it in Connections.`
          : 'Your tokens were accepted, but they do not list any repositories. Check their access, then update them in Connections.'}
      </p>
      <div className={styles.actions}>
        <Link className={styles.secondary} to="/app/connections">
          Open Connections
        </Link>
      </div>
    </section>
  )
}
