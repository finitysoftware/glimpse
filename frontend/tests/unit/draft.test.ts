import { describe, expect, it } from 'vitest'
import {
  applyOperation,
  checkState,
  countByFolder,
  countInBranch,
  draftChanges,
  draftModel,
  folderCount,
  indexFiles,
  parents,
  startDraft,
  within,
  type Draft,
  type VisibilityProfile,
} from '../../src/features/repositories/visibility/draft'

const manifest = ['README.md', 'src/app.ts', 'src/lib/a.ts', 'src/lib/b.ts', 'docs/guide.md']

const profile: VisibilityProfile = {
  branch: 'main',
  headSha: 'a'.repeat(40),
  mode: 'selected',
  version: 3,
  sourceSha: null,
  treeChanged: false,
  selected: ['src/lib/a.ts', 'gone.txt'],
  missing: ['gone.txt'],
  manifest,
  fileCount: manifest.length,
  truncated: false,
  limits: { paths: 1000, bytes: 65536, operations: 100 },
}

function model(draft: Draft, saved = profile) {
  return draftModel(saved, draft, indexFiles(saved.manifest))
}

describe('paths', () => {
  it('matches folders by whole path segments', () => {
    expect(within('src/app.ts', 'src')).toBe(true)
    expect(within('src-secret/key.txt', 'src')).toBe(false)
    expect(within('anything', '')).toBe(true)
  })

  it('lists the folders that contain a path and counts files per folder', () => {
    expect(parents('src/lib/a.ts')).toEqual(['', 'src', 'src/lib'])
    expect(parents('README.md')).toEqual([''])
    const counts = countByFolder(manifest)
    expect(counts.get('')).toBe(5)
    expect(counts.get('src')).toBe(3)
    expect(counts.get('src/lib')).toBe(2)
    expect(indexFiles(null)).toBeNull()
  })
})

describe('startDraft', () => {
  it('starts from the saved rules, and keeps the saved selection only for Selected files', () => {
    expect([...startDraft(profile).selected]).toEqual(['src/lib/a.ts', 'gone.txt'])
    expect(startDraft(profile, 'all')).toMatchObject({ mode: 'all', operations: [] })
    expect(startDraft(profile, 'all').selected.size).toBe(0)
    const all = { ...profile, mode: 'all' as const }
    expect(startDraft(all, 'selected').selected.size).toBe(0)
    expect(startDraft({ ...profile, mode: null }).mode).toBe('selected')
  })
})

describe('checkState and folderCount', () => {
  it('derives files and folders from the complete file list', () => {
    const view = model(startDraft(profile))
    expect(checkState(view, 'src/lib/a.ts', false)).toBe('checked')
    expect(checkState(view, 'src/lib/b.ts', false)).toBe('unchecked')
    expect(checkState(view, 'gone.txt', false)).toBe('unavailable')
    expect(checkState(view, 'src/lib', true)).toBe('mixed')
    expect(checkState(view, 'docs', true)).toBe('unchecked')
    expect(checkState(view, 'nowhere', true)).toBe('unavailable')
    // gone.txt is selected but not in the branch, so it counts nowhere.
    expect(folderCount(view, '')).toEqual({ selected: 1, total: 5 })
    expect(folderCount(view, 'src/lib')).toEqual({ selected: 1, total: 2 })
  })

  it('checks everything for the entire branch and nothing it cannot prove', () => {
    const all = model(startDraft(profile, 'all'))
    expect(checkState(all, 'docs', true)).toBe('checked')
    expect(folderCount(all, 'src')).toEqual({ selected: 3, total: 3 })
    const truncated = model(startDraft(profile), { ...profile, truncated: true })
    expect(checkState(truncated, 'src/lib/a.ts', false)).toBe('unavailable')
    expect(folderCount(truncated, 'src')).toBeNull()
    const large = model(startDraft(profile), { ...profile, manifest: null })
    expect(checkState(large, 'src/lib/b.ts', false)).toBe('unchecked')
    expect(checkState(large, 'src', true)).toBe('unavailable')
    expect(folderCount(large, 'src')).toBeNull()
  })
})

describe('applyOperation', () => {
  it('selects a folder with everything in it and records each operation', () => {
    const draft = applyOperation(model(startDraft(profile)), 'select', 'src', true)
    expect([...draft.selected].sort()).toEqual(
      ['gone.txt', 'src/app.ts', 'src/lib/a.ts', 'src/lib/b.ts'].sort(),
    )
    expect(draft.operations).toEqual([{ action: 'select', path: 'src' }])
    const cleared = applyOperation(model(draft), 'deselect', 'src/lib', true)
    expect([...cleared.selected].sort()).toEqual(['gone.txt', 'src/app.ts'])
    expect(cleared.operations).toHaveLength(2)
  })

  it('selects a single file without touching the rest', () => {
    const draft = applyOperation(model(startDraft(profile)), 'select', 'README.md', false)
    expect(draft.selected.has('README.md')).toBe(true)
    expect(draft.selected.has('src/lib/a.ts')).toBe(true)
  })
})

describe('draftChanges and countInBranch', () => {
  it('lists added and removed files with the folders that contain them', () => {
    let draft = applyOperation(model(startDraft(profile)), 'select', 'docs/guide.md', false)
    draft = applyOperation(model(draft), 'deselect', 'src/lib/a.ts', false)
    const changes = draftChanges(profile, draft)
    expect(changes).toMatchObject({
      modeChanged: false,
      added: ['docs/guide.md'],
      removed: ['src/lib/a.ts'],
    })
    expect([...changes.paths].sort()).toEqual(
      ['', 'docs', 'docs/guide.md', 'src', 'src/lib', 'src/lib/a.ts'].sort(),
    )
  })

  it('reports a mode change without listing paths, and unsaved rules count as Selected files', () => {
    expect(draftChanges(profile, startDraft(profile, 'all'))).toMatchObject({
      modeChanged: true,
      added: [],
      removed: [],
    })
    const fresh = { ...profile, mode: null, selected: [] }
    expect(draftChanges(fresh, startDraft(fresh)).modeChanged).toBe(false)
    expect(draftChanges(fresh, startDraft(fresh, 'all')).modeChanged).toBe(true)
  })

  it('counts only files of the branch when the complete list is known', () => {
    expect(countInBranch(profile.selected, indexFiles(manifest))).toBe(1)
    expect(countInBranch(profile.selected, null)).toBe(2)
  })
})
