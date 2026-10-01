import { render, screen, waitFor } from '../render'
import userEvent from '@testing-library/user-event'
import { useLocation, useNavigate } from 'react-router-dom'
import { expect, it, vi } from 'vitest'
import { RepositoryBrowser } from '../../src/features/repositories/RepositoryBrowser'
import { MemoryRouter } from '../router'

const endpoint = '/api/shares/example/browse' as const
const sha = 'a'.repeat(40)
const head = 'c'.repeat(40)
const preview = {
  repository: { id: 1, fullName: 'fixture/alpha', private: true, defaultBranch: 'main' },
  branch: 'main',
  allowHistory: false,
  historical: false,
  checkedAt: '',
  contentSource: 'upstream',
}
const folders: Record<string, { name: string; path: string; type: string; size: number }[]> = {
  '': [
    { name: 'src', path: 'src', type: 'dir', size: 0 },
    { name: 'README.md', path: 'README.md', type: 'file', size: 6 },
  ],
  src: [{ name: 'app.ts', path: 'src/app.ts', type: 'file', size: 12 }],
}

/**
 * A visitor's repository: two folders, their files and a search that finds src/app.ts. A check without a revision reads
 * `latest`, the head of the branch; Refresh reads `head`.
 */
function fixture(latest = sha) {
  const fetch = vi.fn(async (url: string) => {
    const query = new URL(url, 'http://localhost').searchParams
    const path = query.get('path') ?? ''
    const commitSha = query.get('sha') || (query.get('refresh') === '1' ? head : latest)
    if (query.get('kind') === 'branches')
      return Response.json({
        items: ['main'],
        nextPage: null,
        defaultBranch: 'main',
        allowHistory: false,
      })
    if (query.get('kind') === 'search')
      return Response.json({
        ...preview,
        commitSha,
        path: '',
        kind: 'search',
        search: {
          items: [{ name: 'app.ts', path: 'src/app.ts', type: 'file', size: 12 }],
          page: 1,
          nextPage: null,
          limited: false,
        },
      })
    if (query.get('kind') === 'file')
      return Response.json({
        ...preview,
        commitSha,
        path,
        kind: 'file',
        file: { path, commitSha, bytes: 12, content: `Content of ${path}` },
      })
    return Response.json({
      ...preview,
      commitSha,
      path,
      kind: 'directory',
      entries: folders[path] ?? [],
    })
  })
  vi.stubGlobal('fetch', fetch)
  return fetch
}

function Controls() {
  const location = useLocation()
  const navigate = useNavigate()
  return (
    <>
      <output aria-label="Location">{location.pathname + location.search}</output>
      <button onClick={() => void navigate(-1)}>Browser back</button>
      <button onClick={() => void navigate(1)}>Browser forward</button>
    </>
  )
}

function renderAt(address: string) {
  render(
    <MemoryRouter initialEntries={[address]}>
      <Controls />
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
}

const location = () => screen.getByLabelText('Location')
const button = (name: RegExp) => screen.findByRole('button', { name })
const readme = /^README\.md.*bytes/
const app = /^app\.ts.*bytes/

it('puts folders and files into the address, so Back and Forward walk through them', async () => {
  const fetch = fixture()
  const user = userEvent.setup()
  renderAt('/')
  await user.click(await button(/^src.*Folder/))
  await waitFor(() => expect(location()).toHaveTextContent(/^\/\?path=src$/))
  await user.click(await button(app))
  expect(await screen.findByText('Content of src/app.ts')).toBeInTheDocument()
  expect(location()).toHaveTextContent(/^\/\?path=src%2Fapp\.ts&kind=file$/)
  // Folders and files open at the commit the preview is showing.
  expect(fetch.mock.calls.at(-1)?.[0]).toContain(`sha=${sha}`)

  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  expect(await button(app)).toBeInTheDocument()
  expect(screen.getByRole('navigation', { name: 'File path' })).toHaveTextContent('src')
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  expect(await button(readme)).toBeInTheDocument()
  expect(location()).toHaveTextContent(/^\/$/)
  await user.click(screen.getByRole('button', { name: 'Browser forward' }))
  await user.click(screen.getByRole('button', { name: 'Browser forward' }))
  expect(await screen.findByText('Content of src/app.ts')).toBeInTheDocument()
})

it('opens the file in the address, as after a reload', async () => {
  const fetch = fixture()
  renderAt('/?path=src%2Fapp.ts&kind=file')
  expect(await screen.findByText('Content of src/app.ts')).toBeInTheDocument()
  expect(screen.getByRole('navigation', { name: 'File path' })).toHaveTextContent('src')
  // A reload reads the head of the branch again.
  expect(fetch.mock.calls.find(([url]) => url.includes('kind=file'))?.[0]).toContain('sha=&')
})

it('opens the search in the address with its text, as after a reload', async () => {
  fixture()
  renderAt('/?kind=search&q=app')
  expect(await button(/src\/app\.ts/)).toBeInTheDocument()
  expect(screen.getByLabelText('Search files')).toHaveValue('app')
})

it('returns to search results and their text with Back, and Clear search leaves both', async () => {
  fixture()
  const user = userEvent.setup()
  renderAt('/')
  await user.type(await screen.findByLabelText('Search files'), 'app')
  await user.click(screen.getByRole('button', { name: 'Search' }))
  await user.click(await button(/src\/app\.ts/))
  expect(await screen.findByText('Content of src/app.ts')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  expect(await button(/src\/app\.ts/)).toBeInTheDocument()
  expect(location()).toHaveTextContent(/^\/\?kind=search&q=app$/)
  expect(screen.getByLabelText('Search files')).toHaveValue('app')

  await user.click(screen.getByRole('button', { name: 'Clear search' }))
  expect(await button(readme)).toBeInTheDocument()
  expect(location()).toHaveTextContent(/^\/$/)
  expect(screen.getByLabelText('Search files')).toHaveValue('')
})

it('moves a revision the address names along when Refresh reads the head of the branch', async () => {
  const fetch = fixture()
  const user = userEvent.setup()
  renderAt(`/?view=files&sha=${sha}&branch=main`)
  await button(/^src.*Folder/)
  await user.click(screen.getByRole('button', { name: 'Refresh' }))
  await waitFor(() => expect(fetch.mock.calls.at(-1)?.[0]).toContain('refresh=1'))
  await waitFor(() => expect(screen.getByRole('button', { name: 'Refresh' })).toBeEnabled())
  await user.click(await button(/^src.*Folder/))
  await waitFor(() =>
    expect(location()).toHaveTextContent(`/?view=files&sha=${head}&branch=main&path=src`),
  )
  expect(fetch.mock.calls.at(-1)?.[0]).toContain(`sha=${head}`)
})

it('opens the place already on screen without a new step, so Back goes straight to the one before', async () => {
  fixture()
  const user = userEvent.setup()
  renderAt('/')
  await user.click(await button(/^src.*Folder/))
  await waitFor(() => expect(location()).toHaveTextContent(/^\/\?path=src$/))
  const root = () => screen.getByRole('button', { name: 'Root' })
  await user.click(root())
  expect(await button(readme)).toBeInTheDocument()
  await user.click(root())
  await user.type(screen.getByLabelText('Search files'), 'app')
  await user.click(screen.getByRole('button', { name: 'Search' }))
  await button(/src\/app\.ts/)
  await user.click(screen.getByRole('button', { name: 'Search' }))

  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  await waitFor(() => expect(location()).toHaveTextContent(/^\/$/))
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  await waitFor(() => expect(location()).toHaveTextContent(/^\/\?path=src$/))
})
