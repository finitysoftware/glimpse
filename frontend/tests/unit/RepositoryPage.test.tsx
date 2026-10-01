import { render, screen, waitFor, within } from '../render'
import userEvent from '@testing-library/user-event'
import { Route, Routes, useNavigate } from 'react-router-dom'
import { expect, it, vi } from 'vitest'
import type { Session } from '../../src/features/auth/api'
import { WorkspaceSessionContext } from '../../src/features/auth/session'
import { RepositoryDetail } from '../../src/features/repositories/RepositoryPage'
import type { VisibilityProfile } from '../../src/features/repositories/visibility/draft'
import { WorkspacePanel } from '../../src/features/workspace/WorkspacePanel'
import { MemoryRouter } from '../router'

const base = '/api/workspace/repositories/fixture/alpha'
const sha = 'a'.repeat(40)
const repository = { id: 101, fullName: 'fixture/alpha', private: true, defaultBranch: 'main' }
const profile: VisibilityProfile = {
  branch: 'main',
  headSha: sha,
  mode: 'selected',
  version: 3,
  sourceSha: sha,
  treeChanged: false,
  selected: ['README.md'],
  missing: [],
  manifest: ['README.md', 'src/app.ts'],
  fileCount: 2,
  truncated: false,
  limits: { paths: 5000, bytes: 1048576, operations: 100 },
}

/** One GitHub repository with saved rules for main and no links yet; saving rules is recorded. */
function server() {
  const saves: unknown[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn((path: string, init: RequestInit = {}) => {
      const json = (data: unknown) => Promise.resolve(Response.json(data))
      const url = new URL(path, 'http://localhost')
      const query = url.searchParams
      if (url.pathname === `${base}/browse` && query.get('kind') === 'branches')
        return json({
          items: ['main', 'develop'],
          nextPage: null,
          defaultBranch: 'main',
          allowHistory: true,
        })
      if (url.pathname === `${base}/browse` && query.get('kind') === 'file')
        return json({
          repository,
          commitSha: sha,
          path: 'README.md',
          kind: 'file',
          branch: 'main',
          allowHistory: true,
          file: { path: 'README.md', commitSha: sha, bytes: 6, content: 'readme' },
        })
      if (url.pathname === `${base}/browse`)
        return json({
          repository,
          commitSha: sha,
          path: query.get('path') ?? '',
          kind: 'directory',
          branch: query.get('branch') || 'main',
          allowHistory: true,
          entries: query.get('path')
            ? [{ name: 'app.ts', path: 'src/app.ts', type: 'file', size: 12 }]
            : [
                { name: 'src', path: 'src', type: 'dir', size: 0 },
                { name: 'README.md', path: 'README.md', type: 'file', size: 6 },
              ],
        })
      if (url.pathname === `${base}/visibility` && init.method === 'PUT') {
        saves.push(JSON.parse(String(init.body)))
        return json({ ...profile, selected: [], version: 4 })
      }
      if (url.pathname === `${base}/visibility` && query.get('summary') === '1')
        return json({
          branch: 'main',
          mode: 'selected',
          version: 3,
          selectedCount: 1,
          preview: ['README.md'],
        })
      if (url.pathname === `${base}/visibility`) return json(profile)
      if (url.pathname === `${base}/shares`)
        return json({ items: [], active: true, nextPage: null, storageConfigured: true })
      return Promise.resolve(
        Response.json({ error: { code: 'unexpected', message: path } }, { status: 500 }),
      )
    }),
  )
  return saves
}

function Back() {
  const navigate = useNavigate()
  return (
    <>
      <button onClick={() => void navigate(-1)}>Browser back</button>
      <button onClick={() => void navigate(1)}>Browser forward</button>
    </>
  )
}

function renderPage() {
  render(
    <MemoryRouter initialEntries={['/app', '/app/repositories/fixture/alpha?visibility=1']}>
      <Back />
      <Routes>
        <Route path="/app" element={<h1>All repositories page</h1>} />
        <Route
          path="/app/repositories/:owner/:repository"
          element={<RepositoryDetail csrfToken="csrf-fixture" />}
        />
      </Routes>
    </MemoryRouter>,
  )
}

const readme = () => screen.findByRole('checkbox', { name: 'Visible to visitors: file README.md' })
const question = () => screen.findByRole('alertdialog', { name: 'Unsaved visibility changes' })

/** Deselects README.md, so the rules of main are unsaved. */
async function change(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await readme())
  expect(await readme()).not.toBeChecked()
}

it('opens Access links without a question until rules change, then waits for Stay, Discard or Save', async () => {
  const saves = server()
  const user = userEvent.setup()
  renderPage()
  await readme()
  expect(document.title).toBe('fixture/alpha · Glimpse')
  await user.click(screen.getByRole('button', { name: 'Access links' }))
  expect(await screen.findByRole('heading', { name: 'Repository sharing' })).toBeInTheDocument()
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(document.title).toBe('Access links · fixture/alpha · Glimpse')
  await user.click(screen.getByRole('button', { name: 'Files' }))

  await change(user)
  await user.click(screen.getByRole('button', { name: 'Access links' }))
  expect(await question()).toHaveAccessibleDescription(
    'Save the changes for main before opening Access links?',
  )
  // Stay keeps the draft on the Files tab.
  await user.click(within(await question()).getByRole('button', { name: 'Stay' }))
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(await readme()).not.toBeChecked()
  expect(screen.queryByRole('heading', { name: 'Repository sharing' })).not.toBeInTheDocument()

  // Discard leaves without saving; Files then starts from the saved rules.
  await user.click(screen.getByRole('button', { name: 'Access links' }))
  await user.click(within(await question()).getByRole('button', { name: 'Discard' }))
  expect(await screen.findByRole('heading', { name: 'Repository sharing' })).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Files' }))
  expect(await readme()).toBeChecked()

  // Save stores the rules first, then opens the tab.
  await change(user)
  await user.click(screen.getByRole('button', { name: 'Access links' }))
  await user.click(within(await question()).getByRole('button', { name: 'Save' }))
  expect(await screen.findByRole('heading', { name: 'Repository sharing' })).toBeInTheDocument()
  expect(saves).toEqual([
    expect.objectContaining({
      branch: 'main',
      expectedVersion: 3,
      operations: [{ action: 'deselect', path: 'README.md' }],
    }),
  ])
})

it('reads the saved rules again when Forward returns to Access links', async () => {
  server()
  const answer = fetch
  // Once the owner is back on Files, main gets new saved rules; their answer waits until released.
  let saved: Promise<void> | null = null
  let release = () => {}
  vi.stubGlobal(
    'fetch',
    vi.fn(async (path: string, init?: RequestInit) => {
      if (saved === null || !path.includes('summary=1')) return answer(path, init)
      await saved
      return Response.json({
        branch: 'main',
        mode: 'selected',
        version: 4,
        selectedCount: 2,
        preview: ['README.md', 'src/app.ts'],
      })
    }),
  )
  const user = userEvent.setup()
  renderPage()
  await readme()
  await user.click(screen.getByRole('button', { name: 'Access links' }))
  expect(await screen.findByText('1 selected file')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  await readme()

  saved = new Promise(resolve => (release = resolve))
  await user.click(screen.getByRole('button', { name: 'Browser forward' }))
  expect(await screen.findByRole('heading', { name: 'Repository sharing' })).toBeInTheDocument()
  expect(screen.queryByText('1 selected file')).not.toBeInTheDocument()
  release()
  expect(await screen.findByText('2 selected files')).toBeInTheDocument()
})

it('keeps the draft and the tree while the owner moves through folders and back, without a question', async () => {
  server()
  const user = userEvent.setup()
  renderPage()
  await change(user)
  const path = () => screen.getByRole('navigation', { name: 'File path' })
  await user.click(screen.getByRole('button', { name: /^src.*Folder/ }))
  await waitFor(() => expect(path()).toHaveTextContent('src'))
  expect(document.title).toBe('src · fixture/alpha · Glimpse')
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(await readme()).not.toBeChecked()

  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  await waitFor(() => expect(path()).not.toHaveTextContent('src'))
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(await readme()).not.toBeChecked()
  expect(screen.getByRole('region', { name: 'Unsaved changes' })).toBeInTheDocument()
})

it('asks before another branch opens and stays on the branch with its draft', async () => {
  server()
  const user = userEvent.setup()
  renderPage()
  await change(user)
  await user.selectOptions(screen.getByRole('combobox', { name: 'Branch' }), 'develop')
  expect(await question()).toHaveAccessibleDescription(
    'Save the changes for main before opening develop?',
  )
  await user.click(within(await question()).getByRole('button', { name: 'Stay' }))
  expect(screen.getByRole('combobox', { name: 'Branch' })).toHaveValue('main')
  expect(await readme()).not.toBeChecked()
})

it('asks before File history of an open file', async () => {
  server()
  const user = userEvent.setup()
  renderPage()
  await change(user)
  // Opening a file keeps the preview and its draft; only leaving the preview asks.
  const tree = within(screen.getByRole('navigation', { name: 'Project files' }))
  await user.click(tree.getByRole('button', { name: 'README.md' }))
  await user.click(await screen.findByRole('button', { name: 'File history' }))
  expect(await question()).toHaveAccessibleDescription(
    'Save the changes for main before opening History?',
  )
  await user.click(within(await question()).getByRole('button', { name: 'Stay' }))
  expect(await readme()).not.toBeChecked()
})

it('asks before a link of the page, and before Back, leaves the repository', async () => {
  server()
  const user = userEvent.setup()
  renderPage()
  await change(user)
  await user.click(screen.getByRole('link', { name: 'All repositories' }))
  expect(await question()).toHaveAccessibleDescription(
    'Save the changes for main before leaving this page?',
  )
  await user.click(within(await question()).getByRole('button', { name: 'Stay' }))
  expect(await readme()).not.toBeChecked()

  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  expect(await question()).toHaveAccessibleDescription(
    'Save the changes for main before leaving this page?',
  )
  await user.click(within(await question()).getByRole('button', { name: 'Discard' }))
  expect(await screen.findByRole('heading', { name: 'All repositories page' })).toBeInTheDocument()
})

it('puts the phone drawer away when a file opens, and keeps editing with the draft', async () => {
  server()
  // Below 761 px the tree is a drawer over the page.
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: query === '(max-width: 760px)',
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })),
  )
  const user = userEvent.setup()
  renderPage()
  await change(user)
  const drawer = screen.getByRole('dialog', { name: 'Visible to visitors' })
  await user.click(within(drawer).getByRole('button', { name: 'README.md' }))
  expect(await screen.findByRole('button', { name: 'File history' })).toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(screen.getByRole('region', { name: 'File visibility' })).toBeInTheDocument()
  expect(screen.getByRole('region', { name: 'Unsaved changes' })).toBeInTheDocument()

  // Tree brings the checkboxes back, with the draft.
  await user.click(screen.getByRole('button', { name: 'Tree' }))
  expect(await readme()).not.toBeChecked()
  // The drawer hides the page from assistive technology again, so the button is found among hidden elements.
  expect(screen.getByRole('button', { name: 'Tree', hidden: true })).toBeDisabled()
})

/** The repository in the workspace with its sidebar; sign-outs are recorded. */
function renderWorkspace() {
  const saves = server()
  const answer = fetch
  const signOuts: string[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn((path: string, init?: RequestInit) => {
      if (path !== '/api/auth/logout') return answer(path, init)
      signOuts.push(new Headers(init?.headers).get('X-CSRF-Token') ?? '')
      return Promise.resolve(Response.json({}))
    }),
  )
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
  )
  const session: Session = {
    csrfToken: 'csrf-fixture',
    user: { id: 7, username: 'owner' },
    github: { configured: true, status: 'connected', credentialVersion: 1 },
    gitlab: { configured: false, status: 'not_configured', credentialVersion: 0 },
    passwordChangeRequired: false,
  }
  const signedOut = vi.fn()
  render(
    <MemoryRouter initialEntries={['/app/repositories/fixture/alpha?visibility=1']}>
      <WorkspaceSessionContext.Provider
        value={{ session, expire: vi.fn(), updateConnection: vi.fn(), replace: vi.fn() }}
      >
        <Routes>
          <Route path="/app/*" element={<WorkspacePanel onSignedOut={signedOut} />} />
        </Routes>
      </WorkspaceSessionContext.Provider>
    </MemoryRouter>,
  )
  return { saves, signOuts, signedOut }
}

it('asks before Sign out over unsaved rules, and signs out after Save', async () => {
  const { saves, signOuts, signedOut } = renderWorkspace()
  const user = userEvent.setup()
  await change(user)
  await user.click(screen.getByRole('button', { name: 'Sign out' }))
  expect(await question()).toHaveAccessibleDescription(
    'Save the changes for main before signing out?',
  )
  // Stay keeps the session and the draft.
  await user.click(within(await question()).getByRole('button', { name: 'Stay' }))
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(await readme()).not.toBeChecked()
  expect(signOuts).toEqual([])

  await user.click(screen.getByRole('button', { name: 'Sign out' }))
  await user.click(within(await question()).getByRole('button', { name: 'Save' }))
  await waitFor(() => expect(signedOut).toHaveBeenCalledOnce())
  expect(saves).toHaveLength(1)
  expect(signOuts).toEqual(['csrf-fixture'])
})

it('signs out without a question when nothing is unsaved', async () => {
  const { signOuts, signedOut } = renderWorkspace()
  const user = userEvent.setup()
  await readme()
  await user.click(screen.getByRole('button', { name: 'Sign out' }))
  await waitFor(() => expect(signedOut).toHaveBeenCalledOnce())
  expect(signOuts).toEqual(['csrf-fixture'])
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
})

it('lets every way out go without a question when nothing is unsaved', async () => {
  server()
  const user = userEvent.setup()
  renderPage()
  await readme()
  await user.click(screen.getByRole('button', { name: 'Browser back' }))
  expect(await screen.findByRole('heading', { name: 'All repositories page' })).toBeInTheDocument()
  await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
})
