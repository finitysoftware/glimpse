import { render, screen, waitFor } from '../render'
import { MemoryRouter } from '../router'
import { expect, it, vi } from 'vitest'
import { QueryScope } from '../../src/api/QueryScope'
import type { Session } from '../../src/features/auth/api'
import { Repositories } from '../../src/features/repositories/Repositories'
import { safeReturnTo, WorkspaceSessionContext } from '../../src/features/auth/session'

const session: Session = {
  csrfToken: 'csrf-fixture',
  user: { id: 7, username: 'owner' },
  github: { configured: true, status: 'connected', credentialVersion: 1 },
  gitlab: { configured: false, status: 'not_configured', credentialVersion: 0 },
  passwordChangeRequired: false,
}

function mount(response: Response) {
  const expire = vi.fn()
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(response)),
  )
  render(
    <MemoryRouter>
      <QueryScope onUnauthorized={expire}>
        <WorkspaceSessionContext.Provider
          value={{ session, expire, updateConnection: vi.fn(), replace: vi.fn() }}
        >
          <Repositories />
        </WorkspaceSessionContext.Provider>
      </QueryScope>
    </MemoryRouter>,
  )
  return expire
}

const failure = (
  status: number,
  code: string,
  message: string,
  headers: Record<string, string> = {},
) => Response.json({ error: { code, message } }, { status, headers })

it('offers GitHub onboarding when no token is configured, without a usable filter or refresh', async () => {
  mount(failure(409, 'github_not_connected', 'Connect GitHub to see your repositories.'))
  expect(
    await screen.findByRole('heading', { name: 'Connect GitHub to see your repositories.' }),
  ).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Configure GitHub' })).toHaveAttribute(
    'href',
    '/app/connections#github-connection',
  )
  expect(screen.queryByLabelText('Filter this page')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Refresh repositories/ })).not.toBeInTheDocument()
})

it('distinguishes rejected and restricted tokens from temporary failures', async () => {
  mount(failure(502, 'github_credentials_rejected', 'GitHub credentials were rejected.'))
  expect(
    await screen.findByRole('heading', { name: 'GitHub rejected the saved token.' }),
  ).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Configure GitHub' })).toBeInTheDocument()
})

it('explains restricted access without calling the token wrong', async () => {
  mount(failure(502, 'github_access_denied', 'GitHub denied repository access.'))
  expect(
    await screen.findByRole('heading', { name: 'GitHub restricted access for this token.' }),
  ).toBeInTheDocument()
})

it('shows a valid empty catalogue separately from errors', async () => {
  mount(Response.json({ items: [], nextPage: null }))
  expect(
    await screen.findByRole('heading', { name: 'No repositories available for this token.' }),
  ).toBeInTheDocument()
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('waits for Retry-After on quota limits and offers retry for temporary failures', async () => {
  mount(
    failure(429, 'github_rate_limited', 'GitHub request quota is temporarily unavailable.', {
      'Retry-After': '30',
    }),
  )
  expect(await screen.findByText(/Try again in 30 seconds\./)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: /Try again/ })).toBeDisabled()
})

it('offers retry and Connections for network failures', async () => {
  mount(failure(502, 'github_timeout', 'GitHub did not respond in time.'))
  expect(await screen.findByRole('alert')).toHaveTextContent('GitHub did not respond in time.')
  expect(screen.getByRole('button', { name: 'Try again' })).toBeEnabled()
  expect(screen.getByRole('link', { name: 'Check Connections' })).toHaveAttribute(
    'href',
    '/app/connections',
  )
})

it('treats a Glimpse 401 as an ended session, not a GitHub problem', async () => {
  const expire = mount(failure(401, 'unauthorized', 'Sign in to continue.'))
  await waitFor(() => expect(expire).toHaveBeenCalled())
  expect(screen.queryByRole('link', { name: 'Configure GitHub' })).not.toBeInTheDocument()
})

it('accepts only local workspace paths after sign-in', () => {
  expect(safeReturnTo('/app/connections#github-connection')).toBe(
    '/app/connections#github-connection',
  )
  expect(safeReturnTo('/app/repositories/o/r?tab=1')).toBe('/app/repositories/o/r?tab=1')
  for (const value of [
    null,
    '',
    '/login',
    '//evil.example/app',
    'https://evil.example/app',
    '/application',
    '/app\\..\\admin',
    'javascript:alert(1)',
  ]) {
    expect(safeReturnTo(value)).toBe('/app')
  }
})
