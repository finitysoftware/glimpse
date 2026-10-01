import { render, screen, waitFor, within } from '../render'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { expect, it, vi } from 'vitest'
import { AccessLinks } from '../../src/features/repositories/links/AccessLinks'
import type { LinkDraft } from '../../src/features/repositories/links/CreateLinkForm'
import type { ShareLink } from '../../src/features/repositories/links/api'

const base = '/api/workspace/repositories/owner/app' as const
const LINK = 'c'.repeat(32)
const existing: ShareLink = {
  publicId: LINK,
  name: 'Client review',
  createdAt: '2026-09-20T10:00:00Z',
  expiresAt: null,
  status: 'active',
  branches: ['main'],
  allowHistory: false,
  policyVersion: 4,
  policies: [{ branch: 'main', mode: 'selected', selectedCount: 2, profileVersion: 3 }],
}

type Call = { path: string; method: string; body: unknown; csrf: string | undefined }

/** The owner's link API in memory: main has saved selected files, develop has no saved rules yet. */
function server(items = [existing]) {
  const state = { active: true, items }
  const calls: Call[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn((path: string, init: RequestInit = {}) => {
      const headers = (init.headers ?? {}) as Record<string, string>
      const call = {
        path,
        method: init.method ?? 'GET',
        body: init.body ? JSON.parse(String(init.body)) : undefined,
        csrf: headers['X-CSRF-Token'],
      }
      calls.push(call)
      const json = (data: unknown) => Promise.resolve(Response.json(data))
      if (call.method === 'GET' && path.startsWith(`${base}/shares?page=`))
        return json({
          items: state.items,
          active: state.active,
          nextPage: null,
          storageConfigured: true,
        })
      if (
        call.method === 'GET' &&
        path.startsWith(`${base}/browse?`) &&
        path.includes('kind=branches')
      )
        return json({
          items: ['main', 'develop'],
          nextPage: null,
          defaultBranch: 'main',
          allowHistory: true,
        })
      if (call.method === 'GET' && path.startsWith(`${base}/visibility?branch=main&`))
        return json({
          branch: 'main',
          mode: 'selected',
          version: 3,
          selectedCount: 2,
          preview: ['README.md', 'src/app.ts'],
        })
      if (call.method === 'GET' && path.startsWith(`${base}/visibility?branch=develop&`))
        return json({ branch: 'develop', mode: null, version: 0, selectedCount: 0, preview: [] })
      if (call.method === 'POST' && path === `${base}/shares`)
        return json({ url: 'https://glimpse.example/s/new#secret', publicId: 'd'.repeat(32) })
      if (call.method === 'POST' && path === `${base}/availability`) {
        state.active = (call.body as { active: boolean }).active
        return json({ active: state.active })
      }
      if (call.method === 'POST' && path === `/api/workspace/shares/${LINK}/revoke`) {
        state.items = state.items.map(link => ({ ...link, status: 'revoked' as const }))
        return json({ status: 'revoked' })
      }
      if (call.method === 'POST' && path === `/api/workspace/shares/${LINK}/delete`) {
        state.items = []
        return json({ status: 'deleted' })
      }
      if (call.method === 'POST' && path === `/api/workspace/shares/${LINK}/permissions`)
        return json({ status: 'updated' })
      return Promise.resolve(
        Response.json(
          { error: { code: 'unexpected', message: `${call.method} ${path}` } },
          { status: 500 },
        ),
      )
    }),
  )
  return calls
}

function renderLinks(branches: string[] | null = null) {
  const onConfigure = vi.fn()
  function Links() {
    const [draft, setDraft] = useState<LinkDraft>({
      name: '',
      days: '7',
      permissions: { branches, allowHistory: false },
      choices: {},
    })
    return (
      <AccessLinks
        base={base}
        platform="github"
        csrfToken="owner-csrf"
        draft={draft}
        onDraftChange={setDraft}
        onConfigure={onConfigure}
      />
    )
  }
  render(<Links />)
  return { onConfigure }
}

const posts = (calls: Call[]) => calls.filter(call => call.method === 'POST')
const shareLoads = (calls: Call[]) =>
  calls.filter(call => call.path.startsWith(`${base}/shares?page=`)).length

it('creates a link from the saved rules of the default branch and shows its URL once', async () => {
  const calls = server()
  const user = userEvent.setup()
  renderLinks()

  await user.type(await screen.findByRole('textbox', { name: 'Link name' }), 'Release notes')
  const create = screen.getByRole('button', { name: 'Create link' })
  await waitFor(() => expect(create).toBeEnabled())
  await user.click(create)

  expect(posts(calls)).toEqual([
    {
      path: `${base}/shares`,
      method: 'POST',
      csrf: 'owner-csrf',
      body: {
        name: 'Release notes',
        expiresInDays: 7,
        branches: ['main'],
        allowHistory: false,
        visibility: [{ branch: 'main', profileVersion: 3 }],
      },
    },
  ])
  const ready = await screen.findByRole('heading', { name: 'Your access link is ready' })
  expect(
    within(ready.closest('section')!).getByRole('textbox', { name: 'Access URL' }),
  ).toHaveValue('https://glimpse.example/s/new#secret')
  expect(screen.getByRole('textbox', { name: 'Link name' })).toHaveValue('')
  await waitFor(() => expect(shareLoads(calls)).toBe(2))

  await user.click(screen.getByRole('button', { name: 'Dismiss link' }))
  expect(
    screen.queryByRole('heading', { name: 'Your access link is ready' }),
  ).not.toBeInTheDocument()
})

it('never creates a link while a branch has no explicit file rules', async () => {
  const calls = server()
  const user = userEvent.setup()
  const { onConfigure } = renderLinks(['main', 'develop'])

  await user.type(await screen.findByRole('textbox', { name: 'Link name' }), 'Two branches')
  const develop = within(
    await screen.findByRole('radiogroup', { name: 'File visibility for develop' }),
  )
  expect(develop.getByRole('status')).toHaveTextContent(
    'Choose the rules for develop before saving.',
  )
  const create = screen.getByRole('button', { name: 'Create link' })
  expect(create).toBeDisabled()

  await user.click(develop.getByRole('button', { name: 'Choose files' }))
  expect(onConfigure).toHaveBeenCalledWith('develop')
  await user.click(develop.getByRole('radio', { name: 'Entire branch' }))
  await waitFor(() => expect(create).toBeEnabled())
  await user.click(create)

  expect(posts(calls)[0]?.body).toMatchObject({
    branches: ['main', 'develop'],
    visibility: [
      { branch: 'main', profileVersion: 3 },
      { branch: 'develop', allFiles: true },
    ],
  })
})

it('edits a link with the rules version it was loaded with, then switches sharing off and revokes it', async () => {
  const calls = server()
  const user = userEvent.setup()
  renderLinks()

  await user.click(await screen.findByRole('button', { name: 'Edit access' }))
  const form = await screen.findByRole('form', { name: 'Edit access for Client review' })
  const save = within(form).getByRole('button', { name: 'Save access' })
  await waitFor(() => expect(save).toBeEnabled())
  await user.click(save)
  // The link keeps its current rules for main, and a change made elsewhere since loading is refused by the version.
  expect(posts(calls)).toEqual([
    {
      path: `/api/workspace/shares/${LINK}/permissions`,
      method: 'POST',
      csrf: 'owner-csrf',
      body: {
        branches: ['main'],
        allowHistory: false,
        visibility: [{ branch: 'main', keep: true }],
        expectedPolicyVersion: 4,
      },
    },
  ])
  await waitFor(() =>
    expect(
      screen.queryByRole('form', { name: 'Edit access for Client review' }),
    ).not.toBeInTheDocument(),
  )

  await user.click(screen.getByRole('button', { name: 'Disable sharing' }))
  expect(await screen.findByText('All visitor links are currently disabled.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Enable sharing' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'Create link' })).toBeDisabled()

  // Revoking cannot be undone, so it waits for a confirmation; Cancel keeps the link.
  await user.click(screen.getByRole('button', { name: 'Revoke' }))
  let revoke = await screen.findByRole('alertdialog', { name: 'Revoke link' })
  expect(revoke).toHaveAccessibleDescription(
    'Revoke Client review? Visitors lose access immediately and the URL can no longer be shown.',
  )
  await user.click(within(revoke).getByRole('button', { name: 'Cancel' }))
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(posts(calls).some(call => call.path.endsWith('/revoke'))).toBe(false)
  await user.click(screen.getByRole('button', { name: 'Revoke' }))
  revoke = await screen.findByRole('alertdialog', { name: 'Revoke link' })
  await user.click(within(revoke).getByRole('button', { name: 'Revoke' }))
  expect(await screen.findByRole('button', { name: 'Delete' })).toBeEnabled()
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  // The row's buttons were replaced, so focus returns to the list.
  await waitFor(() => expect(screen.getByRole('heading', { name: 'Access links' })).toHaveFocus())
  expect(screen.queryByRole('button', { name: 'Edit access' })).not.toBeInTheDocument()
  expect(posts(calls).map(call => [call.path, call.body])).toEqual([
    [`/api/workspace/shares/${LINK}/permissions`, expect.anything()],
    [`${base}/availability`, { active: false }],
    [`/api/workspace/shares/${LINK}/revoke`, {}],
  ])
  expect(posts(calls).every(call => call.csrf === 'owner-csrf')).toBe(true)
})

it('deletes a revoked link after a confirmation and keeps the question open when deleting fails', async () => {
  const calls = server([{ ...existing, status: 'revoked' }])
  const answer = fetch
  let failures = 1
  vi.stubGlobal(
    'fetch',
    vi.fn((path: string, init?: RequestInit) => {
      if (!path.endsWith('/delete') || failures-- === 0) return answer(path, init)
      return Promise.resolve(
        Response.json(
          { error: { code: 'unavailable', message: 'Unable to delete this link. Try again.' } },
          { status: 503 },
        ),
      )
    }),
  )
  const user = userEvent.setup()
  renderLinks()
  await user.click(await screen.findByRole('button', { name: 'Delete' }))
  const question = await screen.findByRole('alertdialog', { name: 'Delete link' })
  expect(question).toHaveAccessibleDescription(
    'Delete Client review? It is removed from this list for good. This cannot be undone.',
  )
  await user.click(within(question).getByRole('button', { name: 'Delete' }))
  expect(await within(question).findByRole('alert')).toHaveTextContent(
    'Unable to delete this link. Try again.',
  )
  // The error shows once, in the question it belongs to.
  expect(screen.getAllByText('Unable to delete this link. Try again.')).toHaveLength(1)

  await user.click(within(question).getByRole('button', { name: 'Delete' }))
  expect(
    await screen.findByText('No links yet. Create one when you are ready to share.'),
  ).toBeInTheDocument()
  expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
  expect(posts(calls).map(call => call.path)).toEqual([`/api/workspace/shares/${LINK}/delete`])
})
