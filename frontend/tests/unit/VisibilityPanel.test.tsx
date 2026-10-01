import { act, fireEvent, render, renderHook, screen, waitFor, within } from '../render'
import { expect, it, vi } from 'vitest'
import type { Preview } from '../../src/features/repositories/api'
import { RepositoryTree } from '../../src/features/repositories/tree/RepositoryTree'
import type { VisibilityProfile } from '../../src/features/repositories/visibility/draft'
import { useVisibility } from '../../src/features/repositories/visibility/useVisibility'
import {
  VisibilityPanel,
  VisibilitySaveBar,
} from '../../src/features/repositories/visibility/VisibilityPanel'

const head = 'a'.repeat(40)
const profile: VisibilityProfile = {
  branch: 'main',
  headSha: head,
  mode: 'selected',
  version: 3,
  sourceSha: head,
  treeChanged: false,
  selected: ['README.md'],
  missing: [],
  manifest: ['README.md', 'docs/a.md', 'docs/b.md', 'src/app.ts'],
  fileCount: 4,
  truncated: false,
  limits: { paths: 5000, bytes: 1048576, operations: 20 },
}
const endpoint = '/api/workspace/repositories/fixture/alpha/visibility' as const
const snapshot: Preview = {
  repository: { id: 1, fullName: 'fixture/alpha', private: true, defaultBranch: 'main' },
  commitSha: head,
  path: '',
  kind: 'directory',
  branch: 'main',
  allowHistory: true,
  historical: false,
  checkedAt: '2026-09-20T10:00:00Z',
  contentSource: 'github',
  entries: [
    { name: 'docs', path: 'docs', type: 'dir', size: 0 },
    { name: 'README.md', path: 'README.md', type: 'file', size: 10 },
    { name: 'src', path: 'src', type: 'dir', size: 0 },
  ],
}

async function loaded() {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(Response.json(profile))),
  )
  const hook = renderHook(() => useVisibility(endpoint, 'main', 'csrf-fixture', true))
  await waitFor(() => expect(hook.result.current.profile).not.toBeNull())
  return hook
}

it('counts visible files per folder and names the unsaved difference', async () => {
  const { result } = await loaded()
  expect([
    result.current.visible,
    result.current.savedVisible,
    result.current.folderCount(''),
    result.current.folderCount('docs'),
  ]).toEqual([1, 1, { selected: 1, total: 4 }, { selected: 0, total: 2 }])
  act(() => result.current.toggle('docs', true))
  act(() => result.current.toggle('README.md', false))
  expect(result.current.changes).toEqual({
    modeChanged: false,
    added: ['docs/a.md', 'docs/b.md'],
    removed: ['README.md'],
  })
  expect([
    result.current.changed('docs', true),
    result.current.changed('docs/a.md', false),
    result.current.changed('', true),
    result.current.changed('src', true),
  ]).toEqual([true, true, true, false])
  // Selecting the same file again returns to the saved rules: nothing is marked, even though the draft recorded operations.
  act(() => result.current.toggle('docs', true))
  act(() => result.current.toggle('README.md', false))
  expect([
    result.current.changes.added,
    result.current.changes.removed,
    result.current.changed('docs', true),
  ]).toEqual([[], [], false])
  act(() => result.current.setMode('all'))
  expect([
    result.current.changes.modeChanged,
    result.current.visible,
    result.current.folderCount('docs'),
  ]).toEqual([true, 4, { selected: 2, total: 2 }])
})

it('shows the saved state, the mode cards and a save bar that says what saving changes', async () => {
  const { result } = await loaded()
  const view = render(
    <>
      <VisibilityPanel visibility={result.current} onClose={vi.fn()} />
      <VisibilitySaveBar visibility={result.current} />
    </>,
  )
  const panel = screen.getByRole('region', { name: 'File visibility' })
  expect(within(panel).getByText('Saved')).toBeInTheDocument()
  expect(within(panel).getByRole('radio', { name: 'Selected files' })).toBeChecked()
  expect(within(panel).getByRole('radio', { name: 'Entire branch' })).toHaveAccessibleDescription(
    'Every file, including files added later.',
  )
  expect(within(panel).getByRole('status')).toHaveTextContent('1 of 4 files visible to visitors')
  expect(within(panel).getByText('How new files behave')).toBeInTheDocument()

  act(() => result.current.toggle('src/app.ts', false))
  view.rerender(
    <>
      <VisibilityPanel visibility={result.current} onClose={vi.fn()} />
      <VisibilitySaveBar visibility={result.current} />
    </>,
  )
  expect(within(panel).getByText('1 unsaved change')).toBeInTheDocument()
  expect(within(panel).getByRole('status')).toHaveTextContent('2 of 4 files visible to visitors')
  const bar = screen.getByRole('region', { name: 'Unsaved changes' })
  expect(bar).toHaveTextContent(
    'src/app.ts becomes visible · applies to links created after saving',
  )
  fireEvent.click(within(bar).getByRole('button', { name: 'Discard' }))
  expect(result.current.dirty).toBe(false)

  act(() => result.current.setMode('all'))
  view.rerender(
    <>
      <VisibilityPanel visibility={result.current} onClose={vi.fn()} />
      <VisibilitySaveBar visibility={result.current} />
    </>,
  )
  expect(within(panel).getByRole('status')).toHaveTextContent('All 4 files visible to visitors')
  expect(within(panel).queryByText('How new files behave')).not.toBeInTheDocument()
  expect(screen.getByRole('region', { name: 'Unsaved changes' })).toHaveTextContent(
    'Unsaved changesVisitors will see every file in main',
  )
})

it('filters the complete file list, shows only visible files and counts files per folder', async () => {
  const { result } = await loaded()
  const tree = () => (
    <RepositoryTree
      endpoint="/api/workspace/repositories/fixture/alpha/browse"
      snapshot={snapshot}
      selectedPath=""
      onSelect={vi.fn()}
      onClose={vi.fn()}
      onHide={vi.fn()}
      onFailure={vi.fn()}
      visibility={result.current}
    />
  )
  const view = render(tree())
  expect(screen.getByTitle('1 of 4 files visible')).toHaveTextContent('1 / 4')
  expect(screen.getByTitle('0 of 2 files visible')).toHaveTextContent('0 / 2')

  fireEvent.change(screen.getByRole('searchbox', { name: 'Filter files' }), {
    target: { value: 'DOCS/' },
  })
  const matches = screen.getByRole('list', { name: 'Matching files' })
  expect(
    within(matches)
      .getAllByRole('checkbox')
      .map(box => box.getAttribute('aria-label')),
  ).toEqual(['Visible to visitors: file docs/a.md', 'Visible to visitors: file docs/b.md'])
  fireEvent.click(
    within(matches).getByRole('checkbox', { name: 'Visible to visitors: file docs/b.md' }),
  )
  view.rerender(tree())
  expect(
    screen.getByRole('checkbox', { name: 'Visible to visitors: file docs/b.md' }),
  ).toBeChecked()
  expect(
    screen.getByRole('checkbox', { name: 'Visible to visitors: file docs/b.md' }),
  ).toHaveAttribute('title', 'Unsaved change')

  fireEvent.change(screen.getByRole('searchbox', { name: 'Filter files' }), {
    target: { value: '' },
  })
  fireEvent.click(screen.getByRole('switch', { name: 'Only visible' }))
  expect(
    within(screen.getByRole('list', { name: 'Matching files' }))
      .getAllByRole('button')
      .map(button => button.textContent),
  ).toEqual(['README.md', 'docs/b.md'])
  fireEvent.click(screen.getByRole('button', { name: 'Clear' }))
  view.rerender(tree())
  expect(screen.getByRole('status')).toHaveTextContent('No file is visible to visitors yet.')
})
