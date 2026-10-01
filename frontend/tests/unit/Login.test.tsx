import { render, screen, waitFor } from '../render'
import userEvent from '@testing-library/user-event'
import { expect, it, vi } from 'vitest'
import { MemoryRouter } from '../router'
import { App } from '../../src/app/App'
import { Login } from '../../src/features/auth/Login'

const session = {
  csrfToken: 'csrf-fixture',
  user: { id: 7, username: 'owner' },
  github: { configured: true, status: 'connected' as const, credentialVersion: 1 },
}

it('signs in with username and password and clears the password after success', async () => {
  const user = userEvent.setup()
  const onSignedIn = vi.fn()
  const fetch = vi.fn().mockResolvedValue(Response.json(session))
  vi.stubGlobal('fetch', fetch)
  render(<Login initialError="" onSignedIn={onSignedIn} />)
  await user.type(screen.getByLabelText('Username'), 'Owner')
  await user.type(screen.getByLabelText('Password'), ' test password ')
  await user.click(screen.getByRole('button', { name: 'Sign in' }))
  expect(fetch).toHaveBeenCalledWith(
    '/api/auth/login',
    expect.objectContaining({
      body: JSON.stringify({ username: 'Owner', password: ' test password ' }),
    }),
  )
  expect(onSignedIn).toHaveBeenCalledWith(session)
  expect(screen.getByLabelText('Password')).toHaveValue('')
})

it('shows server errors and allows another attempt', async () => {
  const user = userEvent.setup()
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValue(
        Response.json(
          { error: { code: 'invalid_credentials', message: 'Invalid username or password.' } },
          { status: 401 },
        ),
      ),
  )
  const onSignedIn = vi.fn()
  render(<Login initialError="" onSignedIn={onSignedIn} />)
  await user.type(screen.getByLabelText('Username'), 'owner')
  await user.type(screen.getByLabelText('Password'), 'wrong')
  await user.click(screen.getByRole('button', { name: 'Sign in' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Invalid username or password.')
  expect(onSignedIn).not.toHaveBeenCalled()
  expect(screen.getByRole('button', { name: 'Sign in' })).toBeEnabled()
})

it('explains maintenance and share-only addresses instead of a generic connection error', async () => {
  for (const [code, message] of [
    ['maintenance', 'Glimpse is being updated. Try again in a few minutes.'],
    ['host_rejected', 'This address opens shared links only. Sign in on the main Glimpse address.'],
  ] as const) {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          Response.json(
            { error: { code, message } },
            { status: code === 'maintenance' ? 503 : 403 },
          ),
        ),
    )
    const { unmount } = render(
      <MemoryRouter initialEntries={['/login']}>
        <App />
      </MemoryRouter>,
    )
    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    unmount()
  }
})

it('puts the cursor in Username, then focus on the first page after signing in, and back after signing out', async () => {
  const signedIn = {
    ...session,
    github: { configured: false, status: 'not_configured' as const, credentialVersion: 0 },
    gitlab: { configured: false, status: 'not_configured' as const, credentialVersion: 0 },
    passwordChangeRequired: false,
  }
  vi.stubGlobal(
    'fetch',
    vi.fn((path: string) => {
      if (path === '/api/auth/session')
        return Promise.resolve(
          Response.json({ error: { code: 'unauthorized', message: 'Sign in.' } }, { status: 401 }),
        )
      if (path === '/api/auth/login') return Promise.resolve(Response.json(signedIn))
      return Promise.resolve(Response.json({ status: 'signed_out' }))
    }),
  )
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
  )
  const user = userEvent.setup()
  render(
    <MemoryRouter initialEntries={['/app']}>
      <App />
    </MemoryRouter>,
  )
  const username = await screen.findByRole('textbox', { name: 'Username' })
  expect(username).toHaveFocus()
  await user.type(username, 'owner')
  await user.type(screen.getByLabelText('Password'), 'workspace password')
  await user.click(screen.getByRole('button', { name: 'Sign in' }))

  // The first page of the workspace came through an in-app navigation, so its heading takes the focus.
  const heading = await screen.findByRole('heading', { level: 1, name: 'Repositories' })
  await waitFor(() => expect(heading).toHaveFocus())

  await user.click(screen.getByRole('button', { name: 'Sign out' }))
  await waitFor(() => expect(screen.getByRole('textbox', { name: 'Username' })).toHaveFocus())
})
