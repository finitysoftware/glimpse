import { render, screen } from '../render'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, it, vi } from 'vitest'
import { AccessLinkRow } from '../../src/features/repositories/links/AccessLinkRow'
import type { ShareLink } from '../../src/features/repositories/links/api'

const link: ShareLink = {
  publicId: 'a'.repeat(32),
  name: 'Client review',
  createdAt: '',
  expiresAt: null,
  status: 'active',
  branches: ['main', 'docs'],
  allowHistory: false,
  policyVersion: 2,
  policies: [
    { branch: 'main', mode: 'selected', selectedCount: 2, profileVersion: 3 },
    { branch: 'docs', mode: 'all', selectedCount: 0, profileVersion: null },
  ],
}
const props = {
  link,
  active: true,
  storageConfigured: true,
  pending: false,
  csrfToken: 'test-csrf',
  onRevoke: vi.fn(),
  onDelete: vi.fn(),
}
afterEach(() => vi.unstubAllGlobals())

it('does not automatically fetch the URL and displays reveal failures without a stale URL', async () => {
  const fetch = vi.fn().mockResolvedValue(
    new Response(
      JSON.stringify({
        error: { code: 'share_url_unavailable', message: 'The saved URL could not be opened.' },
      }),
      { status: 409 },
    ),
  )
  vi.stubGlobal('fetch', fetch)
  const user = userEvent.setup()
  render(<AccessLinkRow {...props} />)
  expect(fetch).not.toHaveBeenCalled()
  await user.click(screen.getByRole('button', { name: 'Show' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('The saved URL could not be opened.')
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Show' })).toBeEnabled()
})

it('disables Show without a storage key but leaves Revoke available', () => {
  render(<AccessLinkRow {...props} storageConfigured={false} />)
  expect(screen.getByRole('button', { name: 'Show' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Revoke' })).toBeEnabled()
})

it('sums up what the link opens: files per branch and History', () => {
  render(<AccessLinkRow {...props} />)
  expect(
    screen.getByText('main: 2 selected files · docs: all files · History hidden'),
  ).toBeInTheDocument()
})
