import { render, screen, waitFor } from '../render'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from '../router'
import { expect, it, vi } from 'vitest'
import { QueryScope } from '../../src/api/QueryScope'
import type {
  GitHubConnection,
  GitLabConnection,
} from '../../src/features/workspace/connections/api'
import type { Session } from '../../src/features/auth/api'
import { Connections } from '../../src/features/workspace/connections/Connections'
import { WorkspaceSessionContext } from '../../src/features/auth/session'

const session: Session = {
  csrfToken: 'csrf-fixture',
  user: { id: 7, username: 'owner' },
  github: { configured: false, status: 'not_configured', credentialVersion: 0 },
  gitlab: { configured: false, status: 'not_configured', credentialVersion: 0 },
  passwordChangeRequired: false,
}
const cache = {
  entries: 0,
  bytes: 0,
  maxBytes: 33554432,
  maxEntries: 256,
  hits: 0,
  misses: 0,
  upstreamCalls: 0,
  revalidations: 0,
  evictions: 0,
  providers: {
    github: { rateLimit: null, rateRemaining: null, rateReset: null, rateCheckedAt: null },
    gitlab: { rateLimit: null, rateRemaining: null, rateReset: null, rateCheckedAt: null },
  },
}
const missing: GitHubConnection = {
  configured: false,
  status: 'not_configured',
  maskedToken: null,
  credentialVersion: 0,
  githubLogin: null,
  lastCheckedAt: null,
  errorCode: null,
}
const saved: GitHubConnection = {
  configured: true,
  status: 'connected',
  maskedToken: 'abcdefgh********',
  credentialVersion: 1,
  githubLogin: 'octo',
  lastCheckedAt: '2026-09-24T10:00:00Z',
  errorCode: null,
}
const gitlabMissing: GitLabConnection = {
  configured: false,
  status: 'not_configured',
  baseUrl: null,
  defaultUrl: 'https://gitlab.com',
  maskedToken: null,
  credentialVersion: 0,
  gitlabUsername: null,
  tokenExpiresAt: null,
  scopes: null,
  lastCheckedAt: null,
  errorCode: null,
}

function mount(fetch: ReturnType<typeof vi.fn>, updateConnection = vi.fn(), expire = vi.fn()) {
  vi.stubGlobal('fetch', fetch)
  render(
    <MemoryRouter initialEntries={['/app/connections#github-connection']}>
      <QueryScope onUnauthorized={expire}>
        <WorkspaceSessionContext.Provider
          value={{ session, expire, updateConnection, replace: vi.fn() }}
        >
          <Connections />
        </WorkspaceSessionContext.Provider>
      </QueryScope>
    </MemoryRouter>,
  )
  return { updateConnection, expire }
}

function api(put: () => Response) {
  return vi.fn((path: string, init?: RequestInit) =>
    Promise.resolve(
      init?.method === 'PUT'
        ? put()
        : path === '/api/workspace/preview-cache'
          ? Response.json(cache)
          : path === '/api/workspace/connections/gitlab'
            ? Response.json(gitlabMissing)
            : Response.json(
                init?.method === undefined && path === '/api/workspace/connections/github'
                  ? missing
                  : saved,
              ),
    ),
  )
}

it('shows a missing connection and saves a verified token through an accessible dialog', async () => {
  const user = userEvent.setup()
  let resolveSave: (response: Response) => void = () => {}
  const fetch = api(() => {
    throw new Error('unused')
  })
  fetch.mockImplementation((path: string, init?: RequestInit) =>
    init?.method === 'PUT'
      ? new Promise<Response>(resolve => {
          resolveSave = resolve
        })
      : Promise.resolve(
          path === '/api/workspace/preview-cache'
            ? Response.json(cache)
            : Response.json(path === '/api/workspace/connections/gitlab' ? gitlabMissing : missing),
        ),
  )
  const { updateConnection } = mount(fetch)
  expect(await screen.findAllByText('Not configured')).toHaveLength(2)
  expect(
    screen.getByText('Cache for your workspace and visitors using your access links.'),
  ).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Run connection check' })).not.toBeInTheDocument()
  await waitFor(() => expect(document.activeElement).toHaveAttribute('id', 'github-connection'))

  await user.click(screen.getByRole('button', { name: 'Configure GitHub' }))
  const dialog = screen.getByRole('dialog', { name: 'Configure GitHub' })
  expect(dialog).toHaveAttribute('aria-modal', 'true')
  expect(screen.getByLabelText('Personal access token')).toHaveFocus()
  await user.keyboard('{Escape}')
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Configure GitHub' })).toHaveFocus(),
  )

  await user.click(screen.getByRole('button', { name: 'Configure GitHub' }))
  const input = screen.getByLabelText('Personal access token')
  expect(input).toHaveValue('')
  await user.type(input, 'abcdefgh12345678')
  await user.click(screen.getByRole('button', { name: 'Show token' }))
  expect(input).toHaveAttribute('type', 'text')
  await user.click(screen.getByRole('button', { name: 'Save & verify' }))
  expect(screen.getByRole('button', { name: 'Verifying…' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled()
  const call = fetch.mock.calls.find(([, init]) => init?.method === 'PUT')
  expect(call?.[0]).toBe('/api/workspace/connections/github')
  expect(call?.[1]).toMatchObject({
    body: JSON.stringify({ token: 'abcdefgh12345678', expectedVersion: 0 }),
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': 'csrf-fixture' },
  })
  fetch.mockImplementation((path: string) =>
    Promise.resolve(
      path === '/api/workspace/preview-cache'
        ? Response.json(cache)
        : Response.json(path === '/api/workspace/connections/gitlab' ? gitlabMissing : saved),
    ),
  )
  resolveSave(Response.json(saved))
  expect(await screen.findByText('abcdefgh********')).toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.getByRole('status')).toHaveTextContent('GitHub connected as octo.')
  expect(screen.getByRole('link', { name: 'Open repositories' })).toHaveAttribute('href', '/app')
  expect(updateConnection).toHaveBeenCalledWith('github', {
    configured: true,
    status: 'connected',
    credentialVersion: 1,
  })
  expect(document.body.textContent).not.toContain('abcdefgh12345678')
})

it('keeps the dialog open with a clear error when the candidate is rejected', async () => {
  const user = userEvent.setup()
  mount(
    api(() =>
      Response.json(
        { error: { code: 'github_token_rejected', message: 'GitHub rejected this token.' } },
        { status: 422 },
      ),
    ),
  )
  await user.click(await screen.findByRole('button', { name: 'Configure GitHub' }))
  await user.type(screen.getByLabelText('Personal access token'), 'bad-token')
  await user.click(screen.getByRole('button', { name: 'Save & verify' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('GitHub rejected this token.')
  expect(screen.getByRole('dialog')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Save & verify' })).toBeEnabled()
  await user.click(screen.getByRole('button', { name: 'Cancel' }))
  await user.click(screen.getByRole('button', { name: 'Configure GitHub' }))
  expect(screen.getByLabelText('Personal access token')).toHaveValue('')
})

it('ends the workspace when the Glimpse session expired', async () => {
  const { expire } = mount(
    vi.fn(() =>
      Promise.resolve(
        Response.json(
          { error: { code: 'unauthorized', message: 'Sign in to continue.' } },
          { status: 401 },
        ),
      ),
    ),
  )
  await waitFor(() => expect(expire).toHaveBeenCalled())
})

it('shows the loading state on the Configure buttons instead of a separate message', async () => {
  let release: () => void = () => {}
  const loaded = new Promise<void>(resolve => {
    release = resolve
  })
  const fetch = api(() => {
    throw new Error('unused')
  })
  const answer = fetch.getMockImplementation()!
  fetch.mockImplementation((path: string, init?: RequestInit) =>
    loaded.then(() => answer(path, init)),
  )
  mount(fetch)
  for (const name of ['Configure GitHub', 'Configure GitLab']) {
    const button = screen.getByRole('button', { name })
    expect(button).toBeDisabled()
    expect(button).toHaveAttribute('aria-busy', 'true')
  }
  expect(screen.queryByText(/Checking the connection/)).not.toBeInTheDocument()

  release()
  expect(await screen.findAllByText('Not configured')).toHaveLength(2)
  for (const name of ['Configure GitHub', 'Configure GitLab']) {
    const button = screen.getByRole('button', { name })
    expect(button).toBeEnabled()
    expect(button).toHaveAttribute('aria-busy', 'false')
  }
})
