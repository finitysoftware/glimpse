import { render, screen, waitFor, within } from '../render'
import userEvent from '@testing-library/user-event'
import { Route, Routes, useNavigate } from 'react-router-dom'
import { expect, it, vi } from 'vitest'
import type { Session } from '../../src/features/auth/api'
import { WorkspaceSessionContext } from '../../src/features/auth/session'
import { WorkspacePanel } from '../../src/features/workspace/WorkspacePanel'
import { MemoryRouter } from '../router'

const session: Session = {
  csrfToken: 'csrf-fixture',
  user: { id: 7, username: 'owner' },
  github: { configured: false, status: 'not_configured', credentialVersion: 0 },
  gitlab: { configured: false, status: 'not_configured', credentialVersion: 0 },
  passwordChangeRequired: false,
}

/** Changes only the search of the address, like a filter or a tab. */
function SearchOnly() {
  const navigate = useNavigate()
  return <button onClick={() => void navigate({ search: 'required=0' })}>Change search</button>
}

it('names every page in the browser tab and moves focus to the heading of the page that opens', async () => {
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
  )
  // Connections keeps loading; the test is about the page around it.
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
        <SearchOnly />
        <Routes>
          <Route path="/app/*" element={<WorkspacePanel onSignedOut={vi.fn()} />} />
        </Routes>
      </WorkspaceSessionContext.Provider>
    </MemoryRouter>,
  )
  expect(document.title).toBe('Connections · Glimpse')
  // The first page leaves focus where the browser put it.
  expect(document.body).toHaveFocus()

  const sidebar = screen.getByRole('navigation', { name: 'Workspace' })
  await user.click(within(sidebar).getByRole('link', { name: 'Account' }))
  await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus())
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Account')
  expect(document.title).toBe('Account · Glimpse')

  // New search parameters on the same page keep the focus where it is.
  const change = screen.getByRole('button', { name: 'Change search' })
  await user.click(change)
  expect(change).toHaveFocus()

  await user.click(within(sidebar).getByRole('link', { name: 'Repositories' }))
  await waitFor(() =>
    expect(screen.getByRole('heading', { level: 1, name: 'Repositories' })).toHaveFocus(),
  )
  expect(document.title).toBe('Repositories · Glimpse')
})
