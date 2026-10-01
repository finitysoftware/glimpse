import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, errorMessage } from '../../../api/client'
import { loadVisibility, repositoryKeys, saveVisibility } from '../api'
import {
  applyOperation,
  checkState,
  countInBranch,
  draftChanges,
  draftModel,
  folderCount,
  indexFiles,
  startDraft,
  type CheckState,
  type Draft,
  type Operation,
  type VisibilityProfile,
} from './draft'

export interface Visibility {
  branch: string
  profile: VisibilityProfile | null
  loading: boolean
  saving: boolean
  error: string
  message: string
  conflict: boolean
  mode: 'selected' | 'all'
  count: number
  /** Files of this branch that visitors of new links could open with the draft, and with the saved rules. */
  visible: number
  savedVisible: number
  dirty: boolean
  /** Net difference from the saved rules; paths are not listed when the mode itself changes. */
  changes: { modeChanged: boolean; added: string[]; removed: string[] }
  state: (path: string, folder: boolean) => CheckState
  /** Draft-selected and total files under a folder ('' is the root), from the complete file list; NULL without one. */
  folderCount: (path: string) => { selected: number; total: number } | null
  /** Whether the draft differs from the saved rules at this path, or for a folder anywhere inside it. */
  changed: (path: string, folder: boolean) => boolean
  toggle: (path: string, folder: boolean) => void
  apply: (action: 'select' | 'deselect', path: string, folder: boolean) => void
  setMode: (mode: 'selected' | 'all') => void
  save: () => Promise<boolean>
  cancel: () => void
  reload: () => void
}

/** Draft of one branch's visibility: loads the saved rules, keeps the owner's edits and saves them. */
export function useVisibility(
  endpoint: `/api/${string}`,
  branch: string,
  csrfToken: string,
  enabled: boolean,
): Visibility {
  const queryClient = useQueryClient()
  const queryKey = repositoryKeys.visibility(endpoint, branch)
  // The draft is edited against one loaded version, so nothing reloads it in the background: only
  // Reload and Save replace it, and the editor loads it fresh every time it opens.
  const loaded = useQuery({
    queryKey,
    queryFn: ({ signal }) => loadVisibility(endpoint, branch, signal),
    enabled: enabled && branch !== '',
    staleTime: Infinity,
    gcTime: 0,
  })
  const profile = loaded.data ?? null
  // Edits belong to the profile they started from; a saved or reloaded profile starts a new draft.
  const [edits, setEdits] = useState<{ profile: VisibilityProfile; draft: Draft } | null>(null)
  const savedDraft = useMemo(() => (profile ? startDraft(profile) : null), [profile])
  const draft = edits && edits.profile === profile ? edits.draft : savedDraft
  const [message, setMessage] = useState('')
  const {
    mutateAsync: saveDraft,
    isPending: saving,
    error: saveFailure,
    reset: resetSave,
  } = useMutation({
    mutationFn: ({ from, draft }: { from: VisibilityProfile; draft: Draft }) =>
      saveVisibility(
        endpoint,
        {
          branch,
          expectedVersion: from.version,
          sourceSha: from.headSha,
          mode: draft.mode,
          operations: draft.mode === 'all' ? [] : draft.operations,
        },
        csrfToken,
      ),
    onMutate: () => setMessage(''),
    onSuccess: saved => {
      queryClient.setQueryData(queryKey, saved)
      setEdits(null)
      setMessage('Visibility saved. It applies to new links.')
    },
  })

  // Counting a large file list is the expensive part, so it runs once per loaded profile and draft.
  const index = useMemo(() => indexFiles(profile?.manifest ?? null), [profile])
  const model = useMemo(
    () => (profile && draft ? draftModel(profile, draft, index) : null),
    [profile, draft, index],
  )
  const changes = useMemo(
    () => (profile && draft ? draftChanges(profile, draft) : null),
    [profile, draft],
  )

  function setDraft(next: Draft) {
    if (profile) setEdits({ profile, draft: next })
  }

  function apply(action: Operation['action'], path: string, folder: boolean) {
    if (!model || model.draft.mode === 'all') return
    const limit = model.profile.limits.operations
    if (model.draft.operations.length >= limit) {
      setMessage(`Save these ${limit} changes before making more.`)
      return
    }
    setMessage('')
    setDraft(applyOperation(model, action, path, folder))
  }

  function state(path: string, folder: boolean): CheckState {
    return model ? checkState(model, path, folder) : 'unavailable'
  }

  async function save() {
    if (!draft || !profile || saving) return false
    try {
      await saveDraft({ from: profile, draft })
      return true
    } catch {
      return false
    }
  }

  function discard() {
    setEdits(null)
    resetSave()
    setMessage('')
  }

  const fileCount = profile?.fileCount ?? 0
  return {
    branch,
    profile,
    loading: enabled && branch !== '' && loaded.isPending,
    saving,
    error:
      errorMessage(loaded.error, 'Unable to load file visibility.') ||
      errorMessage(saveFailure, 'Unable to save file visibility.'),
    message,
    conflict: saveFailure instanceof ApiError && saveFailure.status === 409,
    mode: draft?.mode ?? 'selected',
    count: draft?.mode === 'all' ? fileCount : (draft?.selected.size ?? 0),
    dirty: draft !== null && (draft.operations.length > 0 || changes?.modeChanged === true),
    visible: draft?.mode === 'all' ? fileCount : countInBranch(draft?.selected ?? [], index),
    savedVisible: profile?.mode === 'all' ? fileCount : countInBranch(changes?.saved ?? [], index),
    changes: {
      modeChanged: changes?.modeChanged ?? false,
      added: changes?.added ?? [],
      removed: changes?.removed ?? [],
    },
    state,
    folderCount: path => (model ? folderCount(model, path) : null),
    // Git paths are either files or folders, so one set answers both.
    changed: path => changes?.paths.has(path) ?? false,
    // A mixed folder becomes fully selected; a checked one is cleared.
    toggle: (path, folder) =>
      apply(state(path, folder) === 'checked' ? 'deselect' : 'select', path, folder),
    apply,
    setMode: mode => {
      if (profile && draft && draft.mode !== mode) setDraft(startDraft(profile, mode))
    },
    save,
    cancel: () => {
      if (profile) discard()
    },
    reload: () => {
      discard()
      void queryClient.resetQueries({ queryKey })
    },
  }
}
