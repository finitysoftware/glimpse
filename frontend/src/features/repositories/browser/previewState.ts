import type { PreviewQuery } from './usePreview'

/**
 * What one preview keeps besides the loaded data and the address. The address holds the folder, file or search on
 * screen, so Back, Forward and reload return to it; a new identity (branch, History, another page) starts over.
 */
export interface PreviewState {
  /**
   * For each address the owner moved to without a revision of its own: the revision and branch on screen at that
   * moment. Back and Forward then stay at the commit of the tree; a reload forgets them and reads the branch again.
   */
  pins: Record<string, Revision>
  /** Refresh at one address: that address checks this revision again, once without the server cache. */
  refreshed: (Revision & { at: string; ref: string; page: number; cursor: string }) | null
  /** Refresh and Try again count up; each starts a new check. */
  revision: number
  /** Text typed into the search field while the address searched for `at`; another search in the address replaces it. */
  typed: { at: string; text: string } | null
  /** The tree is shown: the owner opened it, or started editing file visibility, which opens it too. */
  tree: boolean
  /** File visibility is being edited (owners only). */
  editing: boolean
  /** Closing file visibility with unsaved rules waits for a decision; leaving the preview waits in the router's blocker. */
  pending: boolean
}

/** A commit and the branch it belongs to; an empty sha is the head of the branch. */
export interface Revision {
  sha: string
  branch: string
}

export type PreviewAction =
  | ({ type: 'pin'; at: string } & Revision)
  | { type: 'unpin' }
  | { type: 'refresh'; at: string; check: Revision & { ref: string; page: number; cursor: string } }
  | { type: 'retry' }
  | { type: 'typeSearch'; at: string; text: string }
  | { type: 'toggleTree' }
  | { type: 'closeTree'; unsaved: boolean }
  | { type: 'hideTree' }
  | { type: 'toggleEditing'; unsaved: boolean }
  | { type: 'decided' }
  | { type: 'stopEditing' }

/** The preview an address asks for: a folder (the default), a file, search results or History. */
export function queryFromAddress(address: URLSearchParams): PreviewQuery {
  let kind = 'directory'
  if (address.get('view') === 'history') kind = 'history'
  else if (address.get('kind') === 'file' || address.get('kind') === 'search')
    kind = address.get('kind')!
  return {
    path: address.get('path') ?? '',
    kind,
    sha: address.get('sha') ?? '',
    ref: address.get('ref') ?? '',
    branch: address.get('branch') ?? '',
    q: kind === 'search' ? (address.get('q') ?? '') : '',
    page: Math.max(1, Number(address.get('page') ?? 1) || 1),
    cursor: address.get('cursor') ?? '',
    revision: 0,
    refresh: false,
  }
}

/** One spelling of an address, with or without its leading '?', for remembering things per address. */
const addressKey = (address: string) => new URLSearchParams(address).toString()

/** The check for the address on screen: the address, the pinned revision where it names none, and Refresh or Try again. */
export function previewQuery(
  address: string,
  state: Pick<PreviewState, 'pins' | 'refreshed' | 'revision'>,
): PreviewQuery {
  const query = queryFromAddress(new URLSearchParams(address))
  const key = addressKey(address)
  const refreshed = state.refreshed?.at === key ? state.refreshed : null
  if (refreshed) {
    const { sha, branch, ref, page, cursor } = refreshed
    return { ...query, sha, branch, ref, page, cursor, revision: state.revision, refresh: true }
  }
  const pinned = state.pins[key]
  return {
    ...query,
    sha: query.sha || (pinned?.sha ?? ''),
    branch: query.branch || (pinned?.branch ?? ''),
    revision: state.revision,
  }
}

/** Parameters that move within one Files preview: its folder, file, search, revision and the editing switch. */
const withinFiles = ['path', 'kind', 'q', 'page', 'sha', 'view', 'visibility']

/**
 * Which preview an address belongs to. Folders, files and searches of one branch share a preview, so the tree and a
 * visibility draft stay while the owner moves around; another branch, History or a tab is another preview. Every
 * History address is its own preview, as each opens at a checked revision.
 */
export function previewIdentity(address: string): string {
  const params = new URLSearchParams(address)
  if (params.get('view') === 'history') return `history:${params.toString()}`
  for (const name of withinFiles) params.delete(name)
  params.sort()
  return `files:${params.toString()}`
}

/**
 * The place of an address in the words of the browser tab: a file or folder by its name, a search by its text, or
 * History (of a file); '' at the root. Steps of Back and Forward then read differently.
 */
export function placeTitle(address: string): string {
  const params = new URLSearchParams(address)
  const name = (params.get('path') ?? '').split('/').filter(Boolean).at(-1) ?? ''
  if (params.get('view') === 'history') return name ? `History of ${name}` : 'History'
  if (params.get('kind') === 'search') return `Search: ${params.get('q') ?? ''}`
  return name
}

/** Whether two addresses name the same place, whatever the order of their parameters. */
export function sameAddress(a: string, b: string): boolean {
  const sorted = (address: string) => {
    const params = new URLSearchParams(address)
    params.sort()
    return params.toString()
  }
  return sorted(a) === sorted(b)
}

/** A place within the Files preview: a folder ('' is the root), a file, or a page of search results. */
export type Place =
  { kind: 'directory' | 'file'; path: string } | { kind: 'search'; q: string; page: number }

/** The address of another place in the same Files preview; the rest of the address (branch, revision, editing) stays. */
export function placeAddress(address: string, place: Place): URLSearchParams {
  const next = new URLSearchParams(address)
  for (const name of ['path', 'kind', 'q', 'page']) next.delete(name)
  if (place.kind === 'search') {
    next.set('kind', 'search')
    next.set('q', place.q)
    if (place.page > 1) next.set('page', String(place.page))
    return next
  }
  if (place.path) next.set('path', place.path)
  if (place.kind === 'file') next.set('kind', 'file')
  return next
}

export function initialPreview(address: URLSearchParams, canEdit: boolean): PreviewState {
  const editing = canEdit && address.get('visibility') === '1'
  return {
    pins: {},
    refreshed: null,
    revision: 0,
    typed: null,
    tree: editing,
    editing,
    pending: false,
  }
}

export function previewReducer(state: PreviewState, action: PreviewAction): PreviewState {
  switch (action.type) {
    case 'pin': {
      const at = addressKey(action.at)
      const pinned = state.pins[at]
      if (pinned?.sha === action.sha && pinned.branch === action.branch) return state
      return { ...state, pins: { ...state.pins, [at]: { sha: action.sha, branch: action.branch } } }
    }
    case 'unpin':
      return { ...state, pins: {} }
    // Refresh checks the branch again without the server cache; a historical revision stays pinned. The other
    // addresses of the preview forget their revisions, so they open at the refreshed branch too.
    case 'refresh':
      return {
        ...state,
        pins: {},
        refreshed: { ...action.check, at: addressKey(action.at) },
        revision: state.revision + 1,
      }
    case 'retry':
      return { ...state, revision: state.revision + 1 }
    case 'typeSearch':
      return { ...state, typed: { at: action.at, text: action.text } }
    case 'toggleTree':
      return { ...state, tree: !state.tree }
    // While editing, the tree holds the checkboxes, so closing it closes editing.
    case 'closeTree':
      if (state.editing)
        return previewReducer(state, { type: 'toggleEditing', unsaved: action.unsaved })
      return { ...state, tree: false }
    // On phones a file opened from the drawer puts the tree away; editing stays open with its panel and save bar.
    case 'hideTree':
      return { ...state, tree: false }
    case 'toggleEditing':
      if (!state.editing) return { ...state, editing: true, tree: true }
      if (action.unsaved) return { ...state, pending: true }
      return { ...state, editing: false }
    case 'decided':
      return { ...state, pending: false }
    case 'stopEditing':
      return { ...state, editing: false }
  }
}

/** The search field: what the owner typed for the search in the address, or that search. */
export function searchText(state: PreviewState, q: string): string {
  return state.typed?.at === q ? state.typed.text : q
}

/** Where Files opens again after History. */
export interface ReturnFile {
  path: string
  kind: string
}

/** The file or folder an address of History returns to. */
export function returnFileOf(address: URLSearchParams): ReturnFile {
  return { path: address.get('returnPath') ?? '', kind: address.get('returnKind') ?? 'directory' }
}

/** The address of History: commits from a revision, optionally one commit opened, and the way back to Files. */
export function historyAddress(history: {
  path: string
  sha: string
  ref?: string
  branch: string
  page: number
  cursor: string
  commit: string
  returnTo: ReturnFile
}): URLSearchParams {
  const address = new URLSearchParams({ view: 'history', path: history.path, sha: history.sha })
  if (history.ref !== undefined) address.set('ref', history.ref)
  address.set('branch', history.branch)
  address.set('page', String(history.page))
  address.set('cursor', history.cursor)
  address.set('commit', history.commit)
  address.set('returnPath', history.returnTo.path)
  address.set('returnKind', history.returnTo.kind)
  return address
}

/** The address of a folder or file at a revision. */
export function filesAddress(file: ReturnFile & { sha: string; branch: string }): URLSearchParams {
  return new URLSearchParams({
    view: 'files',
    path: file.path,
    kind: file.kind,
    sha: file.sha,
    branch: file.branch,
  })
}
