import { act, fireEvent, render, screen, waitFor, within } from '../render'
import { expect, it, vi } from 'vitest'
import { useLocation } from 'react-router-dom'
import { MemoryRouter } from '../router'
import { RepositoryBrowser } from '../../src/features/repositories/RepositoryBrowser'
import { DiffPreview } from '../../src/features/repositories/history/DiffPreview'
import { parseDiff, tokenLines } from '../../src/features/repositories/history/diff'
import type {
  ChangedFile,
  CommitDetail,
  CommitSummary,
  Preview,
} from '../../src/features/repositories/api'

vi.mock('../../src/features/repositories/useBranches', () => ({
  useBranches: () => ({
    data: { items: ['main'], defaultBranch: 'main', allowHistory: true },
    loading: false,
  }),
}))

const sha = 'a'.repeat(40),
  parent = 'b'.repeat(40)
const commit: CommitSummary = {
  sha,
  message: 'Improve startup',
  messageLimited: false,
  author: 'Author',
  date: '2026-09-14T07:00:00Z',
  parents: [parent],
}
const file: ChangedFile = {
  path: 'App.cs',
  previousPath: null,
  status: 'modified',
  additions: 1,
  deletions: 1,
  patch: '@@ -2 +2 @@\n-old\n+<script>alert(1)</script>',
  patchState: 'complete',
}
const detail: CommitDetail = {
  commit,
  files: [file, { ...file, path: 'logo.png', patch: null, patchState: 'unavailable' }],
  page: 1,
  nextPage: 2,
  filesLimited: false,
  stats: { additions: 1, deletions: 1, total: 2 },
}
const preview: Preview = {
  repository: { id: 1, fullName: 'fixture/alpha', private: true, defaultBranch: 'main' },
  commitSha: sha,
  path: '',
  kind: 'directory',
  branch: 'main',
  allowHistory: true,
  historical: false,
  checkedAt: '2026-09-20T10:00:00Z',
  contentSource: 'github',
  entries: [{ name: 'App.cs', path: 'App.cs', type: 'file', size: 3 }],
}

function fixture() {
  const fetch = vi.fn(async (url: string) => {
    const query = new URL(url, 'https://fixture.test').searchParams
    if (query.get('kind') === 'history')
      return Response.json({
        ...preview,
        path: query.get('path') || '',
        kind: 'history',
        history: {
          items: [commit],
          page: Number(query.get('page')),
          nextPage: query.get('page') === '1' ? 2 : null,
          limited: false,
        },
      })
    if (query.get('kind') === 'commit')
      return Response.json({
        ...preview,
        kind: 'commit',
        detail:
          query.get('page') === '2'
            ? { ...detail, page: 2, nextPage: null, files: [{ ...file, status: 'removed' }] }
            : detail,
      })
    if (query.get('kind') === 'file')
      return Response.json({
        ...preview,
        kind: 'file',
        path: 'App.cs',
        file: { path: 'App.cs', commitSha: query.get('sha') || sha, bytes: 3, content: 'old' },
      })
    return Response.json(preview)
  })
  vi.stubGlobal('fetch', fetch)
  return fetch
}

it('loads history on demand at the pinned SHA and renders safe diff rows and missing patches', async () => {
  const fetch = fixture()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/shares/example/browse" />
    </MemoryRouter>,
  )
  await screen.findByRole('button', { name: /App.cs.*bytes/ })
  expect(fetch).toHaveBeenCalledTimes(1)
  fireEvent.click(screen.getByRole('button', { name: 'History' }))
  await screen.findByRole('heading', { name: 'Improve startup' })
  expect(fetch.mock.calls[1]?.[0]).toContain(`sha=${sha}`)
  expect(screen.getByRole('region', { name: 'Diff for App.cs' })).toHaveTextContent(
    '<script>alert(1)</script>',
  )
  expect(document.querySelector('script')).toBeNull()
  expect(screen.getByText('Old line 2')).toBeInTheDocument()
  expect(screen.getByText('New line 2')).toBeInTheDocument()
  expect(screen.getByRole('group', { name: 'Changed files' })).toBeInTheDocument()
  const writeText = vi.fn().mockResolvedValue(undefined)
  vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } })
  fireEvent.click(screen.getByRole('button', { name: 'Copy commit SHA' }))
  expect(await screen.findByText('Commit SHA copied.')).toHaveAttribute('role', 'status')
  expect(writeText).toHaveBeenCalledWith(sha)
  fireEvent.click(screen.getByRole('button', { name: /logo.png/ }))
  expect(screen.getByText(/No text patch is available/)).toHaveAttribute('role', 'status')
  fireEvent.click(screen.getByRole('button', { name: 'Older' }))
  await waitFor(() => expect(screen.getByRole('button', { name: 'Older' })).toBeDisabled())
  expect(
    fetch.mock.calls.some(
      ([url]) =>
        url.includes('kind=history') && url.includes('page=2') && url.includes(`sha=${sha}`),
    ),
  ).toBe(true)
})

it('paginates changed files and opens a removed file at its parent commit', async () => {
  const fetch = fixture()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/shares/example/browse" />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  fireEvent.click(screen.getByRole('button', { name: 'History' }))
  fireEvent.click(await screen.findByRole('button', { name: 'Next files' }))
  fireEvent.click(await screen.findByRole('button', { name: 'View before deletion' }))
  await waitFor(() => expect(fetch.mock.calls.at(-1)?.[0]).toContain(`sha=${parent}`))
  expect(fetch.mock.calls.at(-1)?.[0]).toContain('kind=file')
  expect(screen.getByLabelText('Branch')).toHaveValue('main')
  expect(fetch.mock.calls.at(-1)?.[0]).toContain('branch=main')
})

it('offers history for the open file without silently following earlier names', async () => {
  const fetch = fixture()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/shares/example/browse" />
    </MemoryRouter>,
  )
  fireEvent.click(await screen.findByRole('button', { name: /App.cs.*bytes/ }))
  fireEvent.click(await screen.findByRole('button', { name: 'File history' }))
  await screen.findByText(/Earlier names may have separate history/)
  expect(
    fetch.mock.calls.some(([url]) => url.includes('kind=history') && url.includes('path=App.cs')),
  ).toBe(true)
})

it('direct History URLs restore the selected revision and Refresh returns to the branch head', async () => {
  const fetch = fixture()
  render(
    <MemoryRouter initialEntries={[`/s/example?view=history&sha=${sha}&ref=${sha}&commit=${sha}`]}>
      <RepositoryBrowser endpoint="/api/shares/example/browse" />
    </MemoryRouter>,
  )
  fireEvent.click(await screen.findByRole('button', { name: 'Browse this revision' }))
  await screen.findByRole('button', { name: /App.cs.*bytes/ })
  fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
  await waitFor(() =>
    expect(fetch.mock.calls.at(-1)?.[0]).toContain('sha=&ref=&branch=main&q=&refresh=1'),
  )
})

it('removes history and old diff when a later authorization check fails', async () => {
  const fetch = fixture()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/shares/example/browse" />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  fireEvent.click(screen.getByRole('button', { name: 'History' }))
  await screen.findByRole('region', { name: 'Diff for App.cs' })
  fetch.mockImplementation(async () =>
    Response.json(
      { error: { code: 'invalid_share', message: 'Access revoked.' } },
      { status: 404 },
    ),
  )
  fireEvent.click(screen.getByRole('button', { name: 'Next files' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Access revoked')
  expect(screen.queryByRole('region', { name: /Diff for/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('complementary', { name: 'Commit history' })).not.toBeInTheDocument()
})

it('discards delayed commit responses after leaving History', async () => {
  const fetch = fixture()
  let finish: (response: Response) => void = () => {}
  const original = fetch.getMockImplementation()!
  fetch.mockImplementation(url =>
    url.includes('kind=commit')
      ? new Promise(resolve => {
          finish = resolve
        })
      : original(url),
  )
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/shares/example/browse" />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  fireEvent.click(screen.getByRole('button', { name: 'History' }))
  await screen.findByText('Loading commit…')
  fireEvent.click(
    within(screen.getByRole('navigation', { name: 'Repository views' })).getByRole('button', {
      name: 'Files',
    }),
  )
  await screen.findByRole('button', { name: /App.cs.*bytes/ })
  await act(async () => finish(Response.json({ ...preview, kind: 'commit', detail })))
  expect(screen.queryByRole('region', { name: /Diff for/ })).not.toBeInTheDocument()
})

it('Refresh advances the History URL and ignores the old in-flight commit', async () => {
  const nextSha = 'e'.repeat(40)
  let finish: (response: Response) => void = () => {}
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      const params = new URL(url, 'https://fixture.test').searchParams
      if (params.get('kind') === 'commit' && params.get('sha') === sha)
        return new Promise<Response>(resolve => {
          finish = resolve
        })
      if (params.get('kind') === 'commit')
        return Response.json({
          ...preview,
          commitSha: nextSha,
          kind: 'commit',
          detail: { ...detail, commit: { ...commit, sha: nextSha, message: 'New head' } },
        })
      const head = params.get('refresh') === '1' || params.get('sha') === nextSha ? nextSha : sha
      return Response.json({
        ...preview,
        commitSha: head,
        kind: 'history',
        history: { items: [{ ...commit, sha: head }], page: 1, nextPage: null, limited: false },
      })
    }),
  )
  function Location() {
    return <output aria-label="Current URL">{useLocation().search}</output>
  }
  render(
    <MemoryRouter initialEntries={[`/s/example?view=history&sha=${sha}&commit=${sha}`]}>
      <RepositoryBrowser endpoint="/api/shares/example/browse" />
      <Location />
    </MemoryRouter>,
  )
  await screen.findByText('Loading commit…')
  fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
  await screen.findByRole('heading', { name: 'New head' })
  expect(screen.getByLabelText('Current URL')).toHaveTextContent(`sha=${nextSha}`)
  expect(screen.getByLabelText('Current URL')).not.toHaveTextContent(`commit=${sha}`)
  await act(async () => finish(Response.json({ ...preview, kind: 'commit', detail })))
  expect(screen.queryByRole('heading', { name: 'Improve startup' })).not.toBeInTheDocument()
})

it('windows large diffs and preserves line numbers when scrolling', () => {
  const patch =
    '@@ -0,0 +1,10000 @@\n' + Array.from({ length: 10000 }, (_, i) => `+line ${i + 1}`).join('\n')
  render(<DiffPreview file={{ ...file, path: 'large.txt', patch }} />)
  const region = screen.getByRole('region', { name: 'Diff for large.txt' })
  expect(region.querySelectorAll('code').length).toBeLessThanOrEqual(50)
  expect(region).toHaveTextContent('line 1')
  fireEvent.scroll(region, { target: { scrollTop: 9000 * 24 } })
  expect(screen.getByText('New line 9000')).toBeInTheDocument()
  expect(region.querySelectorAll('code').length).toBeLessThanOrEqual(50)
})

it('parses multiple hunks and no-newline notices without treating markup as HTML', () => {
  const rows = parseDiff('@@ -2 +3 @@\n-a\n+b\n\\ No newline at end of file\n@@ -9,0 +11 @@\n+<b>')
  expect(rows[1]).toMatchObject({ old: 2, next: null, kind: 'removed' })
  expect(rows[2]).toMatchObject({ old: null, next: 3, kind: 'added' })
  expect(rows[5]).toMatchObject({ next: 11, text: '<b>' })
  expect(tokenLines([{ text: 'a\nb', className: 'hljs-keyword' }])).toEqual([
    [{ text: 'a', className: 'hljs-keyword' }],
    [{ text: 'b', className: 'hljs-keyword' }],
  ])
})

it('filtered history continues through the server cursor and hides author and moved-file details', async () => {
  const shared: CommitSummary = { ...commit, message: 'Changes to shared files', author: '' }
  const moved: ChangedFile = {
    path: 'README.md',
    previousPath: null,
    status: 'added',
    additions: 0,
    deletions: 0,
    patch: null,
    patchState: 'restricted',
  }
  const fetch = vi.fn(async (url: string) => {
    const query = new URL(url, 'https://fixture.test').searchParams
    if (query.get('kind') === 'history') {
      const cursor = query.get('cursor') ?? ''
      return Response.json({
        ...preview,
        kind: 'history',
        history: {
          items: cursor ? [] : [shared],
          page: 1,
          nextPage: null,
          limited: false,
          filtered: true,
          cursor,
          nextCursor: cursor ? null : '2.0.1',
          incomplete: !cursor,
          largeCommitsSkipped: false,
        },
      })
    }
    if (query.get('kind') === 'commit')
      return Response.json({
        ...preview,
        kind: 'commit',
        detail: { ...detail, commit: shared, files: [moved], nextPage: null },
      })
    return Response.json(preview)
  })
  vi.stubGlobal('fetch', fetch)
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/shares/example/browse" />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  fireEvent.click(screen.getByRole('button', { name: 'History' }))
  await screen.findByRole('heading', { name: 'Changes to shared files' })
  expect(
    screen.getByText('Only changes to files shared through this link are listed.'),
  ).toBeInTheDocument()
  expect(screen.getByText(/Only part of the history was checked/)).toBeInTheDocument()
  expect(screen.queryByText(/Author/)).toBeNull()
  expect(screen.getByText(/moved from or to a file that is not shared/)).toHaveAttribute(
    'role',
    'status',
  )
  expect(screen.getByRole('button', { name: 'Newest' })).toBeDisabled()
  expect(screen.queryByText(/^Page /)).toBeNull()
  fireEvent.click(screen.getByRole('button', { name: 'Continue' }))
  await screen.findByText('No changes to shared files in this part of the history.')
  expect(
    fetch.mock.calls.some(
      ([url]) =>
        url.includes('kind=history') && url.includes('cursor=2.0.1') && url.includes(`sha=${sha}`),
    ),
  ).toBe(true)
  expect(screen.getByRole('button', { name: 'Older' })).toBeDisabled()
  fireEvent.click(screen.getByRole('button', { name: 'Newest' }))
  await screen.findByRole('heading', { name: 'Changes to shared files' })
  expect(
    fetch.mock.calls.filter(([url]) => url.includes('kind=history')).at(-1)?.[0],
  ).not.toContain('cursor=')
})
