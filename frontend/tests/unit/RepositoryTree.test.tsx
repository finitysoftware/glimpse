import { act, fireEvent, render, screen, waitFor, within } from '../render'
import { expect, it, vi } from 'vitest'
import { MemoryRouter } from '../router'
import { RepositoryBrowser } from '../../src/features/repositories/RepositoryBrowser'
import type { Preview } from '../../src/features/repositories/api'

vi.mock('../../src/features/repositories/useBranches', () => ({
  useBranches: () => ({
    data: { items: ['main'], defaultBranch: 'main', allowHistory: true },
    loading: false,
  }),
}))

const sha = 'a'.repeat(40)
const nextSha = 'b'.repeat(40)
const endpoint = '/api/workspace/repositories/fixture/alpha/browse' as const
const repository = { id: 101, fullName: 'fixture/alpha', private: true, defaultBranch: 'main' }
const directories: Record<string, NonNullable<Preview['entries']>> = {
  '': [
    { name: 'docs', path: 'docs', type: 'dir', size: 0 },
    { name: 'src', path: 'src', type: 'dir', size: 0 },
    { name: 'README.txt', path: 'README.txt', type: 'file', size: 5 },
  ],
  docs: [{ name: 'guide.txt', path: 'docs/guide.txt', type: 'file', size: 5 }],
}
function preview(path: string, kind = 'directory', commitSha = sha): Preview {
  return {
    repository,
    path,
    kind: kind === 'file' ? 'file' : 'directory',
    commitSha,
    branch: 'main',
    allowHistory: true,
    historical: false,
    checkedAt: '2026-09-20T10:00:00Z',
    contentSource: 'github',
    ...(kind === 'file'
      ? { file: { path, commitSha, content: `Content of ${path}`, bytes: 24 } }
      : { entries: directories[path] ?? [] }),
  }
}
function stub() {
  const fetch = vi.fn(async (url: string) => {
    const query = new URL(url, 'http://localhost').searchParams
    return Response.json(
      preview(query.get('path') || '', query.get('kind') || 'directory', query.get('sha') || sha),
    )
  })
  vi.stubGlobal('fetch', fetch)
  return fetch
}
async function openFile() {
  await screen.findByRole('button', { name: /README.txt.*bytes/ })
  fireEvent.click(screen.getByRole('button', { name: /README.txt.*bytes/ }))
  await screen.findByText('Content of README.txt')
}

it('loads only expanded folders and toggles layout without reloading or remounting the selected file', async () => {
  const fetch = stub()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await screen.findByRole('button', { name: /README.txt.*bytes/ })
  expect(screen.queryByRole('navigation', { name: 'Project files' })).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Tree' }))
  const tree = within(screen.getByRole('navigation', { name: 'Project files' }))
  expect(fetch).toHaveBeenCalledTimes(1)
  expect(tree.getByRole('button', { name: 'docs' })).toHaveAttribute('aria-expanded', 'false')
  fireEvent.click(tree.getByRole('button', { name: 'docs' }))
  fireEvent.click(await tree.findByRole('button', { name: 'guide.txt' }))
  const code = await screen.findByText('Content of docs/guide.txt')
  expect(fetch).toHaveBeenCalledTimes(3)
  expect(fetch.mock.calls[1]?.[0]).toContain(`path=docs&kind=directory&sha=${sha}`)
  expect(fetch.mock.calls.every(([url]) => !url.includes('path=src'))).toBe(true)
  expect(tree.getByRole('button', { name: 'guide.txt' })).toHaveAttribute('aria-current', 'page')
  fireEvent.click(screen.getByRole('button', { name: 'Tree' }))
  expect(screen.getByRole('button', { name: 'Tree' })).toHaveAttribute('aria-pressed', 'false')
  expect(screen.queryByRole('navigation', { name: 'Project files' })).not.toBeInTheDocument()
  expect(screen.getByText('Content of docs/guide.txt')).toBe(code)
  expect(fetch).toHaveBeenCalledTimes(3)
  expect(screen.queryByText(`Commit ${sha.slice(0, 12)}`)).not.toBeInTheDocument()
})

it('reveals ancestors of a file selected in classic view and preserves the selected branch', async () => {
  const fetch = stub()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  fireEvent.click(await screen.findByRole('button', { name: /docs.*Folder/ }))
  fireEvent.click(await screen.findByRole('button', { name: /guide.txt.*bytes/ }))
  await screen.findByText('Content of docs/guide.txt')
  fireEvent.click(screen.getByRole('button', { name: 'Tree' }))
  const tree = within(screen.getByRole('navigation', { name: 'Project files' }))
  expect(await tree.findByRole('button', { name: 'guide.txt' })).toHaveAttribute(
    'aria-current',
    'page',
  )
  expect(tree.getByRole('button', { name: 'docs' })).toHaveAttribute('aria-expanded', 'true')
  expect(screen.getByLabelText('Branch')).toHaveValue('main')
  expect(screen.getByText('Content of docs/guide.txt')).toBeInTheDocument()
  expect(
    fetch.mock.calls
      .slice(3)
      .every(([url]) => url.includes(`sha=${sha}`) && url.includes('refresh=0')),
  ).toBe(true)
})

it('removes both code and tree after a folder request reports lost access', async () => {
  const fetch = stub()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await openFile()
  fireEvent.click(screen.getByRole('button', { name: 'Tree' }))
  const tree = within(screen.getByRole('navigation', { name: 'Project files' }))
  await tree.findByRole('button', { name: 'docs' })
  fetch.mockResolvedValueOnce(
    Response.json(
      { error: { code: 'share_unavailable', message: 'This link was revoked.' } },
      { status: 404 },
    ),
  )
  fireEvent.click(tree.getByRole('button', { name: 'docs' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('This link was revoked.')
  expect(screen.queryByText('Content of README.txt')).not.toBeInTheDocument()
  expect(screen.queryByRole('navigation', { name: 'Project files' })).not.toBeInTheDocument()
})

it('ignores an old tree response after Refresh moves the preview to another commit', async () => {
  const fetch = stub()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await openFile()
  let complete: (response: Response) => void = () => {}
  fetch.mockImplementationOnce(
    () =>
      new Promise(resolve => {
        complete = resolve
      }),
  )
  fireEvent.click(screen.getByRole('button', { name: 'Tree' }))
  await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3))
  fetch.mockImplementation(async (url: string) => {
    const params = new URL(url, 'http://localhost').searchParams
    const value = preview(params.get('path') || '', params.get('kind') || 'directory', nextSha)
    if (value.kind === 'directory')
      value.entries = [{ name: 'new.txt', path: 'new.txt', type: 'file', size: 1 }]
    return Response.json(value)
  })
  fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
  await waitFor(() =>
    expect(fetch.mock.calls.some(([url]) => url.includes(`sha=${nextSha}`))).toBe(true),
  )
  const tree = within(screen.getByRole('navigation', { name: 'Project files' }))
  await tree.findByRole('button', { name: 'new.txt' })
  await act(async () => {
    complete(
      Response.json({
        ...preview(''),
        entries: [{ name: 'old.txt', path: 'old.txt', type: 'file', size: 1 }],
      }),
    )
  })
  expect(screen.queryByRole('button', { name: 'old.txt' })).not.toBeInTheDocument()
  expect(tree.getByRole('button', { name: 'new.txt' })).toBeInTheDocument()
})

it('aborts a folder read when it is collapsed and preserves disclosure state during file navigation', async () => {
  const fetch = stub()
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await screen.findByRole('button', { name: /README.txt.*bytes/ })
  fireEvent.click(screen.getByRole('button', { name: 'Tree' }))
  const tree = within(screen.getByRole('navigation', { name: 'Project files' }))
  const abort = vi.fn()
  vi.stubGlobal(
    'fetch',
    vi.fn(
      (_url: string, options: RequestInit) =>
        new Promise((_resolve, reject) => {
          options.signal?.addEventListener('abort', () => {
            abort()
            reject(new DOMException('Aborted', 'AbortError'))
          })
        }),
    ),
  )
  fireEvent.click(tree.getByRole('button', { name: 'docs' }))
  await tree.findByRole('status')
  fireEvent.click(tree.getByRole('button', { name: 'docs' }))
  expect(abort).toHaveBeenCalledTimes(1)
  vi.stubGlobal('fetch', fetch)
  fireEvent.click(tree.getByRole('button', { name: 'README.txt' }))
  await screen.findByText('Content of README.txt')
  expect(tree.getByRole('button', { name: 'docs' })).toHaveAttribute('aria-expanded', 'false')
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('labels truncated folders and renders unsupported entries and markup names safely', async () => {
  const fetch = stub()
  fetch.mockResolvedValueOnce(
    Response.json({
      ...preview(''),
      limited: true,
      entries: [
        {
          name: '<script>run()</script>',
          path: '<script>run()</script>',
          type: 'submodule',
          size: 0,
        },
      ],
    }),
  )
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint={endpoint} />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/alpha')
  fireEvent.click(screen.getByRole('button', { name: 'Tree' }))
  const tree = within(screen.getByRole('navigation', { name: 'Project files' }))
  expect(tree.getByText('Only the first 1,000 items are available.')).toBeInTheDocument()
  expect(tree.getByRole('button', { name: /<script>run\(\)<\/script>/ })).toBeDisabled()
  expect(document.querySelector('script')).toBeNull()
  expect(fetch).toHaveBeenCalledTimes(1)
})
