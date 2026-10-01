import { useContext, useEffect, useMemo, useReducer, useState, type FormEvent } from 'react'
import { FileCode2, History as HistoryIcon } from 'lucide-react'
import { useBlocker, useLocation, useNavigate, type Location } from 'react-router-dom'
import { clsx } from 'clsx'
import { SignOutHoldContext } from '../auth/session'
import type { Preview } from './api'
import { PreviewContent } from './browser/PreviewContent'
import { BranchSearchForm, PreviewToolbar } from './browser/PreviewToolbar'
import { UnsavedChangesDialog, type UnsavedChoice } from './browser/UnsavedChangesDialog'
import {
  filesAddress,
  historyAddress,
  initialPreview,
  placeAddress,
  previewIdentity,
  previewQuery,
  previewReducer,
  returnFileOf,
  sameAddress,
  searchText,
  type Place,
} from './browser/previewState'
import { usePreview } from './browser/usePreview'
import { History } from './history/History'
import { RepositoryTree } from './tree/RepositoryTree'
import { useBranches } from './useBranches'
import { useResizablePane } from './useResizablePane'
import { useVisibility } from './visibility/useVisibility'
import { VisibilityPanel, VisibilitySaveBar } from './visibility/VisibilityPanel'
import styles from './Repositories.module.css'

/** Owner-only editing of per-branch file visibility; visitors never receive it. */
export interface VisibilityEditor {
  endpoint: `/api/${string}`
  csrfToken: string
}

/** Called with the repository fields of each loaded preview, e.g. for a page heading. */
type RepositoryListener = (repository: Preview['repository']) => void

export function RepositoryBrowser({
  endpoint,
  visibility,
  onRepository,
}: {
  endpoint: `/api/${string}`
  visibility?: VisibilityEditor
  onRepository?: RepositoryListener
}) {
  const location = useLocation()
  // Another branch, History or tab starts a fresh preview; folders, files and searches of the branch share one.
  return (
    <RepositoryPreview
      key={`${endpoint}:${previewIdentity(location.search)}`}
      endpoint={endpoint}
      visibility={visibility}
      onRepository={onRepository}
    />
  )
}

function RepositoryPreview({
  endpoint,
  visibility: editor,
  onRepository,
}: {
  endpoint: `/api/${string}`
  visibility?: VisibilityEditor
  onRepository?: RepositoryListener
}) {
  const location = useLocation()
  const navigate = useNavigate()
  const signOutHold = useContext(SignOutHoldContext)
  // The address this preview opened with (History uses it; another History address is another preview).
  const [initial] = useState(() => new URLSearchParams(location.search))
  const [state, dispatch] = useReducer(previewReducer, initial, address =>
    initialPreview(address, editor !== undefined),
  )
  const { editing, pending } = state
  // The folder, file or search in the address, at the pinned revision; stable while neither changes.
  const query = useMemo(
    () =>
      previewQuery(location.search, {
        pins: state.pins,
        refreshed: state.refreshed,
        revision: state.revision,
      }),
    [location.search, state.pins, state.refreshed, state.revision],
  )
  const typedSearch = searchText(state, query.q)
  const isHistory = query.kind === 'history'
  const branches = useBranches(endpoint, query.revision)
  const { container, paneStyle, separator } = useResizablePane(
    'Resize files panel',
    280,
    !isHistory,
  )
  const { data, snapshot, moved, loading, error, cooldown, fail } = usePreview(
    endpoint,
    query,
    initial,
    onRepository,
  )
  const selectedBranch = query.branch || data?.branch || branches.data?.defaultBranch || ''
  const allowHistory = data?.allowHistory ?? branches.data?.allowHistory ?? false
  const visibility = useVisibility(
    editor?.endpoint ?? '/api/unavailable',
    selectedBranch,
    editor?.csrfToken ?? '',
    editing && selectedBranch !== '',
  )
  const unsaved = editing && visibility.dirty
  // Sign out waiting for the decision about unsaved rules.
  const [signingOut, setSigningOut] = useState<{ signOut: () => void } | null>(null)
  const treeOpen = state.tree && snapshot !== undefined
  // Files opens again where the owner left for History.
  const returnTo = isHistory ? returnFileOf(initial) : { path: query.path, kind: query.kind }
  // Every way out of this preview while its rules are unsaved - another branch, File history, Access links, a link of
  // the page or the app, Back and Forward to them, and Sign out - waits for the same decision as closing file
  // visibility. Other folders, files and searches of the branch keep the draft, so they never ask.
  const blocker = useBlocker(
    ({ currentLocation, nextLocation }) =>
      unsaved &&
      (currentLocation.pathname !== nextLocation.pathname ||
        previewIdentity(currentLocation.search) !== previewIdentity(nextLocation.search)),
  )
  useEffect(() => {
    if (!unsaved) return
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault()
    }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [unsaved])
  // Sign out is no navigation; while rules are unsaved it waits for the same decision.
  useEffect(() => {
    if (!unsaved || !signOutHold) return
    return signOutHold.hold(signOut => setSigningOut({ signOut }))
  }, [unsaved, signOutHold])
  // History opened by its address moves the address to the revision the server checked.
  useEffect(() => {
    if (!moved) return
    const address = historyAddress({
      path: query.path,
      sha: moved.commitSha,
      ref: query.ref,
      branch: moved.branch,
      page: query.page,
      cursor: query.cursor,
      commit: query.revision > 0 ? '' : (initial.get('commit') ?? ''),
      returnTo: returnFileOf(initial),
    })
    void navigate({ search: address.toString() }, { replace: true })
  }, [moved, initial, navigate, query])

  /**
   * Opens another address in this repository; another branch, History or tab starts a new preview. The place already
   * on screen (a file clicked again, Root at the root, the same search) replaces its own step, so Back never stays put.
   */
  function go(address: URLSearchParams) {
    const search = address.toString()
    void navigate({ search }, { replace: sameAddress(search, location.search) })
  }

  /** Opens a folder, file or search at the revision on screen; the address keeps it for Back, Forward and reload. */
  function place(next: Place) {
    const address = placeAddress(location.search, next)
    // A revision the address names follows the one on screen, e.g. after Refresh moved to the head of the branch.
    if (address.has('sha') && snapshot) address.set('sha', snapshot.commitSha)
    const at = address.toString()
    dispatch({ type: 'pin', at, sha: snapshot?.commitSha || query.sha, branch: selectedBranch })
    go(address)
  }

  function open(path: string, kind: 'directory' | 'file' = 'directory') {
    place({ kind, path })
  }

  function search(event: FormEvent) {
    event.preventDefault()
    place({ kind: 'search', q: typedSearch.trim(), page: 1 })
  }

  /** Leaves a revision the address or a pin held for the head of the branch on screen. */
  function toBranchHead() {
    dispatch({ type: 'unpin' })
    const branch = selectedBranch
    go(new URLSearchParams(editing ? { branch, visibility: '1' } : { branch }))
  }

  function changeBranch(branch: string) {
    if (branch === '~more') return branches.loadMore()
    // Rules of one branch are never carried to another; the blocker asks about unsaved changes first.
    go(new URLSearchParams(editing ? { branch, visibility: '1' } : { branch }))
  }

  async function decide(choice: UnsavedChoice) {
    // Saving keeps the dialog open, and busy, until the rules are stored; a failed save stays with its error.
    const saved = choice === 'save' ? await visibility.save() : true
    const leave = choice !== 'stay' && saved
    if (blocker.state === 'blocked') {
      // Leaving starts another preview (or page), so discarded rules go with this one.
      if (leave) blocker.proceed()
      else blocker.reset()
      return
    }
    if (signingOut) {
      // Signing out ends this page; a sign out that fails keeps the rules as they are.
      setSigningOut(null)
      if (leave) signingOut.signOut()
      return
    }
    dispatch({ type: 'decided' })
    if (!leave) return
    if (choice === 'discard') visibility.cancel()
    dispatch({ type: 'stopEditing' })
  }

  function showHistory(path = '', page = 1, commit = '', cursor = '') {
    const sha = data?.commitSha ?? query.sha
    go(historyAddress({ path, sha, branch: selectedBranch, page, cursor, commit, returnTo }))
  }

  function showFiles() {
    go(filesAddress({ ...returnTo, sha: data?.commitSha ?? query.sha, branch: selectedBranch }))
  }

  function browseCommit(sha: string, path = '') {
    const kind = path ? 'file' : 'directory'
    go(filesAddress({ path, kind, sha, branch: selectedBranch }))
  }

  return (
    <section className={styles.browser} aria-label="Repository preview">
      <PreviewToolbar
        title={data?.repository.fullName || 'Repository preview'}
        refreshDisabled={loading || cooldown > 0}
        onRefresh={() =>
          dispatch({
            type: 'refresh',
            at: location.search,
            check: {
              sha: data?.historical ? data.commitSha : '',
              branch: selectedBranch,
              ref: '',
              // History starts again at its newest commits; search results stay on their page.
              page: isHistory ? 1 : query.page,
              cursor: '',
            },
          })
        }
        tree={{
          open: state.tree,
          pressed: state.tree,
          // While editing, the tree holds the checkboxes: Tree only brings it back after a phone put it away.
          disabled:
            isHistory || (editing && state.tree) || (!state.tree && (!data || cooldown > 0)),
          onToggle: () => dispatch({ type: 'toggleTree' }),
        }}
        fileVisibility={
          editor && {
            pressed: editing,
            disabled: isHistory || (!editing && !data),
            onToggle: () => dispatch({ type: 'toggleEditing', unsaved }),
          }
        }
      />
      <BranchSearchForm
        branches={branches.data}
        branch={selectedBranch}
        branchDisabled={loading || branches.loading || cooldown > 0}
        search={typedSearch}
        searchDisabled={loading || cooldown > 0}
        searchLocked={editing}
        onBranch={changeBranch}
        onSearchChange={text => dispatch({ type: 'typeSearch', at: query.q, text })}
        onSearch={search}
      />
      {branches.error && !error && (
        <p role="alert" className={styles.notice}>
          {branches.error}
        </p>
      )}
      {data?.historical && (
        <p className={styles.notice}>
          Historical revision ·{' '}
          <button className={styles.secondary} onClick={toBranchHead}>
            Back to current branch
          </button>
        </p>
      )}
      <nav className={styles.viewTabs} aria-label="Repository views">
        <button
          aria-pressed={!isHistory}
          disabled={loading}
          onClick={() => {
            if (isHistory) showFiles()
          }}
        >
          <FileCode2 size={16} /> Files
        </button>
        {allowHistory && (
          <button
            aria-pressed={isHistory}
            disabled={loading || !data || editing}
            onClick={() => {
              if (!isHistory || query.path) showHistory()
            }}
          >
            <HistoryIcon size={16} /> History
          </button>
        )}
        {allowHistory && !isHistory && data?.file && (
          <button className={styles.fileHistory} onClick={() => showHistory(data.file!.path)}>
            <HistoryIcon size={14} /> File history
          </button>
        )}
      </nav>
      {editing && !isHistory && (
        <VisibilityPanel
          visibility={visibility}
          onClose={() => dispatch({ type: 'toggleEditing', unsaved })}
        />
      )}
      {(pending || blocker.state === 'blocked' || signingOut) && (
        <UnsavedChangesDialog
          branch={visibility.branch}
          before={
            blocker.state === 'blocked'
              ? destination(blocker.location, selectedBranch)
              : signingOut
                ? 'signing out'
                : 'closing file visibility'
          }
          saving={visibility.saving}
          onChoose={choice => void decide(choice)}
        />
      )}
      {isHistory ? (
        <>
          {loading && (
            <p role="status" className={styles.empty}>
              Loading history…
            </p>
          )}
          {data?.history && (
            <History
              key={`${data.commitSha}:${data.path}:${data.history.page}:${query.cursor}:${query.revision}`}
              endpoint={endpoint}
              snapshot={data}
              initialCommit={query.revision === 0 ? initial.get('commit') || undefined : undefined}
              onPage={page => showHistory(query.path, page)}
              onCursor={cursor => showHistory(query.path, 1, '', cursor)}
              onOpen={browseCommit}
              onFailure={fail}
              onCommit={sha => showHistory(query.path, query.page, sha, query.cursor)}
            />
          )}
        </>
      ) : (
        <div
          ref={container}
          style={paneStyle}
          className={clsx(styles.workspace, treeOpen && styles.withTree)}
        >
          {treeOpen && (
            <RepositoryTree
              key={snapshot.commitSha}
              endpoint={endpoint}
              snapshot={snapshot}
              selectedPath={query.path}
              onSelect={path => open(path, 'file')}
              onClose={() => dispatch({ type: 'closeTree', unsaved })}
              onHide={() => dispatch({ type: 'hideTree' })}
              onFailure={fail}
              visibility={editing ? visibility : undefined}
            />
          )}
          {treeOpen && separator}
          <PreviewContent
            data={data}
            path={query.path}
            search={{ q: query.q, page: query.page }}
            loading={loading}
            visibility={editing ? visibility : undefined}
            onOpen={open}
            onClearSearch={() => {
              dispatch({ type: 'typeSearch', at: '', text: '' })
              open('')
            }}
            onSearchPage={page => place({ kind: 'search', q: query.q, page })}
          />
        </div>
      )}
      {editing && !isHistory && (visibility.dirty || visibility.saving) && (
        <VisibilitySaveBar visibility={visibility} />
      )}
      {error && (
        <div className={styles.error}>
          <p role="alert">{error}</p>
          <button
            className={styles.secondary}
            disabled={cooldown > 0}
            onClick={() => dispatch({ type: 'retry' })}
          >
            {cooldown > 0 ? `Try again in ${cooldown}s` : 'Try again'}
          </button>
        </div>
      )}
    </section>
  )
}

/** Where a held-back navigation would go, in the words of the unsaved changes question. */
function destination(next: Location, branch: string): string {
  const address = new URLSearchParams(next.search)
  if (address.get('tab') === 'links') return 'opening Access links'
  const nextBranch = address.get('branch')
  if (nextBranch && nextBranch !== branch) return `opening ${nextBranch}`
  if (address.get('view') === 'history') return 'opening History'
  return 'leaving this page'
}
