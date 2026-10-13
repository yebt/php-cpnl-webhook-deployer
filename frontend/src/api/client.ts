/**
 * Thin fetch wrapper for the deployer API.
 *
 * - Every call is same-origin with the session cookie.
 * - State-changing calls carry X-CSRF-Token; the token comes from login/setup responses
 *   or is loaded lazily from GET /api/me, and is refreshed once on a csrf_mismatch.
 * - An `unauthenticated` 401 (expired session) clears the token and calls
 *   `onUnauthenticated` so the app can redirect to the login screen.
 * - Every failure is an ApiError with the backend's uniform `{error: {code, message, details}}`.
 */

export type FieldErrors = Record<string, string>

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Record<string, unknown> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Field-level validation messages (422 validation_failed), flattened to one string per field. */
  fieldErrors(): FieldErrors {
    if (this.code !== 'validation_failed') return {}
    const out: FieldErrors = {}
    for (const [field, value] of Object.entries(this.details)) {
      out[field] = Array.isArray(value) ? value.join(' ') : String(value)
    }
    return out
  }
}

type Query = Record<string, string | number | boolean | null | undefined>

export interface RequestOptions {
  query?: Query
  /** Set to false for the CSRF-exempt endpoints (login, setup). */
  csrf?: boolean
}

export interface ApiClientOptions {
  /** URL prefix of the app: '' behind a rewrite, '/index.php' for PATH_INFO URLs. */
  basePath: string
  fetch?: typeof fetch
  onUnauthenticated?: () => void
}

const SAFE_METHODS = new Set(['GET', 'HEAD'])

export function createApiClient(options: ApiClientOptions) {
  const doFetch = options.fetch ?? ((input, init) => globalThis.fetch(input, init))
  let csrfToken: string | null = null

  function url(path: string, query?: Query): string {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
    }
    const qs = params.toString()
    return `${options.basePath}${path}${qs ? `?${qs}` : ''}`
  }

  async function send(method: string, path: string, body: unknown, opts: RequestOptions): Promise<Response> {
    const headers = new Headers({ Accept: 'application/json' })
    if (body !== undefined) headers.set('Content-Type', 'application/json')
    if (!SAFE_METHODS.has(method) && opts.csrf !== false) {
      headers.set('X-CSRF-Token', csrfToken ?? (await loadCsrfToken()))
    }
    try {
      return await doFetch(url(path, opts.query), {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
      })
    } catch (e) {
      throw new ApiError(0, 'network_error', 'Cannot reach the server. Check your connection and try again.', {
        cause: String(e),
      })
    }
  }

  async function toError(response: Response): Promise<ApiError> {
    let payload: unknown = null
    try {
      payload = await response.json()
    } catch {
      // Not JSON (e.g. a proxy error page): fall through to the generic error.
    }
    const error = (payload as { error?: { code?: unknown; message?: unknown; details?: unknown } } | null)?.error
    if (error && typeof error.code === 'string') {
      return new ApiError(
        response.status,
        error.code,
        typeof error.message === 'string' ? error.message : 'Request failed.',
        (error.details as Record<string, unknown> | undefined) ?? {},
      )
    }
    return new ApiError(response.status, 'http_error', `The server answered with HTTP ${response.status}.`)
  }

  async function request<T>(method: string, path: string, body?: unknown, opts: RequestOptions = {}): Promise<T> {
    let response = await send(method, path, body, opts)
    let error = response.ok ? null : await toError(response)

    if (error?.code === 'csrf_mismatch') {
      csrfToken = null
      response = await send(method, path, body, opts)
      error = response.ok ? null : await toError(response)
    }
    if (error) {
      if (error.code === 'unauthenticated') {
        csrfToken = null
        options.onUnauthenticated?.()
      }
      throw error
    }
    if (response.status === 204) return undefined as T
    const text = await response.text()
    return (text === '' ? undefined : JSON.parse(text)) as T
  }

  async function loadCsrfToken(): Promise<string> {
    const me = await request<{ csrf_token: string }>('GET', '/api/me')
    csrfToken = me.csrf_token
    return csrfToken
  }

  return {
    get: <T>(path: string, opts?: RequestOptions) => request<T>('GET', path, undefined, opts),
    post: <T>(path: string, body?: unknown, opts?: RequestOptions) => request<T>('POST', path, body, opts),
    put: <T>(path: string, body?: unknown, opts?: RequestOptions) => request<T>('PUT', path, body, opts),
    del: <T = void>(path: string, opts?: RequestOptions) => request<T>('DELETE', path, undefined, opts),
    setCsrfToken(token: string | null) {
      csrfToken = token
    },
  }
}

export type ApiClient = ReturnType<typeof createApiClient>
