/**
 * File visibility of one branch as the owner edits it: the saved rules (profile) and a draft of changes.
 * Plain functions without React; useVisibility keeps the state and talks to the server, which applies the
 * same operations to its own file list and stays authoritative.
 */

export interface VisibilityProfile {
  branch: string
  headSha: string
  mode: 'selected' | 'all' | null
  version: number
  sourceSha: string | null
  treeChanged: boolean
  selected: string[]
  missing: string[]
  manifest: string[] | null
  fileCount: number
  truncated: boolean
  limits: { paths: number; bytes: number; operations: number }
}

export type CheckState = 'checked' | 'unchecked' | 'mixed' | 'unavailable'

export interface Operation {
  action: 'select' | 'deselect'
  path: string
}

export interface Draft {
  mode: 'selected' | 'all'
  selected: Set<string>
  /** What the owner did, in order; saving sends these instead of the whole selection. */
  operations: Operation[]
}

/** The complete file list of a branch with the number of files in each folder ('' is the root). */
export interface FileIndex {
  files: Set<string>
  folders: Map<string, number>
}

/** A draft with the rules it started from and its selected files per folder. */
export interface DraftModel {
  profile: VisibilityProfile
  draft: Draft
  /** Null when the branch has more files than a complete list can hold. */
  index: FileIndex | null
  chosen: Map<string, number>
}

/** Net difference between a draft and the saved rules. */
export interface DraftChanges {
  modeChanged: boolean
  added: string[]
  removed: string[]
  /** The saved selection. */
  saved: Set<string>
  /** Changed files and every folder that contains one, so a closed folder still shows the change. */
  paths: Set<string>
}

/** A folder path matches whole segments only; the root contains everything. */
export function within(path: string, folder: string): boolean {
  return folder === '' || path === folder || path.startsWith(folder + '/')
}

/** The folders that contain a path, from the root ('') down. */
export function parents(path: string): string[] {
  const parts = path.split('/').slice(0, -1)
  return ['', ...parts.map((_, index) => parts.slice(0, index + 1).join('/'))]
}

/** How many of the paths each folder contains. */
export function countByFolder(paths: Iterable<string>): Map<string, number> {
  const folders = new Map<string, number>()
  for (const path of paths)
    for (const parent of parents(path)) folders.set(parent, (folders.get(parent) ?? 0) + 1)
  return folders
}

export function indexFiles(manifest: string[] | null): FileIndex | null {
  if (!manifest) return null
  return { files: new Set(manifest), folders: countByFolder(manifest) }
}

/**
 * A draft without edits: the saved rules, or a fresh start in another mode. Only Selected files over a
 * saved selection keeps that selection.
 */
export function startDraft(profile: VisibilityProfile, mode = profile.mode ?? 'selected'): Draft {
  const keepSaved = mode === 'selected' && profile.mode === 'selected'
  return { mode, selected: new Set(keepSaved ? profile.selected : []), operations: [] }
}

export function draftModel(
  profile: VisibilityProfile,
  draft: Draft,
  index: FileIndex | null,
): DraftModel {
  // Selected paths that are no longer in the branch count nowhere.
  const inBranch = index ? [...draft.selected].filter(path => index.files.has(path)) : []
  return { profile, draft, index, chosen: countByFolder(inBranch) }
}

/** What the checkbox of a file or folder shows. */
export function checkState(model: DraftModel, path: string, folder: boolean): CheckState {
  const { profile, draft, index, chosen } = model
  // An incomplete file list cannot prove individual paths; only the entire branch remains possible.
  if (profile.truncated) return draft.mode === 'all' ? 'checked' : 'unavailable'
  if (!folder) {
    if (index && !index.files.has(path)) return 'unavailable'
    return draft.mode === 'all' || draft.selected.has(path) ? 'checked' : 'unchecked'
  }
  // Folder state comes from the complete file list, never from the folders loaded in the tree.
  const total = index?.folders.get(path) ?? 0
  if (total === 0) return 'unavailable'
  if (draft.mode === 'all') return 'checked'
  const selected = chosen.get(path) ?? 0
  if (selected === 0) return 'unchecked'
  return selected === total ? 'checked' : 'mixed'
}

/** Selected and total files under a folder; null without a complete file list. */
export function folderCount(
  model: DraftModel,
  path: string,
): { selected: number; total: number } | null {
  const total = model.index?.folders.get(path) ?? 0
  if (total === 0 || model.profile.truncated) return null
  const selected = model.draft.mode === 'all' ? total : (model.chosen.get(path) ?? 0)
  return { selected, total }
}

/** The draft after selecting or clearing a file, or a folder with everything in it. */
export function applyOperation(
  model: DraftModel,
  action: Operation['action'],
  path: string,
  folder: boolean,
): Draft {
  const { draft, profile } = model
  const selected = new Set(draft.selected)
  if (action === 'deselect') {
    for (const file of draft.selected) if (within(file, path)) selected.delete(file)
  } else if (!folder) selected.add(path)
  else for (const file of profile.manifest ?? []) if (within(file, path)) selected.add(file)
  return { ...draft, selected, operations: [...draft.operations, { action, path }] }
}

export function draftChanges(profile: VisibilityProfile, draft: Draft): DraftChanges {
  const saved = new Set(profile.mode === 'selected' ? profile.selected : [])
  // Rules that were never saved count as Selected files with nothing selected.
  const modeChanged = draft.mode !== (profile.mode ?? 'selected')
  if (modeChanged || draft.mode === 'all')
    return { modeChanged, added: [], removed: [], saved, paths: new Set() }
  const added = [...draft.selected].filter(path => !saved.has(path)).sort()
  const removed = [...saved].filter(path => !draft.selected.has(path)).sort()
  const paths = new Set<string>()
  for (const path of [...added, ...removed]) {
    paths.add(path)
    for (const parent of parents(path)) paths.add(parent)
  }
  return { modeChanged, added, removed, saved, paths }
}

/** How many of the paths are files of the branch; every path counts without a complete file list. */
export function countInBranch(paths: Iterable<string>, index: FileIndex | null): number {
  const list = [...paths]
  return index ? list.filter(path => index.files.has(path)).length : list.length
}
