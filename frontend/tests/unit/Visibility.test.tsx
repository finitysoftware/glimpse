import { act, render, renderHook, screen, waitFor } from '../render'
import userEvent from '@testing-library/user-event'
import { expect, it, vi } from 'vitest'
import type { Preview } from '../../src/features/repositories/api'
import { RepositoryTree } from '../../src/features/repositories/tree/RepositoryTree'
import type { VisibilityProfile } from '../../src/features/repositories/visibility/draft'
import { useVisibility } from '../../src/features/repositories/visibility/useVisibility'

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
  limits: { paths: 5000, bytes: 1048576, operations: 2 },
}
const endpoint = '/api/workspace/repositories/fixture/alpha/visibility' as const

function mockFetch(saveResponse: () => Response = () => Response.json(profile)) {
  const fetch = vi.fn((_path: string, init?: RequestInit) =>
    Promise.resolve(init?.method === 'PUT' ? saveResponse() : Response.json(profile)),
  )
  vi.stubGlobal('fetch', fetch)
  return fetch
}

it('derives folder states from the complete manifest and saves the recorded operations', async () => {
  const fetch = mockFetch()
  const { result } = renderHook(() => useVisibility(endpoint, 'main', 'csrf-fixture', true))
  await waitFor(() => expect(result.current.profile).not.toBeNull())
  expect(result.current.state('', true)).toBe('mixed')
  expect(result.current.state('docs', true)).toBe('unchecked')
  expect(result.current.state('README.md', false)).toBe('checked')
  expect(result.current.state('docs-link', false)).toBe('unavailable')
  expect(result.current.dirty).toBe(false)
  act(() => result.current.toggle('docs', true))
  expect(result.current.state('docs', true)).toBe('checked')
  expect(result.current.state('', true)).toBe('mixed')
  expect(result.current.count).toBe(3)
  act(() => result.current.toggle('src/app.ts', false))
  expect(result.current.state('', true)).toBe('checked')
  // The operation limit asks for an earlier save instead of growing the request.
  act(() => result.current.toggle('README.md', false))
  expect(result.current.message).toContain('Save these 2 changes')
  expect(result.current.dirty).toBe(true)
  await act(async () => {
    expect(await result.current.save()).toBe(true)
  })
  const put = fetch.mock.calls.find(([, init]) => init?.method === 'PUT')
  expect(JSON.parse(String(put?.[1]?.body))).toEqual({
    branch: 'main',
    expectedVersion: 3,
    sourceSha: head,
    mode: 'selected',
    operations: [
      { action: 'select', path: 'docs' },
      { action: 'select', path: 'src/app.ts' },
    ],
  })
  expect(put?.[1]?.headers).toMatchObject({ 'X-CSRF-Token': 'csrf-fixture' })
  expect(result.current.dirty).toBe(false)
})

it('switches to All files explicitly and restores the saved draft on cancel', async () => {
  const fetch = mockFetch()
  const { result } = renderHook(() => useVisibility(endpoint, 'main', 'csrf-fixture', true))
  await waitFor(() => expect(result.current.profile).not.toBeNull())
  act(() => result.current.setMode('all'))
  expect([
    result.current.mode,
    result.current.count,
    result.current.state('docs', true),
    result.current.dirty,
  ]).toEqual(['all', 4, 'checked', true])
  act(() => result.current.toggle('docs', true))
  expect(result.current.count).toBe(4)
  act(() => result.current.cancel())
  expect([result.current.mode, result.current.count, result.current.dirty]).toEqual([
    'selected',
    1,
    false,
  ])
  act(() => result.current.setMode('all'))
  await act(async () => {
    await result.current.save()
  })
  expect(
    JSON.parse(String(fetch.mock.calls.find(([, init]) => init?.method === 'PUT')?.[1]?.body)),
  ).toMatchObject({ mode: 'all', operations: [] })
})

it('keeps the draft and offers a reload after a version conflict', async () => {
  mockFetch(() =>
    Response.json(
      {
        error: {
          code: 'visibility_changed',
          message: 'These visibility rules were changed elsewhere.',
        },
      },
      { status: 409 },
    ),
  )
  const { result } = renderHook(() => useVisibility(endpoint, 'main', 'csrf-fixture', true))
  await waitFor(() => expect(result.current.profile).not.toBeNull())
  act(() => result.current.toggle('docs', true))
  await act(async () => {
    expect(await result.current.save()).toBe(false)
  })
  expect([result.current.conflict, result.current.error, result.current.dirty]).toEqual([
    true,
    'These visibility rules were changed elsewhere.',
    true,
  ])
})

it('renders separate accessible checkboxes that never open files', async () => {
  const user = userEvent.setup()
  mockFetch()
  const { result } = renderHook(() => useVisibility(endpoint, 'main', 'csrf-fixture', true))
  await waitFor(() => expect(result.current.profile).not.toBeNull())
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
      { name: 'link', path: 'link', type: 'symlink', size: 4 },
    ],
  }
  const onSelect = vi.fn()
  const view = render(
    <RepositoryTree
      endpoint="/api/workspace/repositories/fixture/alpha/browse"
      snapshot={snapshot}
      selectedPath=""
      onSelect={onSelect}
      onClose={vi.fn()}
      onHide={vi.fn()}
      onFailure={vi.fn()}
      visibility={result.current}
    />,
  )
  const root = screen.getByRole('checkbox', { name: 'Visible to visitors: all files' })
  expect((root as HTMLInputElement).indeterminate).toBe(true)
  expect(
    screen.getByRole('checkbox', { name: 'Visible to visitors: file README.md' }),
  ).toBeChecked()
  expect(
    screen.getByRole('checkbox', { name: 'Visible to visitors: folder docs' }),
  ).not.toBeChecked()
  expect(screen.getByRole('checkbox', { name: 'Visible to visitors: file link' })).toBeDisabled()
  await user.click(screen.getByRole('checkbox', { name: 'Visible to visitors: folder docs' }))
  expect(onSelect).not.toHaveBeenCalled()
  view.rerender(
    <RepositoryTree
      endpoint="/api/workspace/repositories/fixture/alpha/browse"
      snapshot={snapshot}
      selectedPath=""
      onSelect={onSelect}
      onClose={vi.fn()}
      onHide={vi.fn()}
      onFailure={vi.fn()}
      visibility={result.current}
    />,
  )
  expect(screen.getByRole('checkbox', { name: 'Visible to visitors: folder docs' })).toBeChecked()
  expect(screen.getByText('3 selected · unsaved')).toBeInTheDocument()
})
