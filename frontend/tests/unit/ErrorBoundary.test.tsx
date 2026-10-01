import { render, screen, within } from '../render'
import { render as renderAlone } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, Route, RouterProvider, Routes } from 'react-router-dom'
import { beforeEach, expect, it, vi } from 'vitest'
import { ErrorBoundary, PageFailure } from '../../src/components/ErrorBoundary'
import type { Session } from '../../src/features/auth/api'
import { WorkspaceSessionContext } from '../../src/features/auth/session'
import { WorkspacePanel } from '../../src/features/workspace/WorkspacePanel'
import { MemoryRouter } from '../router'

// Connections renders data it did not expect, e.g. after a change of the API.
vi.mock('../../src/features/workspace/connections/Connections', () => ({
  Connections: () => {
    throw new TypeError("Cannot read properties of undefined (reading 'status')")
  },
}))

beforeEach(() => {
  // React reports every caught render error to the console; the tests check what the user sees instead.
  vi.spyOn(console, 'error').mockImplementation(() => {})
})

function Broken(): never {
  throw new Error('secret detail of a failure')
}

it('shows a message with Reload instead of a failed view, and the view again for another key', () => {
  const view = renderAlone(
    <ErrorBoundary resetKey="/app/connections">
      <Broken />
    </ErrorBoundary>,
  )
  const alert = screen.getByRole('alert', { name: 'Something went wrong' })
  expect(alert).toHaveTextContent('This page could not be shown. Reload to try again.')
  expect(within(alert).getByRole('button', { name: 'Reload' })).toBeEnabled()
  expect(document.body).not.toHaveTextContent('secret detail')

  view.rerender(
    <ErrorBoundary resetKey="/app/connections">
      <p>Recovered</p>
    </ErrorBoundary>,
  )
  expect(screen.getByRole('alert', { name: 'Something went wrong' })).toBeInTheDocument()
  view.rerender(
    <ErrorBoundary resetKey="/app/account">
      <p>Recovered</p>
    </ErrorBoundary>,
  )
  expect(screen.getByText('Recovered')).toBeInTheDocument()
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('replaces the developer error page of the router at the root', async () => {
  const router = createMemoryRouter([
    { path: '*', element: <Broken />, errorElement: <PageFailure /> },
  ])
  renderAlone(<RouterProvider router={router} />)
  expect(await screen.findByRole('alert', { name: 'Something went wrong' })).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Open Glimpse' })).toHaveAttribute('href', '/app')
  expect(document.body).not.toHaveTextContent('Unexpected Application Error')
})

it('keeps the workspace sidebar working when one page fails, and opens another page', async () => {
  const session: Session = {
    csrfToken: 'csrf-fixture',
    user: { id: 7, username: 'owner' },
    github: { configured: false, status: 'not_configured', credentialVersion: 0 },
    gitlab: { configured: false, status: 'not_configured', credentialVersion: 0 },
    passwordChangeRequired: false,
  }
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
  )
  vi.stubGlobal(
    'fetch',
    vi.fn(() => new Promise<Response>(() => {})),
  )
  const user = userEvent.setup()
  render(
    <MemoryRouter initialEntries={['/app/connections']}>
      <WorkspaceSessionContext.Provider
        value={{ session, expire: vi.fn(), updateConnection: vi.fn(), replace: vi.fn() }}
      >
        <Routes>
          <Route path="/app/*" element={<WorkspacePanel onSignedOut={vi.fn()} />} />
        </Routes>
      </WorkspaceSessionContext.Provider>
    </MemoryRouter>,
  )

  const main = screen.getByRole('main')
  expect(within(main).getByRole('alert', { name: 'Something went wrong' })).toBeInTheDocument()
  const sidebar = screen.getByRole('navigation', { name: 'Workspace' })
  await user.click(within(sidebar).getByRole('link', { name: 'Account' }))
  expect(
    within(main).queryByRole('alert', { name: 'Something went wrong' }),
  ).not.toBeInTheDocument()
  expect(within(main).getByRole('heading', { level: 1 })).not.toHaveTextContent(
    'Something went wrong',
  )
})
