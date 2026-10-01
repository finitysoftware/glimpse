import { act, cleanup, render, screen, waitFor, within } from '../render'
import userEvent from '@testing-library/user-event'
import { Route, Routes, useLocation } from 'react-router-dom'
import { MemoryRouter } from '../router'
import { expect, it, vi } from 'vitest'
import type { ConnectionSummary, Session } from '../../src/features/auth/api'
import type {
  GitHubConnection,
  GitLabConnection,
} from '../../src/features/workspace/connections/api'
import type { Repository } from '../../src/features/repositories/api'
import { Repositories } from '../../src/features/repositories/Repositories'
import { GitLabProjectDetail } from '../../src/features/repositories/RepositoryPage'
import { Connections } from '../../src/features/workspace/connections/Connections'
import { WorkspaceSessionContext } from '../../src/features/auth/session'

vi.mock('../../src/features/repositories/useBranches', () => ({
  useBranches: () => ({
    data: { items: ['main'], defaultBranch: 'main', allowHistory: true },
    loading: false,
  }),
}))

const connected: ConnectionSummary = { configured: true, status: 'connected', credentialVersion: 1 }
const missing: ConnectionSummary = {
  configured: false,
  status: 'not_configured',
  credentialVersion: 0,
}
const sessionWith = (github: ConnectionSummary, gitlab: ConnectionSummary): Session => ({
  csrfToken: 'csrf-fixture',
  user: { id: 7, username: 'owner' },
  github,
  gitlab,
  passwordChangeRequired: false,
})
const repository = (overrides: Partial<Repository>): Repository => ({
  provider: 'github',
  id: 1,
  fullName: 'owner/app',
  owner: 'owner',
  name: 'app',
  private: true,
  visibility: 'private',
  defaultBranch: 'main',
  description: '',
  ...overrides,
})
const githubItems = [
  repository({ id: 101, fullName: 'octo/zeta', owner: 'octo', name: 'zeta' }),
  repository({
    id: 102,
    fullName: 'octo/alpha',
    owner: 'octo',
    name: 'alpha',
    private: false,
    visibility: 'public',
  }),
]
const gitlabItems = [
  repository({
    provider: 'gitlab',
    id: 42,
    fullName: 'group/sub/beta',
    owner: 'group/sub',
    name: 'beta',
    visibility: 'internal',
  }),
]

function Location() {
  const location = useLocation()
  return <output aria-label="Location">{location.pathname + location.search}</output>
}

function catalogue(overrides: Partial<Record<'github' | 'gitlab', () => Response>> = {}) {
  return vi.fn((path: string) => {
    const platform = new URL(path, 'https://glimpse.example').searchParams.get('provider') as
      'github' | 'gitlab'
    return Promise.resolve(
      overrides[platform]?.() ??
        Response.json({
          items: platform === 'gitlab' ? gitlabItems : githubItems,
          nextPage: platform === 'github' ? 2 : null,
        }),
    )
  })
}

function mountList(session: Session, fetch: ReturnType<typeof vi.fn>, path = '/app') {
  vi.stubGlobal('fetch', fetch)
  render(
    <MemoryRouter initialEntries={[path]}>
      <WorkspaceSessionContext.Provider
        value={{ session, expire: vi.fn(), updateConnection: vi.fn(), replace: vi.fn() }}
      >
        <Routes>
          <Route
            path="/app"
            element={
              <>
                <Repositories />
                <Location />
              </>
            }
          />
        </Routes>
      </WorkspaceSessionContext.Provider>
    </MemoryRouter>,
  )
}

const requested = (fetch: ReturnType<typeof vi.fn>) =>
  fetch.mock.calls.map(([path]) => String(path))

it('merges both platforms into one sorted page with a platform badge on every repository', async () => {
  const fetch = catalogue()
  mountList(sessionWith(connected, connected), fetch)
  const list = await screen.findAllByRole('link', { name: /octo|group/ })
  expect(list.map(link => within(link).getByRole('heading').textContent)).toEqual([
    'group/sub/beta',
    'octo/alpha',
    'octo/zeta',
  ])
  expect(list[0]).toHaveAttribute('href', '/app/gitlab/42')
  expect(list[1]).toHaveAttribute('href', '/app/repositories/octo/alpha')
  expect(within(list[0]!).getByText('GitLab')).toBeInTheDocument()
  expect(within(list[0]!).getByText('Internal')).toBeInTheDocument()
  expect(within(list[1]!).getByText('GitHub')).toBeInTheDocument()
  expect(within(list[1]!).getByText('Public')).toBeInTheDocument()
  expect(within(list[2]!).getByText('Private')).toBeInTheDocument()
  expect(requested(fetch).sort()).toEqual([
    '/api/workspace/repositories?provider=github&page=1',
    '/api/workspace/repositories?provider=gitlab&page=1',
  ])
  expect(screen.queryByText(/Available through your/)).not.toBeInTheDocument()
  // Next page exists because GitHub has one, although GitLab does not.
  expect(screen.getByRole('button', { name: /Next/ })).toBeEnabled()
})

it('keeps the catalogue page in the address', async () => {
  const user = userEvent.setup()
  const fetch = catalogue()
  mountList(sessionWith(connected, connected), fetch, '/app?page=2')
  expect(await screen.findByText('Page 2')).toBeInTheDocument()
  expect(requested(fetch).sort()).toEqual([
    '/api/workspace/repositories?provider=github&page=2',
    '/api/workspace/repositories?provider=gitlab&page=2',
  ])
  await user.click(screen.getByRole('button', { name: /Previous/ }))
  await waitFor(() => expect(screen.getByLabelText('Location')).toHaveTextContent(/^\/app$/))
  expect(await screen.findByText('Page 1')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: /Next/ }))
  await waitFor(() =>
    expect(screen.getByLabelText('Location')).toHaveTextContent(/^\/app\?page=2$/),
  )
})

it('filters platforms with a checkbox menu that keeps at least one platform selected', async () => {
  const user = userEvent.setup()
  const fetch = catalogue()
  mountList(sessionWith(connected, connected), fetch)
  const trigger = await screen.findByRole('button', { name: /Platforms.*All platforms/ })
  expect(trigger).toHaveAttribute('aria-expanded', 'false')
  // Opened from the keyboard, the menu starts on its first option.
  act(() => trigger.focus())
  await user.keyboard('{Enter}')
  const options = await screen.findAllByRole('menuitemcheckbox')
  expect(options.map(option => [option.textContent, option.getAttribute('aria-checked')])).toEqual([
    ['GitHub', 'true'],
    ['GitLab', 'true'],
  ])
  await waitFor(() => expect(options[0]).toHaveFocus())
  await user.keyboard('{ArrowDown}')
  expect(options[1]).toHaveFocus()
  const before = requested(fetch).length
  await user.click(options[1]!)
  await waitFor(() =>
    expect(screen.getByRole('menuitemcheckbox', { name: 'GitLab' })).toHaveAttribute(
      'aria-checked',
      'false',
    ),
  )
  expect(screen.getByRole('menu')).toBeInTheDocument()
  expect(screen.getByLabelText('Location')).toHaveTextContent('/app?platforms=github')
  await waitFor(() => expect(screen.queryByText('group/sub/beta')).not.toBeInTheDocument())
  // The GitHub page stays from the cache, and nothing is requested from GitLab any more.
  expect(requested(fetch).slice(before)).toEqual([])
  // The last selected platform cannot be cleared.
  const github = screen.getByRole('menuitemcheckbox', { name: 'GitHub' })
  expect(github).toHaveAttribute('aria-disabled', 'true')
  expect(github).toHaveAttribute('title', 'At least one platform stays selected')
  await user.click(github)
  expect(github).toHaveAttribute('aria-checked', 'true')
  await user.keyboard('{Escape}')
  expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: /Platforms.*GitHub/ })).toHaveFocus()
  await user.click(screen.getByRole('button', { name: /Platforms/ }))
  await user.click(screen.getByRole('menuitemcheckbox', { name: 'GitLab' }))
  expect(screen.getByLabelText('Location')).toHaveTextContent(/^\/app$/)
  expect(await screen.findByText('group/sub/beta')).toBeInTheDocument()
})

it('starts from the platforms in the address and shows a failing platform as a notice', async () => {
  const fetch = catalogue({
    gitlab: () =>
      Response.json(
        {
          error: {
            code: 'gitlab_credentials_rejected',
            message: 'GitLab credentials were rejected.',
          },
        },
        { status: 502 },
      ),
  })
  mountList(sessionWith(connected, connected), fetch, '/app?platforms=gitlab')
  expect(
    await screen.findByRole('heading', { name: 'GitLab rejected the saved token.' }),
  ).toBeInTheDocument()
  expect(requested(fetch)).toEqual(['/api/workspace/repositories?provider=gitlab&page=1'])
  expect(screen.getByRole('link', { name: 'Configure GitLab' })).toHaveAttribute(
    'href',
    '/app/connections#gitlab-connection',
  )
  cleanup()
  const both = catalogue({
    gitlab: () =>
      Response.json(
        {
          error: {
            code: 'gitlab_credentials_rejected',
            message: 'GitLab credentials were rejected.',
          },
        },
        { status: 502 },
      ),
  })
  mountList(sessionWith(connected, connected), both)
  expect(await screen.findByText('octo/alpha')).toBeInTheDocument()
  const notice = screen.getByRole('alert')
  expect(notice).toHaveTextContent('GitLab: GitLab rejected the saved token.')
  expect(within(notice).getByRole('link', { name: 'Configure GitLab' })).toHaveAttribute(
    'href',
    '/app/connections#gitlab-connection',
  )
})

it('keeps the single-platform view and onboarding when fewer than two platforms are connected', async () => {
  const fetch = catalogue()
  mountList(sessionWith(missing, connected), fetch)
  expect(await screen.findByText('Available through your GitLab connection')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Platforms/ })).not.toBeInTheDocument()
  expect(screen.queryByText('GitLab', { selector: 'span' })).not.toBeInTheDocument()
  expect(requested(fetch)).toEqual(['/api/workspace/repositories?provider=gitlab&page=1'])
  cleanup()
  const none = catalogue()
  mountList(sessionWith(missing, missing), none)
  expect(
    screen.getByRole('heading', { name: 'Connect GitHub or GitLab to see your repositories.' }),
  ).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Configure GitLab' })).toHaveAttribute(
    'href',
    '/app/connections#gitlab-connection',
  )
  expect(none).not.toHaveBeenCalled()
})

it('names a GitLab project page from the catalogue and then from the project', async () => {
  const preview = {
    repository: {
      id: 42,
      fullName: 'group/sub/beta-renamed',
      private: true,
      defaultBranch: 'main',
      provider: 'gitlab',
    },
    commitSha: 'a'.repeat(40),
    path: '',
    kind: 'directory',
    entries: [],
  }
  vi.stubGlobal(
    'fetch',
    vi.fn((path: string) =>
      Promise.resolve(
        Response.json(
          path.includes('/shares')
            ? { items: [], active: true, nextPage: null, storageConfigured: true }
            : preview,
        ),
      ),
    ),
  )
  render(
    <MemoryRouter
      initialEntries={[{ pathname: '/app/gitlab/42', state: { fullName: 'group/sub/beta' } }]}
    >
      <WorkspaceSessionContext.Provider
        value={{
          session: sessionWith(missing, connected),
          expire: vi.fn(),
          updateConnection: vi.fn(),
          replace: vi.fn(),
        }}
      >
        <Routes>
          <Route
            path="/app/gitlab/:projectId"
            element={<GitLabProjectDetail csrfToken="csrf-fixture" />}
          />
        </Routes>
      </WorkspaceSessionContext.Provider>
    </MemoryRouter>,
  )
  expect(screen.getByText('GitLab · group/sub')).toBeInTheDocument()
  expect(await screen.findByRole('heading', { level: 1, name: 'beta-renamed' })).toBeInTheDocument()
  const calls = vi.mocked(fetch).mock.calls.map(([path]) => String(path))
  expect(calls.some(path => path.startsWith('/api/workspace/gitlab/projects/42/browse?'))).toBe(
    true,
  )
})

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
const gitlabSaved: GitLabConnection = {
  ...gitlabMissing,
  configured: true,
  status: 'connected',
  baseUrl: 'https://code.example.org',
  maskedToken: 'glpat-abc****',
  credentialVersion: 3,
  gitlabUsername: 'jdoe',
  tokenExpiresAt: new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10),
  scopes: ['read_api'],
  lastCheckedAt: '2026-09-24T10:00:00Z',
}
const githubSaved: GitHubConnection = {
  configured: true,
  status: 'connected',
  maskedToken: 'abcdefgh********',
  credentialVersion: 1,
  githubLogin: 'octo',
  lastCheckedAt: '2026-09-24T10:00:00Z',
  errorCode: null,
}
const cache = {
  entries: 0,
  bytes: 0,
  maxBytes: 67108864,
  maxEntries: 512,
  hits: 0,
  misses: 0,
  upstreamCalls: 0,
  revalidations: 0,
  evictions: 0,
  providers: {
    github: { rateLimit: 5000, rateRemaining: 4990, rateReset: null, rateCheckedAt: null },
    gitlab: { rateLimit: 2000, rateRemaining: 1999, rateReset: null, rateCheckedAt: null },
  },
}

function mountConnections(fetch: ReturnType<typeof vi.fn>, updateConnection = vi.fn()) {
  vi.stubGlobal('fetch', fetch)
  render(
    <MemoryRouter initialEntries={['/app/connections#gitlab-connection']}>
      <WorkspaceSessionContext.Provider
        value={{
          session: sessionWith(connected, missing),
          expire: vi.fn(),
          updateConnection,
          replace: vi.fn(),
        }}
      >
        <Connections />
      </WorkspaceSessionContext.Provider>
    </MemoryRouter>,
  )
  return updateConnection
}

it('configures GitLab with its address and token in one verified step', async () => {
  const user = userEvent.setup()
  let gitlab = gitlabMissing
  const fetch = vi.fn((path: string, init?: RequestInit) => {
    if (init?.method === 'PUT') {
      gitlab = gitlabSaved
      return Promise.resolve(Response.json(gitlabSaved))
    }
    return Promise.resolve(
      Response.json(
        path === '/api/workspace/preview-cache'
          ? cache
          : path.endsWith('/gitlab')
            ? gitlab
            : githubSaved,
      ),
    )
  })
  const updateConnection = mountConnections(fetch)
  await waitFor(() => expect(document.activeElement).toHaveAttribute('id', 'gitlab-connection'))
  await user.click(screen.getByRole('button', { name: 'Configure GitLab' }))
  const dialog = screen.getByRole('dialog', { name: 'Configure GitLab' })
  const address = within(dialog).getByLabelText('GitLab URL')
  expect(address).toHaveValue('https://gitlab.com')
  expect(within(dialog).getByRole('link', { name: /Create a token on GitLab/ })).toHaveAttribute(
    'href',
    'https://gitlab.com/-/user_settings/personal_access_tokens?name=Glimpse&scopes=read_api',
  )
  await user.clear(address)
  await user.type(address, 'https://code.example.org/')
  expect(within(dialog).getByRole('link', { name: /Create a token on GitLab/ })).toHaveAttribute(
    'href',
    'https://code.example.org/-/user_settings/personal_access_tokens?name=Glimpse&scopes=read_api',
  )
  await user.type(within(dialog).getByLabelText('Personal access token'), 'glpat-secret-value')
  await user.click(within(dialog).getByRole('button', { name: 'Save & verify' }))
  const put = fetch.mock.calls.find(([, init]) => init?.method === 'PUT')
  expect(put?.[0]).toBe('/api/workspace/connections/gitlab')
  expect(JSON.parse(String(put?.[1]?.body))).toEqual({
    baseUrl: 'https://code.example.org/',
    token: 'glpat-secret-value',
    expectedVersion: 0,
  })
  expect(await screen.findByText('https://code.example.org')).toBeInTheDocument()
  expect(screen.getByText('jdoe')).toBeInTheDocument()
  expect(screen.getByText(/expires in 5 days/)).toBeInTheDocument()
  expect(screen.getByRole('status')).toHaveTextContent('GitLab connected as jdoe.')
  expect(updateConnection).toHaveBeenCalledWith('gitlab', {
    configured: true,
    status: 'connected',
    credentialVersion: 3,
  })
  expect(await screen.findByText('1999 / 2000')).toBeInTheDocument()
  expect(document.body.textContent).not.toContain('glpat-secret-value')
})

it('disconnects a platform after confirmation and keeps the other one', async () => {
  const user = userEvent.setup()
  let gitlab = gitlabSaved
  const fetch = vi.fn((path: string, init?: RequestInit) => {
    if (init?.method === 'POST' && path === '/api/workspace/connections/gitlab/disconnect') {
      gitlab = gitlabMissing
      return Promise.resolve(Response.json(gitlabMissing))
    }
    return Promise.resolve(
      Response.json(
        path === '/api/workspace/preview-cache'
          ? cache
          : path.endsWith('/gitlab')
            ? gitlab
            : githubSaved,
      ),
    )
  })
  const updateConnection = mountConnections(fetch)
  const card = await screen.findByRole('region', { name: 'GitLab connection' })
  await user.click(await within(card).findByRole('button', { name: 'Disconnect' }))
  const confirm = screen.getByRole('alertdialog', { name: 'Disconnect GitLab?' })
  expect(confirm).toHaveTextContent('Links and file visibility settings are kept.')
  expect(within(confirm).getByRole('button', { name: 'Cancel' })).toHaveFocus()
  await user.keyboard('{Escape}')
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(fetch.mock.calls.some(([path]) => String(path).endsWith('/disconnect'))).toBe(false)
  await user.click(within(card).getByRole('button', { name: 'Disconnect' }))
  await user.click(
    within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Disconnect GitLab' }),
  )
  expect(await screen.findByRole('status')).toHaveTextContent(
    'GitLab disconnected. Your links and file visibility settings are kept.',
  )
  expect(updateConnection).toHaveBeenCalledWith('gitlab', {
    configured: false,
    status: 'not_configured',
    credentialVersion: 0,
  })
  await waitFor(() =>
    expect(within(card).queryByRole('button', { name: 'Disconnect' })).not.toBeInTheDocument(),
  )
  expect(
    within(screen.getByRole('region', { name: 'GitHub connection' })).getByRole('button', {
      name: 'Disconnect',
    }),
  ).toBeInTheDocument()
})
