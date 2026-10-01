import { act, fireEvent, render, screen, waitFor } from '../render'
import { afterEach, expect, it, vi } from 'vitest'
import { MemoryRouter } from '../router'
import { RepositoryBrowser } from '../../src/features/repositories/RepositoryBrowser'

vi.mock('../../src/features/repositories/useBranches', () => ({
  useBranches: () => ({
    data: { items: ['main'], defaultBranch: 'main', allowHistory: true },
    loading: false,
  }),
}))

const preview = {
  repository: { fullName: 'fixture/alpha' },
  commitSha: 'a'.repeat(40),
  path: '',
  kind: 'directory',
  entries: [],
}
afterEach(() => vi.useRealTimers())

it('refreshes the branch explicitly and keeps navigation at the selected SHA', async () => {
  const fetch = vi.fn().mockImplementation(async () =>
    Response.json({
      ...preview,
      entries: [{ name: 'docs', path: 'docs', type: 'dir', size: 0 }],
    }),
  )
  vi.stubGlobal('fetch', fetch)
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/workspace/repositories/fixture/alpha/browse" />
    </MemoryRouter>,
  )
  fireEvent.click(await screen.findByRole('button', { name: /docs.*Folder/ }))
  await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
  expect(fetch.mock.calls[1]?.[0]).toContain(`sha=${'a'.repeat(40)}`)
  expect(fetch.mock.calls[1]?.[0]).toContain('refresh=0')
  await waitFor(() => expect(screen.getByRole('button', { name: 'Refresh' })).toBeEnabled())
  fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
  expect(fetch.mock.calls.at(-1)?.[0]).toContain('sha=&ref=&branch=main&q=&refresh=1')
})

it('respects Retry-After without automatically retrying an exhausted quota', async () => {
  vi.useFakeTimers()
  const fetch = vi
    .fn()
    .mockImplementation(async () =>
      Response.json(
        { error: { code: 'github_rate_limited', message: 'GitHub quota is unavailable.' } },
        { status: 429, headers: { 'Retry-After': '5' } },
      ),
    )
  vi.stubGlobal('fetch', fetch)
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/workspace/repositories/fixture/alpha/browse" />
    </MemoryRouter>,
  )
  await act(async () => {
    await vi.advanceTimersByTimeAsync(1)
  })
  expect(screen.getByRole('button', { name: /Try again in/ })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Refresh' })).toBeDisabled()
  await act(async () => {
    await vi.advanceTimersByTimeAsync(5500)
  })
  expect(screen.getByRole('button', { name: 'Try again' })).toBeEnabled()
  expect(fetch).toHaveBeenCalledTimes(1)
})

it('retries a busy reservation once and stops after a second failure', async () => {
  vi.useFakeTimers()
  const fetch = vi
    .fn()
    .mockImplementation(async () =>
      Response.json(
        { error: { code: 'github_busy', message: 'A GitHub check is already running.' } },
        { status: 503, headers: { 'Retry-After': '2' } },
      ),
    )
  vi.stubGlobal('fetch', fetch)
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/workspace/repositories/fixture/alpha/browse" />
    </MemoryRouter>,
  )
  await act(async () => {
    await vi.advanceTimersByTimeAsync(10000)
  })
  expect(fetch).toHaveBeenCalledTimes(2)
  expect(screen.getByRole('alert')).toHaveTextContent('A GitHub check is already running.')
})

it('aborts a stalled preview and leaves a visible retry action', async () => {
  vi.useFakeTimers()
  vi.stubGlobal(
    'fetch',
    vi.fn(
      (_url: string, options: RequestInit) =>
        new Promise((_resolve, reject) => {
          options.signal?.addEventListener('abort', () =>
            reject(new DOMException('Aborted', 'AbortError')),
          )
        }),
    ),
  )
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/workspace/repositories/fixture/alpha/browse" />
    </MemoryRouter>,
  )
  await act(async () => {
    await vi.advanceTimersByTimeAsync(20001)
  })
  expect(screen.getByRole('alert')).toHaveTextContent('took too long')
  expect(screen.getByRole('button', { name: 'Try again' })).toBeEnabled()
})

it('discards a delayed response after switching repositories', async () => {
  let complete: (value: Response) => void = () => {}
  const fetch = vi
    .fn()
    .mockImplementationOnce(
      () =>
        new Promise<Response>(resolve => {
          complete = resolve
        }),
    )
    .mockResolvedValue(Response.json({ ...preview, repository: { fullName: 'fixture/beta' } }))
  vi.stubGlobal('fetch', fetch)
  const view = render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/workspace/repositories/fixture/alpha/browse" />
    </MemoryRouter>,
  )
  view.rerender(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/workspace/repositories/fixture/beta/browse" />
    </MemoryRouter>,
  )
  await screen.findByText('fixture/beta')
  await act(async () => {
    complete(Response.json(preview))
  })
  expect(screen.queryByText('fixture/alpha')).not.toBeInTheDocument()
  expect(screen.getByText('fixture/beta')).toBeInTheDocument()
})

it('removes the previous file when Refresh fails instead of presenting it as current', async () => {
  const fetch = vi
    .fn()
    .mockResolvedValueOnce(
      Response.json({
        ...preview,
        kind: 'file',
        path: 'note.txt',
        file: {
          path: 'note.txt',
          commitSha: preview.commitSha,
          content: 'Old private text',
          bytes: 16,
        },
      }),
    )
    .mockResolvedValue(
      Response.json(
        { error: { code: 'github_not_found', message: 'The file is unavailable.' } },
        { status: 502 },
      ),
    )
  vi.stubGlobal('fetch', fetch)
  render(
    <MemoryRouter>
      <RepositoryBrowser endpoint="/api/workspace/repositories/fixture/alpha/browse" />
    </MemoryRouter>,
  )
  await screen.findByText('Old private text')
  fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
  expect(screen.queryByText('Old private text')).not.toBeInTheDocument()
  expect(await screen.findByRole('alert')).toHaveTextContent('The file is unavailable.')
  expect(screen.queryByText('Old private text')).not.toBeInTheDocument()
})
