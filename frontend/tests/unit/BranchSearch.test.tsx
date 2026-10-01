import { useState } from 'react'
import { act, fireEvent, render, screen, waitFor } from '../render'
import { expect, it, vi } from 'vitest'
import { Route, Routes, useNavigate, useParams } from 'react-router-dom'
import { MemoryRouter } from '../router'
import { RepositoryBrowser } from '../../src/features/repositories/RepositoryBrowser'
import {
  BranchPermissions,
  type Permissions,
} from '../../src/features/repositories/links/BranchPermissions'

const endpoint = '/api/shares/example/browse' as const
const sha = 'a'.repeat(40)
const preview = {
  repository: { id: 1, fullName: 'fixture/alpha', defaultBranch: 'main', private: true },
  commitSha: sha,
  branch: 'main',
  allowHistory: false,
  kind: 'directory',
  path: '',
  entries: [],
}
function fixture() {
  const fetch = vi.fn(async (url: string) => {
    const query = new URL(url, 'http://localhost').searchParams
    if (query.get('kind') === 'branches')
      return Response.json({
        items: ['main', 'feature/preview'],
        defaultBranch: 'main',
        allowHistory: false,
        nextPage: null,
      })
    if (query.get('kind') === 'search')
      return Response.json({
        ...preview,
        kind: 'search',
        search: {
          items: [{ path: 'docs/čitaj me.txt', name: 'čitaj me.txt', type: 'file', size: 6 }],
          page: Number(query.get('page')),
          nextPage: query.get('page') === '1' ? 2 : null,
          limited: true,
        },
      })
    if (query.get('kind') === 'file')
      return Response.json({
        ...preview,
        kind: 'file',
        path: query.get('path'),
        file: { path: query.get('path'), commitSha: sha, bytes: 6, content: 'Source' },
      })
    return Response.json({ ...preview, branch: query.get('branch') || 'main' })
  })
  vi.stubGlobal('fetch', fetch)
  return fetch
}

it('orders Branch, Search files and Search and hides disallowed History and header metadata', async () => {
  fixture()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  const branch = screen.getByRole('combobox', { name: 'Branch' })
  await waitFor(() => expect(branch).toHaveValue('main'))
  expect(branch.nextElementSibling).toBe(screen.getByRole('textbox', { name: 'Search files' }))
  expect(branch.nextElementSibling?.nextElementSibling).toBe(
    screen.getByRole('button', { name: 'Search' }),
  )
  expect(screen.queryByRole('button', { name: 'History' })).not.toBeInTheDocument()
  expect(screen.queryByText(/^Commit |^Checked /)).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Search' })).toBeDisabled()
})

it('searches on submit, marks partial results and opens results at the same branch and SHA', async () => {
  const fetch = fixture()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  const before = fetch.mock.calls.length
  fireEvent.change(screen.getByLabelText('Search files'), { target: { value: 'čitaj' } })
  expect(fetch).toHaveBeenCalledTimes(before)
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))
  await screen.findByRole('button', { name: /docs\/čitaj me.txt/ })
  expect(fetch.mock.calls.at(-1)?.[0]).toContain('q=%C4%8Ditaj')
  expect(fetch.mock.calls.at(-1)?.[0]).toContain(`sha=${sha}`)
  expect(fetch.mock.calls.at(-1)?.[0]).toContain('branch=main')
  expect(screen.getByText(/partial file index/)).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Next results' }))
  await waitFor(() => expect(screen.getByRole('button', { name: 'Next results' })).toBeDisabled())
  fireEvent.click(screen.getByRole('button', { name: /docs\/čitaj me.txt/ }))
  await screen.findByText('Source')
  expect(fetch.mock.calls.at(-1)?.[0]).toContain(`sha=${sha}`)
  expect(fetch.mock.calls.at(-1)?.[0]).toContain('branch=main')
})

it('changing branches clears search and opens the new branch without reusing the old SHA', async () => {
  const fetch = fixture()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  fireEvent.change(screen.getByLabelText('Search files'), { target: { value: 'docs' } })
  fireEvent.change(screen.getByRole('combobox', { name: 'Branch' }), {
    target: { value: 'feature/preview' },
  })
  await waitFor(() =>
    expect(fetch.mock.calls.at(-1)?.[0]).toContain('sha=&ref=&branch=feature%2Fpreview'),
  )
  expect(screen.getByLabelText('Search files')).toHaveValue('')
  expect(screen.getByRole('combobox', { name: 'Branch' })).toHaveValue('feature/preview')
})

it('discards late search results after selecting another repository', async () => {
  const fetch = fixture()
  const original = fetch.getMockImplementation()!
  let finish: ((value: Response) => void) | null = null
  fetch.mockImplementation(url =>
    url.includes('kind=search') && finish === null
      ? new Promise(resolve => {
          finish = resolve
        })
      : original(url),
  )
  // Another repository is another page, as in the workspace and for visitors.
  function Repository() {
    const { name = '' } = useParams()
    return <RepositoryBrowser endpoint={`/api/shares/${name}/browse`} />
  }
  function Other() {
    const navigate = useNavigate()
    return <button onClick={() => void navigate('/other')}>Other repository</button>
  }
  render(
    <MemoryRouter initialEntries={['/example']}>
      <Other />
      <Routes>
        <Route path="/:name" element={<Repository />} />
      </Routes>
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  fireEvent.change(screen.getByLabelText('Search files'), { target: { value: 'docs' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))
  await waitFor(() => expect(finish).not.toBeNull())
  fireEvent.click(screen.getByRole('button', { name: 'Other repository' }))
  await waitFor(() =>
    expect(fetch.mock.calls.some(([url]) => url.startsWith('/api/shares/other/browse'))).toBe(true),
  )
  await act(async () =>
    finish!(
      Response.json({
        ...preview,
        kind: 'search',
        search: { items: [{ path: 'stale.txt', size: 1 }], page: 1, nextPage: null },
      }),
    ),
  )
  expect(screen.queryByText('stale.txt')).not.toBeInTheDocument()
  expect(screen.getByLabelText('Search files')).toHaveValue('')
})

it('resizes by keyboard within bounds without requesting repository content again', async () => {
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
    width: 1100,
  } as DOMRect)
  const fetch = fixture()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  fireEvent.click(screen.getByRole('button', { name: 'Tree' }))
  const separator = screen.getByRole('separator', { name: 'Resize files panel' })
  const calls = fetch.mock.calls.length
  fireEvent.keyDown(separator, { key: 'ArrowRight' })
  expect(separator).toHaveAttribute('aria-valuenow', '300')
  fireEvent.keyDown(separator, { key: 'Home' })
  fireEvent.keyDown(separator, { key: 'ArrowLeft' })
  expect(separator).toHaveAttribute('aria-valuenow', '200')
  fireEvent.keyDown(separator, { key: 'End' })
  fireEvent.keyDown(separator, { key: 'ArrowRight' })
  expect(separator).toHaveAttribute('aria-valuenow', '640')
  expect(fetch).toHaveBeenCalledTimes(calls)
  vi.restoreAllMocks()
})

it('starts new link permissions at the default branch with History off and supports paginated choices', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) =>
      Response.json({
        items: url.includes('page=2') ? ['feature/preview'] : ['main'],
        defaultBranch: 'main',
        allowHistory: true,
        nextPage: url.includes('page=2') ? null : 2,
      }),
    ),
  )
  function Form() {
    const [value, setValue] = useState<Permissions>({ branches: null, allowHistory: false })
    return (
      <>
        <BranchPermissions endpoint={endpoint} value={value} onChange={setValue} disabled={false} />
        <output>{JSON.stringify(value)}</output>
      </>
    )
  }
  render(<Form />)
  await waitFor(() => expect(screen.getByRole('checkbox', { name: 'main' })).toBeChecked())
  expect(screen.getByRole('switch', { name: 'Allow history' })).not.toBeChecked()
  fireEvent.click(screen.getByRole('button', { name: 'Load more branches' }))
  fireEvent.click(await screen.findByRole('checkbox', { name: 'feature/preview' }))
  fireEvent.click(screen.getByRole('checkbox', { name: 'main' }))
  fireEvent.click(screen.getByRole('switch', { name: 'Allow history' }))
  expect(screen.getByRole('status')).toHaveTextContent(
    '"branches":["feature/preview"],"allowHistory":true',
  )
})
