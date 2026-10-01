import { describe, expect, it } from 'vitest'
import {
  filesAddress,
  historyAddress,
  initialPreview,
  placeAddress,
  previewIdentity,
  previewQuery,
  previewReducer,
  queryFromAddress,
  placeTitle,
  returnFileOf,
  sameAddress,
  searchText,
  type PreviewState,
} from '../../src/features/repositories/browser/previewState'

const sha = 'a'.repeat(40)
const later = 'b'.repeat(40)

function start(address = '', canEdit = true): PreviewState {
  return initialPreview(new URLSearchParams(address), canEdit)
}

describe('queryFromAddress', () => {
  it('opens the repository root by default', () => {
    expect(queryFromAddress(new URLSearchParams())).toEqual({
      path: '',
      kind: 'directory',
      sha: '',
      ref: '',
      branch: '',
      q: '',
      page: 1,
      cursor: '',
      revision: 0,
      refresh: false,
    })
  })

  it('reads a file, a search and History from the address', () => {
    const file = queryFromAddress(new URLSearchParams('view=files&path=a.md&kind=file&branch=dev'))
    expect(file).toMatchObject({ path: 'a.md', kind: 'file', branch: 'dev' })
    const search = queryFromAddress(new URLSearchParams('kind=search&q=app&page=3'))
    expect(search).toMatchObject({ kind: 'search', q: 'app', page: 3, path: '' })
    const history = queryFromAddress(new URLSearchParams(`view=history&sha=${sha}&page=3&cursor=c`))
    expect(history).toMatchObject({ kind: 'history', sha, page: 3, cursor: 'c' })
  })

  it('ignores a search text outside search results and a page that is no page', () => {
    expect(queryFromAddress(new URLSearchParams('path=src&q=app&page=x'))).toMatchObject({
      kind: 'directory',
      q: '',
      page: 1,
    })
  })
})

describe('previewQuery', () => {
  it('opens addresses without a revision at the pinned one, and keeps a revision the address names', () => {
    const pinned = previewReducer(start(), { type: 'pin', at: 'path=src', sha, branch: 'main' })
    expect(previewQuery('path=src', pinned)).toMatchObject({ path: 'src', sha, branch: 'main' })
    // The router spells addresses with a leading '?'.
    expect(previewQuery('?path=src', pinned)).toMatchObject({ sha, branch: 'main' })
    // The address the owner left keeps the revision it opened at, so going back needs no new check.
    expect(previewQuery('', pinned)).toMatchObject({ sha: '', branch: '' })
    const named = previewReducer(pinned, {
      type: 'pin',
      at: `sha=${later}&branch=dev`,
      sha,
      branch: 'main',
    })
    expect(previewQuery(`sha=${later}&branch=dev`, named)).toMatchObject({
      sha: later,
      branch: 'dev',
    })
    expect(previewQuery('path=src', previewReducer(pinned, { type: 'unpin' }))).toMatchObject({
      sha: '',
      branch: '',
    })
  })

  it('refreshes only the address Refresh was pressed at, once without the server cache', () => {
    const check = { sha: '', branch: 'main', ref: '', page: 1, cursor: '' }
    const pinned = previewReducer(start(), { type: 'pin', at: 'path=docs', sha, branch: 'main' })
    const state = previewReducer(pinned, { type: 'refresh', at: `path=src&sha=${sha}`, check })
    expect(previewQuery(`path=src&sha=${sha}`, state)).toMatchObject({
      path: 'src',
      sha: '',
      branch: 'main',
      revision: 1,
      refresh: true,
    })
    // Elsewhere the preview forgets its revisions and opens at the head, without skipping the server cache.
    expect(previewQuery('path=docs', state)).toMatchObject({
      sha: '',
      branch: '',
      revision: 1,
      refresh: false,
    })
  })
})

describe('previewIdentity', () => {
  it('keeps one Files preview for its folders, files, searches, revisions and editing switch', () => {
    const files = previewIdentity('branch=dev')
    for (const address of [
      'branch=dev&path=src',
      'branch=dev&path=a.md&kind=file',
      'kind=search&q=app&page=2&branch=dev',
      `view=files&branch=dev&sha=${sha}&visibility=1`,
    ])
      expect(previewIdentity(address)).toBe(files)
  })

  it('starts another preview for another branch, tab or any History address', () => {
    expect(previewIdentity('branch=main')).not.toBe(previewIdentity('branch=dev'))
    expect(previewIdentity('')).not.toBe(previewIdentity('tab=links'))
    expect(previewIdentity('view=history&page=1')).not.toBe(previewIdentity('view=history&page=2'))
    expect(previewIdentity('view=history&page=1')).not.toBe(previewIdentity('page=1'))
  })
})

describe('placeAddress', () => {
  it('moves to a folder, a file or a search and keeps branch, revision and editing', () => {
    const address = `branch=dev&sha=${sha}&visibility=1&path=src`
    expect(placeAddress(address, { kind: 'file', path: 'src/a.ts' }).toString()).toBe(
      `branch=dev&sha=${sha}&visibility=1&path=src%2Fa.ts&kind=file`,
    )
    expect(placeAddress(address, { kind: 'directory', path: '' }).toString()).toBe(
      `branch=dev&sha=${sha}&visibility=1`,
    )
    expect(placeAddress(address, { kind: 'search', q: 'app', page: 2 }).toString()).toBe(
      `branch=dev&sha=${sha}&visibility=1&kind=search&q=app&page=2`,
    )
    expect(
      placeAddress('kind=search&q=app&page=2', { kind: 'search', q: 'x', page: 1 }).toString(),
    ).toBe('kind=search&q=x')
  })
})

describe('placeTitle', () => {
  it('names the place of an address for the browser tab', () => {
    expect(placeTitle('')).toBe('')
    expect(placeTitle('?path=src%2Fapp.ts&kind=file')).toBe('app.ts')
    expect(placeTitle('path=src/lib')).toBe('lib')
    expect(placeTitle('kind=search&q=app&page=2')).toBe('Search: app')
    expect(placeTitle(`view=history&sha=${sha}&path=`)).toBe('History')
    expect(placeTitle('view=history&path=src%2Fapp.ts')).toBe('History of app.ts')
  })
})

describe('sameAddress', () => {
  it('compares the places of two addresses, whatever the order of their parameters', () => {
    expect(sameAddress('?branch=dev&path=src', 'path=src&branch=dev')).toBe(true)
    expect(sameAddress('path=src', 'path=src&kind=file')).toBe(false)
  })
})

describe('initialPreview', () => {
  it('starts editing file visibility, with its tree, only for owners who asked for it', () => {
    expect(start('visibility=1')).toMatchObject({ editing: true, tree: true })
    expect(start('visibility=1', false).editing).toBe(false)
    expect(start('').editing).toBe(false)
  })
})

describe('previewReducer', () => {
  it('keeps the state when the pinned revision does not change', () => {
    const pinned = previewReducer(start(), { type: 'pin', at: 'path=src', sha, branch: 'main' })
    expect(previewReducer(pinned, { type: 'pin', at: 'path=src', sha, branch: 'main' })).toBe(
      pinned,
    )
  })

  it('counts Try again without skipping the server cache', () => {
    const state = previewReducer(start(), { type: 'retry' })
    expect(previewQuery('path=src', state)).toMatchObject({ revision: 1, refresh: false })
  })

  it('keeps typed search text until the address searches for something else', () => {
    const typed = previewReducer(start(), { type: 'typeSearch', at: '', text: 'ap' })
    expect(searchText(typed, '')).toBe('ap')
    expect(searchText(typed, 'app')).toBe('app')
  })

  it('opens editing with the tree and asks before closing it over unsaved rules', () => {
    let state = previewReducer(start(), { type: 'toggleEditing', unsaved: false })
    expect(state).toMatchObject({ editing: true, tree: true })
    expect(previewReducer(state, { type: 'toggleEditing', unsaved: true }).pending).toBe(true)
    const asking = previewReducer(state, { type: 'closeTree', unsaved: true })
    expect(asking).toMatchObject({ pending: true, editing: true })
    expect(previewReducer(asking, { type: 'decided' }).pending).toBe(false)
    state = previewReducer(state, { type: 'closeTree', unsaved: false })
    expect(state.editing).toBe(false)
  })

  it('puts the tree away after a file opens on a phone and keeps editing', () => {
    const editing = previewReducer(start(), { type: 'toggleEditing', unsaved: false })
    const hidden = previewReducer(editing, { type: 'hideTree' })
    expect(hidden).toMatchObject({ editing: true, tree: false, pending: false })
    expect(previewReducer(hidden, { type: 'toggleTree' })).toMatchObject({
      editing: true,
      tree: true,
    })
  })

  it('closes a plain tree without asking', () => {
    const state = previewReducer(start(), { type: 'toggleTree' })
    expect(previewReducer(state, { type: 'closeTree', unsaved: false })).toMatchObject({
      tree: false,
      pending: false,
    })
  })
})

describe('addresses', () => {
  it('builds History with the way back to Files, and the ref only when given', () => {
    const returnTo = { path: 'src/a.ts', kind: 'file' }
    const history = historyAddress({
      path: '',
      sha,
      branch: 'main',
      page: 2,
      cursor: '',
      commit: '',
      returnTo,
    })
    expect(history.toString()).toBe(
      `view=history&path=&sha=${sha}&branch=main&page=2&cursor=&commit=&returnPath=src%2Fa.ts&returnKind=file`,
    )
    const pinned = historyAddress({
      path: '',
      sha,
      ref: sha,
      branch: 'main',
      page: 1,
      cursor: '',
      commit: '',
      returnTo,
    })
    expect(pinned.get('ref')).toBe(sha)
    expect(returnFileOf(history)).toEqual(returnTo)
    expect(filesAddress({ ...returnTo, sha, branch: 'main' }).toString()).toBe(
      `view=files&path=src%2Fa.ts&kind=file&sha=${sha}&branch=main`,
    )
  })
})
