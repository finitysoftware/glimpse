import { render, screen, waitFor, within } from '../render'
import userEvent from '@testing-library/user-event'
import { useLocation, useNavigate } from 'react-router-dom'
import { MemoryRouter } from '../router'
import { expect, it, vi } from 'vitest'
import { App } from '../../src/app/App'
import type {
  AdminSession,
  AdminShare,
  AdminUser,
  AdminUserDetail,
  Dashboard,
} from '../../src/features/admin/api'

const session: AdminSession = {
  admin: {
    id: 1,
    username: 'root.admin',
    firstName: 'Root',
    lastName: 'Admin',
    email: 'root@example.com',
  },
  csrfToken: 'admin-csrf',
  passwordChangeRequired: false,
}
const base = {
  github: { status: 'not_configured' as const, login: null },
  gitlab: { status: 'not_configured' as const, username: null, baseUrl: null },
  links: { active: 0, total: 0 },
  lastSignInAt: null,
  createdAt: '2026-09-01T10:00:00Z',
}
const rootUser: AdminUser = {
  ...base,
  id: 1,
  username: 'root.admin',
  firstName: 'Root',
  lastName: 'Admin',
  email: 'root@example.com',
  role: 'admin',
  active: true,
  version: 1,
}
const ana: AdminUser = {
  ...base,
  id: 2,
  username: 'ana.anic',
  firstName: 'Ana',
  lastName: 'Anić',
  email: 'ana@example.com',
  role: 'user',
  active: true,
  version: 4,
  github: { status: 'connected', login: 'ana-gh' },
  links: { active: 1, total: 2 },
}
const anaDetail: AdminUserDetail = {
  ...ana,
  mustChangePassword: false,
  sessions: { workspace: 1, admin: 0 },
  repositories: { active: 1, total: 1 },
  linkStates: { active: 1, expired: 0, revoked: 1, selectedFiles: 0, history: 1, opens30Days: 3 },
  connection: {
    configured: true,
    status: 'connected',
    login: 'ana-gh',
    lastCheckedAt: null,
    errorCode: null,
    credentialVersion: 2,
  },
  gitlabConnection: {
    configured: false,
    status: 'not_configured',
    username: null,
    baseUrl: null,
    tokenExpiresAt: null,
    lastCheckedAt: null,
    errorCode: null,
    credentialVersion: 0,
  },
  cache: { entries: 3, bytes: 900, upstreamCalls: 4 },
}
const link: AdminShare = {
  publicId: 'a'.repeat(32),
  name: 'Client review',
  owner: { id: 2, username: 'ana.anic', firstName: 'Ana', lastName: 'Anić' },
  repository: {
    provider: 'github',
    instance: 'https://github.com',
    owner: 'fixture',
    name: 'alpha',
    active: true,
  },
  status: 'active',
  createdAt: '2026-09-02T10:00:00Z',
  expiresAt: null,
  branches: ['main'],
  allowHistory: true,
  policies: [{ branch: 'main', mode: 'selected', selectedCount: 2, profileVersion: 1 }],
}
const dashboard: Dashboard = {
  generatedAt: '2026-09-24T10:00:00Z',
  users: {
    total: 2,
    active: 2,
    disabled: 0,
    admins: 1,
    signedInLast7Days: 1,
    withoutGitHub: 1,
    withoutGitLab: 2,
  },
  github: {
    connected: 1,
    notVerified: 0,
    credentialsRejected: 0,
    accessRestricted: 0,
    temporarilyUnavailable: 0,
    notConfigured: 1,
  },
  gitlab: {
    connected: 0,
    notVerified: 0,
    credentialsRejected: 0,
    accessRestricted: 0,
    temporarilyUnavailable: 0,
    notConfigured: 2,
    expiringSoon: 0,
  },
  links: { active: 1, expired: 0, revoked: 1, selectedFiles: 1, history: 1 },
  visitors: { activeSessions: 2, opensLast7Days: 5, opensLast30Days: 9 },
  cache: {
    bytes: 2048,
    maxBytes: 268435456,
    entries: 3,
    upstreamCalls: 7,
    revalidations: 1,
    evictions: 0,
    usersInCooldown: 0,
  },
  system: {
    phpVersion: '8.5.13',
    databaseVersion: '11.4.12-MariaDB',
    environment: 'development',
    maintenance: false,
    migrations: { applied: 8, total: 8, latest: '008_admin_panel.sql', pending: [] },
    workspaceOrigins: ['http://127.0.0.1:5173'],
    sharePublicOrigin: 'http://127.0.0.1:5173',
    shareKeyConfigured: true,
    githubTokenKeyId: 'gh-key',
  },
  series: Array.from({ length: 14 }, (_, index) => ({
    day: `2026-09-${String(11 + index).padStart(2, '0')}`,
    workspaceLogins: index % 3,
    linkOpens: index % 4,
    linksCreated: 0,
  })),
}

type Handler = (body: Record<string, unknown>, url: URL) => Response
function api(routes: Record<string, Handler>) {
  const fetch = vi.fn(async (input: string, init: RequestInit = {}) => {
    const url = new URL(input, 'http://127.0.0.1')
    const handler = routes[`${init.method ?? 'GET'} ${url.pathname}`]
    if (!handler)
      return Response.json(
        {
          error: {
            code: 'not_found',
            message: `No fixture for ${init.method ?? 'GET'} ${url.pathname}`,
          },
        },
        { status: 404 },
      )
    return handler(
      typeof init.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : {},
      url,
    )
  })
  vi.stubGlobal('fetch', fetch)
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
  )
  return fetch
}
const calls = (fetch: ReturnType<typeof api>, method: string, path: string) =>
  fetch.mock.calls
    .filter(
      ([input, init]) =>
        (init?.method ?? 'GET') === method && new URL(input, 'http://127.0.0.1').pathname === path,
    )
    .map(([, init]) => ({
      body:
        typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : {},
      csrf: new Headers(init?.headers).get('X-CSRF-Token'),
    }))

function Location() {
  const location = useLocation()
  return <output aria-label="Location">{location.pathname + location.search}</output>
}
function Back() {
  const navigate = useNavigate()
  return <button onClick={() => void navigate(-1)}>Browser back</button>
}
function renderApp(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <Location />
      <Back />
    </MemoryRouter>,
  )
}

it('asks for the admin sign-in first and then shows the dashboard', async () => {
  const user = userEvent.setup()
  let signedIn = false
  const fetch = api({
    'GET /api/admin/auth/session': () =>
      signedIn
        ? Response.json(session)
        : Response.json(
            { error: { code: 'unauthorized', message: 'Sign in to continue.' } },
            { status: 401 },
          ),
    'POST /api/admin/auth/login': () => {
      signedIn = true
      return Response.json(session)
    },
    'GET /api/admin/dashboard': () => Response.json(dashboard),
  })
  renderApp('/admin/users')
  expect(await screen.findByText('Sign in to the Glimpse admin panel.')).toBeInTheDocument()
  expect(document.title).toBe('Admin sign-in · Glimpse')
  expect(screen.getByLabelText('Location')).toHaveTextContent(
    '/admin/login?returnTo=%2Fadmin%2Fusers',
  )
  await user.type(screen.getByLabelText('Username'), 'root.admin')
  await user.type(screen.getByLabelText('Password'), 'admin panel password')
  await user.click(screen.getByRole('button', { name: 'Sign in' }))
  expect(calls(fetch, 'POST', '/api/admin/auth/login')[0]?.body).toEqual({
    username: 'root.admin',
    password: 'admin panel password',
  })
  await waitFor(() => expect(screen.getByLabelText('Location')).toHaveTextContent('/admin/users'))
  await user.click(screen.getByRole('link', { name: 'Dashboard' }))
  expect(
    await screen.findByRole('heading', { name: /Good (morning|afternoon|evening), Root/ }),
  ).toBeInTheDocument()
  expect(within(screen.getByRole('region', { name: 'Users' })).getByText('2')).toBeInTheDocument()
  expect(screen.getByRole('region', { name: 'System' })).toHaveTextContent('Up to date')
  expect(screen.getByRole('img', { name: /Workspace sign-ins and link opens/ })).toBeInTheDocument()
})

it('edits a user: shows the GitHub token on request, saves only changed fields and replaces the token', async () => {
  const user = userEvent.setup()
  const fetch = api({
    'GET /api/admin/auth/session': () => Response.json(session),
    'GET /api/admin/users': () =>
      Response.json({ items: [rootUser, ana], page: 1, nextPage: null, total: 2 }),
    'GET /api/admin/users/2': () => Response.json(anaDetail),
    'POST /api/admin/users/2/github/token/reveal': () =>
      Response.json({ token: 'github_pat_saved_token' }),
    'PATCH /api/admin/users/2': body =>
      Response.json({ ...anaDetail, firstName: body.firstName, version: 5 }),
    'PUT /api/admin/users/2/github/token': () =>
      Response.json({
        ...anaDetail,
        firstName: 'Anna',
        version: 5,
        connection: { ...anaDetail.connection, credentialVersion: 3 },
      }),
  })
  renderApp('/admin/users')
  const row = (await screen.findByText('Ana Anić')).closest('tr')!
  expect(
    within(within(screen.getByRole('table')).getByText('Root Admin').closest('tr')!).getByText(
      'You',
    ),
  ).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Delete Root Admin' })).toBeDisabled()
  await user.click(within(row).getByRole('button', { name: 'Edit Ana Anić' }))
  const dialog = await screen.findByRole('dialog', { name: 'Edit user' })
  const token = within(dialog).getByLabelText('GitHub token')
  expect(token).toHaveAttribute('type', 'password')
  expect(token).toHaveValue('')
  expect(within(dialog).getByLabelText('Password')).toHaveValue('')
  await user.click(within(dialog).getByRole('button', { name: 'Show GitHub token' }))
  await waitFor(() => expect(token).toHaveValue('github_pat_saved_token'))
  expect(token).toHaveAttribute('type', 'text')
  await user.clear(token)
  await user.type(token, 'github_pat_new_token')
  await user.clear(within(dialog).getByLabelText('First name'))
  await user.type(within(dialog).getByLabelText('First name'), 'Anna')
  await user.click(within(dialog).getByRole('button', { name: 'Save' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(calls(fetch, 'PATCH', '/api/admin/users/2')).toEqual([
    { body: { expectedVersion: 4, firstName: 'Anna' }, csrf: 'admin-csrf' },
  ])
  expect(calls(fetch, 'PUT', '/api/admin/users/2/github/token')[0]?.body).toEqual({
    token: 'github_pat_new_token',
    expectedVersion: 2,
  })
  expect(screen.getByText('Anna Anić was saved.')).toHaveAttribute('role', 'status')
})

it('adds a user with a generated password and deletes another after confirmation', async () => {
  const user = userEvent.setup()
  const fetch = api({
    'GET /api/admin/auth/session': () => Response.json(session),
    'GET /api/admin/users': () =>
      Response.json({ items: [rootUser, ana], page: 1, nextPage: null, total: 2 }),
    'POST /api/admin/users': body =>
      Response.json(
        {
          ...anaDetail,
          id: 3,
          username: body.username,
          firstName: body.firstName,
          lastName: body.lastName,
        },
        { status: 201 },
      ),
    'POST /api/admin/users/2/delete': () => Response.json({ status: 'deleted' }),
  })
  renderApp('/admin/users')
  await user.click(await screen.findByRole('button', { name: 'Add user' }))
  const dialog = await screen.findByRole('dialog', { name: 'Add user' })
  await user.type(within(dialog).getByLabelText('First name'), 'Mia')
  await user.type(within(dialog).getByLabelText('Last name'), 'Marić')
  await user.type(within(dialog).getByLabelText('Username'), 'mia.maric')
  await user.click(within(dialog).getByRole('button', { name: 'Generate' }))
  const password = within(dialog).getByLabelText('Password')
  expect(password).toHaveAttribute('type', 'text')
  expect((password as HTMLInputElement).value).toHaveLength(20)
  await user.click(within(dialog).getByRole('button', { name: 'Create' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  const created = calls(fetch, 'POST', '/api/admin/users')[0]?.body
  expect(created).toMatchObject({
    firstName: 'Mia',
    lastName: 'Marić',
    username: 'mia.maric',
    role: 'user',
    requirePasswordChange: false,
  })
  expect(created?.password).toBe((password as HTMLInputElement).value)
  expect(created).not.toHaveProperty('githubToken')

  await user.click(screen.getByRole('button', { name: 'Delete Ana Anić' }))
  const confirm = await screen.findByRole('alertdialog', { name: 'Delete user' })
  expect(within(confirm).getByRole('button', { name: 'Cancel' })).toHaveFocus()
  await user.click(within(confirm).getByRole('button', { name: 'Delete' }))
  await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull())
  expect(calls(fetch, 'POST', '/api/admin/users/2/delete')[0]?.body).toEqual({ expectedVersion: 4 })
})

it('lists links of one user, shows a URL and revokes a link', async () => {
  const user = userEvent.setup()
  const fetch = api({
    'GET /api/admin/auth/session': () => Response.json(session),
    'GET /api/admin/shares': () =>
      Response.json({ items: [link], page: 1, nextPage: null, total: 1 }),
    [`POST /api/admin/shares/${link.publicId}/reveal`]: () =>
      Response.json({ url: `http://127.0.0.1:5173/s/${link.publicId}#secret` }),
    [`POST /api/admin/shares/${link.publicId}/revoke`]: () => Response.json({ status: 'revoked' }),
  })
  vi.stubGlobal('navigator', {
    ...navigator,
    clipboard: { writeText: vi.fn().mockResolvedValue(undefined) },
  })
  renderApp('/admin/links?user=2')
  expect(await screen.findByText('Client review')).toBeInTheDocument()
  expect(screen.getByText('Links of Ana Anić')).toBeInTheDocument()
  expect(
    new URL(
      fetch.mock.calls.find(([input]) => input.startsWith('/api/admin/shares'))![0],
      'http://x',
    ).searchParams.get('user'),
  ).toBe('2')
  expect(screen.getByText(/main: 2 selected · History on/)).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Show' }))
  expect(
    await screen.findByDisplayValue(`http://127.0.0.1:5173/s/${link.publicId}#secret`),
  ).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Copy' }))
  expect(await screen.findByRole('button', { name: 'Copied' })).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Hide' }))
  expect(screen.queryByDisplayValue(/#secret/)).toBeNull()
  await user.click(screen.getByRole('button', { name: 'Revoke' }))
  await user.click(
    within(await screen.findByRole('alertdialog', { name: 'Revoke link' })).getByRole('button', {
      name: 'Revoke',
    }),
  )
  await waitFor(() =>
    expect(calls(fetch, 'POST', `/api/admin/shares/${link.publicId}/revoke`)).toHaveLength(1),
  )
})

it('sends an administrator with a required password change to the account page first', async () => {
  api({
    'GET /api/admin/auth/session': () =>
      Response.json({ ...session, passwordChangeRequired: true }),
  })
  renderApp('/admin/users')
  expect(await screen.findByText('Set a new password before you continue.')).toBeInTheDocument()
  expect(screen.getByLabelText('Location')).toHaveTextContent('/admin/account?required=1')
})

it('manages a user GitLab connection next to GitHub and shows the platform of links', async () => {
  const user = userEvent.setup()
  const gitlabAna: AdminUserDetail = {
    ...anaDetail,
    gitlab: { status: 'connected', username: 'ana-gl', baseUrl: 'https://gitlab.example' },
    gitlabConnection: {
      configured: true,
      status: 'connected',
      username: 'ana-gl',
      baseUrl: 'https://gitlab.example',
      tokenExpiresAt: '2027-03-01',
      lastCheckedAt: null,
      errorCode: null,
      credentialVersion: 1,
    },
  }
  const fetch = api({
    'GET /api/admin/auth/session': () => Response.json(session),
    'GET /api/admin/users': () =>
      Response.json({ items: [rootUser, gitlabAna], page: 1, nextPage: null, total: 2 }),
    'GET /api/admin/users/2': () => Response.json(gitlabAna),
    'POST /api/admin/users/2/gitlab/token/reveal': () =>
      Response.json({ token: 'glpat-saved-token', baseUrl: 'https://gitlab.example' }),
    'PUT /api/admin/users/2/gitlab': () =>
      Response.json({
        ...gitlabAna,
        gitlabConnection: {
          ...gitlabAna.gitlabConnection,
          baseUrl: 'https://code.example.org',
          credentialVersion: 2,
        },
      }),
    'GET /api/admin/shares': () =>
      Response.json({
        items: [
          {
            ...link,
            repository: {
              provider: 'gitlab',
              instance: 'https://gitlab.example',
              owner: 'group/sub',
              name: 'alpha',
              active: true,
            },
          },
        ],
        page: 1,
        nextPage: null,
        total: 1,
      }),
  })
  renderApp('/admin/users')
  const row = (await screen.findByText('Ana Anić')).closest('tr')!
  expect(row).toHaveTextContent('GitHub: Connected · ana-gh')
  expect(row).toHaveTextContent('GitLab: Connected · ana-gl')
  await user.selectOptions(screen.getByLabelText('GitLab'), 'connected')
  await waitFor(() =>
    expect(fetch.mock.calls.some(([input]) => String(input).includes('gitlab=connected'))).toBe(
      true,
    ),
  )
  await user.click(
    within(screen.getByText('Ana Anić').closest('tr')!).getByRole('button', {
      name: 'Edit Ana Anić',
    }),
  )
  const dialog = await screen.findByRole('dialog', { name: 'Edit user' })
  const address = within(dialog).getByLabelText('GitLab URL')
  const token = within(dialog).getByLabelText('GitLab token')
  expect(address).toHaveValue('https://gitlab.example')
  expect(token).toHaveValue('')
  expect(dialog).toHaveTextContent('Connected as ana-gl')
  await user.click(within(dialog).getByRole('button', { name: 'Show GitLab token' }))
  await waitFor(() => expect(token).toHaveValue('glpat-saved-token'))
  // A new address with the revealed token replaces the connection in one verified step.
  await user.clear(address)
  await user.type(address, 'https://code.example.org')
  await user.click(within(dialog).getByRole('button', { name: 'Save' }))
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(calls(fetch, 'PUT', '/api/admin/users/2/gitlab')[0]?.body).toEqual({
    baseUrl: 'https://code.example.org',
    token: 'glpat-saved-token',
    expectedVersion: 1,
  })
  expect(calls(fetch, 'PATCH', '/api/admin/users/2')).toEqual([])
  await user.click(screen.getByRole('link', { name: 'Access links' }))
  const linkRow = (await screen.findByText('Client review')).closest('tr')!
  expect(linkRow).toHaveTextContent('GitLab group/sub/alpha')
  expect(linkRow).toHaveTextContent('https://gitlab.example')
})

it('asks for the GitLab token when only the GitLab address changes', async () => {
  const user = userEvent.setup()
  const fetch = api({
    'GET /api/admin/auth/session': () => Response.json(session),
    'GET /api/admin/users': () =>
      Response.json({ items: [rootUser, ana], page: 1, nextPage: null, total: 2 }),
    'GET /api/admin/users/2': () => Response.json(anaDetail),
  })
  renderApp('/admin/users')
  await user.click(
    within((await screen.findByText('Ana Anić')).closest('tr')!).getByRole('button', {
      name: 'Edit Ana Anić',
    }),
  )
  const dialog = await screen.findByRole('dialog', { name: 'Edit user' })
  await user.type(within(dialog).getByLabelText('GitLab URL'), 'https://gitlab.com')
  await user.click(within(dialog).getByRole('button', { name: 'Save' }))
  expect(within(dialog).getByRole('alert')).toHaveTextContent(
    'Enter the GitLab token for this GitLab URL.',
  )
  expect(calls(fetch, 'PUT', '/api/admin/users/2/gitlab')).toEqual([])
})

it('keeps the user filters and the page in the address, so Back returns to them', async () => {
  const user = userEvent.setup()
  const fetch = api({
    'GET /api/admin/auth/session': () => Response.json(session),
    'GET /api/admin/users': (_, url) =>
      Response.json({
        items: [rootUser, ana],
        page: Number(url.searchParams.get('page')),
        nextPage: 3,
        total: 60,
      }),
    'GET /api/admin/shares': () => Response.json({ items: [], page: 1, nextPage: null, total: 0 }),
  })
  const location = () => screen.getByLabelText('Location')
  const search = () => screen.getByRole('textbox', { name: 'Search users' })
  renderApp('/admin/users')
  await screen.findByRole('button', { name: 'Access links of Ana Anić' })
  expect(document.title).toBe('Users · Admin · Glimpse')
  await user.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'disabled')
  await user.type(search(), 'ana{Enter}')
  await user.click(screen.getByRole('button', { name: 'Next' }))
  await waitFor(() =>
    expect(location()).toHaveTextContent('/admin/users?status=disabled&q=ana&page=2'),
  )

  await user.click(await screen.findByRole('button', { name: 'Access links of Ana Anić' }))
  await waitFor(() => expect(location()).toHaveTextContent('/admin/links?user=2'))
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  expect(await screen.findByRole('combobox', { name: 'Status' })).toHaveValue('disabled')
  expect(search()).toHaveValue('ana')
  expect(screen.getByText('Page 2')).toBeInTheDocument()
  const last = fetch.mock.calls.filter(([input]) => input.startsWith('/api/admin/users?')).at(-1)!
  expect(Object.fromEntries(new URL(last[0], 'http://x').searchParams)).toEqual({
    page: '2',
    q: 'ana',
    status: 'disabled',
  })

  // Back through the earlier filters: the search field follows the address.
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  await waitFor(() => expect(location()).toHaveTextContent(/^\/admin\/users\?status=disabled$/))
  expect(search()).toHaveValue('')
  expect(screen.getByText('Page 1')).toBeInTheDocument()
})

it('searches users and links again without a new step, so Back goes straight to the page before', async () => {
  const user = userEvent.setup()
  api({
    'GET /api/admin/auth/session': () => Response.json(session),
    'GET /api/admin/users': () =>
      Response.json({ items: [rootUser, ana], page: 1, nextPage: null, total: 2 }),
    'GET /api/admin/shares': () =>
      Response.json({ items: [link], page: 1, nextPage: null, total: 1 }),
  })
  const location = () => screen.getByLabelText('Location')
  renderApp('/admin/users')
  const users = await screen.findByRole('textbox', { name: 'Search users' })
  await user.type(users, 'ana{Enter}')
  await user.type(users, '{Enter}')
  await waitFor(() => expect(location()).toHaveTextContent(/^\/admin\/users\?q=ana$/))
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  await waitFor(() => expect(location()).toHaveTextContent(/^\/admin\/users$/))

  await user.click(screen.getByRole('link', { name: 'Access links' }))
  const links = await screen.findByRole('textbox', { name: 'Search links' })
  await user.type(links, 'client{Enter}')
  await user.type(links, '{Enter}')
  await waitFor(() => expect(location()).toHaveTextContent(/^\/admin\/links\?q=client$/))
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  await waitFor(() => expect(location()).toHaveTextContent(/^\/admin\/links$/))
})
