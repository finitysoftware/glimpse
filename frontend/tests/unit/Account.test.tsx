import { render, screen } from '../render'
import userEvent from '@testing-library/user-event'
import { Route, Routes, useLocation } from 'react-router-dom'
import { MemoryRouter } from '../router'
import { expect, it, vi } from 'vitest'
import type { Session } from '../../src/features/auth/api'
import { WorkspacePanel } from '../../src/features/workspace/WorkspacePanel'
import { WorkspaceSessionContext } from '../../src/features/auth/session'

const base: Session = {
  csrfToken: 'csrf-fixture',
  user: { id: 7, username: 'owner' },
  github: { configured: false, status: 'not_configured', credentialVersion: 0 },
  gitlab: { configured: false, status: 'not_configured', credentialVersion: 0 },
  passwordChangeRequired: false,
}

function Location() {
  const location = useLocation()
  return <output aria-label="Location">{location.pathname + location.search}</output>
}

function renderPanel(session: Session, replace = vi.fn(), path = '/app') {
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
  )
  render(
    <MemoryRouter initialEntries={[path]}>
      <WorkspaceSessionContext.Provider
        value={{ session, expire: vi.fn(), updateConnection: vi.fn(), replace }}
      >
        <Routes>
          <Route
            path="/app/*"
            element={
              <>
                <WorkspacePanel onSignedOut={vi.fn()} />
                <Location />
              </>
            }
          />
        </Routes>
      </WorkspaceSessionContext.Provider>
    </MemoryRouter>,
  )
  return replace
}

it('sends a required password change first and continues to the workspace with the renewed session', async () => {
  const user = userEvent.setup()
  const renewed = { ...base, csrfToken: 'renewed-csrf', passwordChangeRequired: false }
  const fetch = vi.fn().mockResolvedValue(Response.json(renewed))
  vi.stubGlobal('fetch', fetch)
  const replace = renderPanel({ ...base, passwordChangeRequired: true })
  expect(
    await screen.findByText(
      'An administrator asked you to set a new password before you continue.',
    ),
  ).toBeInTheDocument()
  expect(screen.getByLabelText('Location')).toHaveTextContent('/app/account?required=1')
  await user.type(screen.getByLabelText('Current password'), 'old workspace password')
  await user.type(screen.getByLabelText('New password'), 'a brand new workspace password')
  await user.type(screen.getByLabelText('Confirm new password'), 'a brand new workspace password')
  await user.click(screen.getByRole('button', { name: /Change password/ }))
  expect(fetch).toHaveBeenCalledWith(
    '/api/auth/password',
    expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({
        currentPassword: 'old workspace password',
        newPassword: 'a brand new workspace password',
      }),
    }),
  )
  expect(new Headers((fetch.mock.calls[0]?.[1] as RequestInit).headers).get('X-CSRF-Token')).toBe(
    'csrf-fixture',
  )
  expect(replace).toHaveBeenCalledWith(renewed)
  expect(screen.getByLabelText('Location')).toHaveTextContent(/^\/app$/)
})

it('checks length and confirmation before sending and keeps the page for a voluntary change', async () => {
  const user = userEvent.setup()
  const fetch = vi.fn().mockResolvedValue(
    Response.json(
      {
        error: {
          code: 'current_password_invalid',
          message: 'The current password is incorrect.',
        },
      },
      { status: 422 },
    ),
  )
  vi.stubGlobal('fetch', fetch)
  renderPanel(base, vi.fn(), '/app/account')
  expect(screen.getByRole('link', { name: 'Account' })).toHaveAttribute('aria-current', 'page')
  await user.type(screen.getByLabelText('Current password'), 'old workspace password')
  await user.type(screen.getByLabelText('New password'), 'short')
  await user.type(screen.getByLabelText('Confirm new password'), 'short')
  await user.click(screen.getByRole('button', { name: /Change password/ }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Use 8-128 characters.')
  await user.clear(screen.getByLabelText('New password'))
  await user.type(screen.getByLabelText('New password'), 'a brand new workspace password')
  await user.click(screen.getByRole('button', { name: /Change password/ }))
  expect(await screen.findByRole('alert')).toHaveTextContent('The new passwords do not match.')
  expect(fetch).not.toHaveBeenCalled()
  await user.clear(screen.getByLabelText('Confirm new password'))
  await user.type(screen.getByLabelText('Confirm new password'), 'a brand new workspace password')
  await user.click(screen.getByRole('button', { name: /Change password/ }))
  expect(await screen.findByRole('alert')).toHaveTextContent('The current password is incorrect.')
  expect(screen.getByLabelText('Location')).toHaveTextContent('/app/account')
})
