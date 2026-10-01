export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public retryAfter: number | null = null,
  ) {
    super(message)
  }
}

/** The message of a failed request, a fallback when it has none, or '' when nothing failed. */
export function errorMessage(reason: unknown, fallback: string): string {
  if (!reason) return ''
  return reason instanceof Error && reason.message ? reason.message : fallback
}

export async function request<T>(path: `/api/${string}`, options: RequestInit = {}): Promise<T> {
  const response = await fetch(path, { ...options, credentials: 'same-origin', cache: 'no-store' })
  let data: unknown
  try {
    data = await response.json()
  } catch {
    throw new ApiError(
      response.status,
      'invalid_response',
      'The server returned an unexpected response.',
    )
  }
  if (!response.ok) {
    const error = (data as { error?: { code?: unknown; message?: unknown } } | null)?.error
    throw new ApiError(
      response.status,
      typeof error?.code === 'string' ? error.code : 'request_failed',
      typeof error?.message === 'string' ? error.message : 'Unable to complete this request.',
      retryAfterSeconds(response.headers.get('Retry-After')),
    )
  }
  return data as T
}

/** Retry-After as seconds (1 s to 1 day); the header holds either seconds or a date. */
function retryAfterSeconds(header: string | null): number | null {
  if (header === null) return null
  const seconds = /^\d+$/.test(header)
    ? Number(header)
    : Math.ceil((Date.parse(header) - Date.now()) / 1000)
  if (!Number.isFinite(seconds)) return null
  return Math.max(1, Math.min(86400, seconds))
}

function send<T>(
  method: 'POST' | 'PUT' | 'PATCH',
  path: `/api/${string}`,
  body: object,
  csrfToken?: string,
): Promise<T> {
  return request<T>(path, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
    },
    body: JSON.stringify(body),
  })
}

export function post<T>(path: `/api/${string}`, body: object, csrfToken?: string): Promise<T> {
  return send<T>('POST', path, body, csrfToken)
}

export function put<T>(path: `/api/${string}`, body: object, csrfToken?: string): Promise<T> {
  return send<T>('PUT', path, body, csrfToken)
}

export function patch<T>(path: `/api/${string}`, body: object, csrfToken?: string): Promise<T> {
  return send<T>('PATCH', path, body, csrfToken)
}
