import { describe, expect, it, vi } from 'vitest'
import { ApiError, post, request } from '../../src/api/client'

describe('API client', () => {
  it('uses same-origin cookies and disables browser caching', async () => {
    const fetch = vi.fn().mockResolvedValue(Response.json({ status: 'ok' }))
    vi.stubGlobal('fetch', fetch)
    await expect(request('/api/health')).resolves.toEqual({ status: 'ok' })
    expect(fetch).toHaveBeenCalledWith(
      '/api/health',
      expect.objectContaining({ credentials: 'same-origin', cache: 'no-store' }),
    )
  })
  it('sends the CSRF token in a header and keeps credentials out of URLs', async () => {
    const fetch = vi.fn().mockResolvedValue(Response.json({ status: 'ok' }))
    vi.stubGlobal('fetch', fetch)
    await post('/api/auth/logout', {}, 'csrf-fixture')
    expect(fetch).toHaveBeenCalledWith(
      '/api/auth/logout',
      expect.objectContaining({
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': 'csrf-fixture' },
        body: '{}',
      }),
    )
  })
  it('preserves useful safe server errors', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          Response.json(
            { error: { code: 'unauthorized', message: 'Sign in to continue.' } },
            { status: 401 },
          ),
        ),
    )
    await expect(request('/api/workspace/diagnostics')).rejects.toMatchObject({
      status: 401,
      code: 'unauthorized',
    })
  })
  it('handles HTML proxy errors without exposing their body', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('<html>private stack trace</html>', { status: 502 })),
    )
    await expect(request('/api/health')).rejects.toEqual(
      new ApiError(502, 'invalid_response', 'The server returned an unexpected response.'),
    )
  })
  it('passes cancellation through to fetch', async () => {
    const controller = new AbortController()
    const fetch = vi.fn().mockRejectedValue(new DOMException('Aborted', 'AbortError'))
    vi.stubGlobal('fetch', fetch)
    controller.abort()
    await expect(request('/api/health', { signal: controller.signal })).rejects.toMatchObject({
      name: 'AbortError',
    })
    expect(fetch).toHaveBeenCalledWith(
      '/api/health',
      expect.objectContaining({ signal: controller.signal }),
    )
  })
})
