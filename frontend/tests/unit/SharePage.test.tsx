import { render, screen, waitFor } from '../render'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { MemoryRouter } from '../router'
import { afterEach, expect, it, vi } from 'vitest'
import { openShare } from '../../src/features/sharing/api'
import { SharePage } from '../../src/features/sharing/SharePage'

const ID = 'a'.repeat(32)
const SECRET = 'b'.repeat(64)

type Call = { path: string; method: string; body: unknown; csrf: string | undefined }

/** A visitor API in memory; every request is recorded with the address the browser showed when it was sent. */
function server(respond: (call: Call) => Response | Promise<Response>) {
  const calls: (Call & { address: string })[] = []
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
      calls.push({ ...call, address: window.location.href })
      return Promise.resolve(respond(call))
    }),
  )
  return calls
}

afterEach(() => {
  window.history.replaceState(null, '', '/')
  Reflect.deleteProperty(navigator, 'locks')
})

it('exchanges the secret once, after it has left the address bar', async () => {
  window.history.replaceState({ from: 'test' }, '', `/s/${ID}?tab=files#${SECRET}`)
  const calls = server(() => Response.json({ csrfToken: 'visitor-csrf' }))

  await expect(openShare(ID)).resolves.toEqual({ csrfToken: 'visitor-csrf' })
  expect(calls).toHaveLength(1)
  expect(calls[0]).toMatchObject({
    path: `/api/shares/${ID}/exchange`,
    method: 'POST',
    body: { secret: SECRET },
  })
  // The request was sent from an address without the secret; the rest of the address and the history state stay.
  expect(calls[0]!.address).toBe(`${window.location.origin}/s/${ID}?tab=files`)
  expect(window.history.state).toEqual({ from: 'test' })

  // Opening the link again only asks for the session, so the secret is never sent twice.
  await openShare(ID)
  expect(calls[1]).toMatchObject({
    path: `/api/shares/${ID}/session`,
    method: 'GET',
    body: undefined,
  })
  expect(
    calls.map(call => JSON.stringify(call.body ?? {})).filter(body => body.includes(SECRET)),
  ).toHaveLength(1)
})

it('lets one tab at a time exchange a secret, so all tabs share one visitor session', async () => {
  window.history.replaceState(null, '', `/s/${ID}#${SECRET}`)
  const steps: string[] = []
  server(() => {
    steps.push('exchange')
    return Response.json({ csrfToken: 'visitor-csrf' })
  })
  const request = vi.fn(async (name: string, work: () => Promise<unknown>) => {
    steps.push(`lock ${name}`)
    const result = await work()
    steps.push('unlock')
    return result
  })
  Object.defineProperty(navigator, 'locks', { value: { request }, configurable: true })

  await openShare(ID)
  expect(steps).toEqual(['lock glimpse-share-exchange', 'exchange', 'unlock'])
})

function page(address = `/s/${ID}`) {
  return render(
    <MemoryRouter initialEntries={[address]}>
      <Routes>
        <Route path="/s/:publicId/*" element={<SharePage />} />
      </Routes>
    </MemoryRouter>,
  )
}

it('ends the visit with its CSRF token and then shows nothing of the repository', async () => {
  window.history.replaceState(null, '', `/s/${ID}`)
  const calls = server(call => {
    if (call.path === `/api/shares/${ID}/session`)
      return Response.json({ csrfToken: 'visitor-csrf' })
    if (call.path === '/api/visitor/logout') return Response.json({ status: 'ended' })
    // Repository previews stay pending; this test is about the visit itself.
    return new Promise<Response>(() => {})
  })
  const user = userEvent.setup()
  page()

  await user.click(await screen.findByRole('button', { name: 'End session' }))
  expect(calls.find(call => call.path === '/api/visitor/logout')).toMatchObject({
    method: 'POST',
    csrf: 'visitor-csrf',
  })
  expect(await screen.findByRole('alert')).toHaveTextContent(
    'Your session has ended. Open the original access link to return.',
  )
  expect(screen.queryByRole('button', { name: 'End session' })).not.toBeInTheDocument()
  expect(calls.some(call => call.path.endsWith('/exchange'))).toBe(false)
})

it('shows why a link cannot be opened, without any repository view', async () => {
  window.history.replaceState(null, '', `/s/${ID}`)
  server(() =>
    Response.json(
      {
        error: {
          code: 'share_unavailable',
          message:
            'This link is invalid, expired, revoked, or unavailable. Open the original link to try again.',
        },
      },
      { status: 404 },
    ),
  )
  page()

  expect(await screen.findByRole('heading', { name: 'Repository unavailable' })).toBeInTheDocument()
  expect(document.title).toBe('Repository unavailable · Glimpse')
  expect(screen.getByRole('alert')).toHaveTextContent(
    'This link is invalid, expired, revoked, or unavailable.',
  )
  expect(screen.queryByRole('button', { name: 'End session' })).not.toBeInTheDocument()
})

it('names the open file and the shared repository in the browser tab', async () => {
  window.history.replaceState(null, '', `/s/${ID}`)
  const sha = 'c'.repeat(40)
  server(call => {
    if (call.path === `/api/shares/${ID}/session`)
      return Response.json({ csrfToken: 'visitor-csrf' })
    if (call.path.includes('kind=branches'))
      return Response.json({
        items: ['main'],
        nextPage: null,
        defaultBranch: 'main',
        allowHistory: false,
      })
    return Response.json({
      repository: { id: 1, fullName: 'fixture/alpha', private: true, defaultBranch: 'main' },
      commitSha: sha,
      path: '',
      kind: 'directory',
      branch: 'main',
      allowHistory: false,
      historical: false,
      checkedAt: '',
      contentSource: 'upstream',
      entries: [],
    })
  })
  page(`/s/${ID}?path=docs%2Fguide.md&kind=file`)
  expect(document.title).toBe('guide.md · Shared repository · Glimpse')
  await waitFor(() => expect(document.title).toBe('guide.md · fixture/alpha · Glimpse'))
})
