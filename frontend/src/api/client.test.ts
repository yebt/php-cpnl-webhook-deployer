import { describe, expect, it, vi } from 'vitest'
import { ApiError, createApiClient } from './client'

type Call = { url: string; init: RequestInit }

function jsonResponse(status: number, body: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
  })
}

/** A fetch double that answers from a queue (or a router function) and records every call. */
function fakeFetch(handler: (url: string, init: RequestInit) => Response) {
  const calls: Call[] = []
  const fn = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    calls.push({ url, init: init ?? {} })
    return handler(url, init ?? {})
  })
  return { fn: fn as unknown as typeof fetch, calls }
}

function header(call: Call | undefined, name: string): string | null {
  return new Headers(call?.init.headers).get(name)
}

describe('api client', () => {
  it('sends GET requests under the base path with credentials and no CSRF header', async () => {
    const { fn, calls } = fakeFetch(() => jsonResponse(200, { projects: [] }))
    const api = createApiClient({ basePath: '/index.php', fetch: fn })

    const data = await api.get<{ projects: unknown[] }>('/api/projects', { query: { limit: 5, status: undefined } })

    expect(data).toEqual({ projects: [] })
    expect(calls).toHaveLength(1)
    expect(calls[0]?.url).toBe('/index.php/api/projects?limit=5')
    expect(calls[0]?.init.credentials).toBe('same-origin')
    expect(header(calls[0], 'X-CSRF-Token')).toBeNull()
  })

  it('loads the CSRF token from /api/me before the first state-changing call', async () => {
    const { fn, calls } = fakeFetch((url) =>
      url.endsWith('/api/me')
        ? jsonResponse(200, { user: { id: 1, username: 'admin' }, csrf_token: 'tok-1' })
        : jsonResponse(201, { project: { id: 3 } }),
    )
    const api = createApiClient({ basePath: '', fetch: fn })

    await api.post('/api/projects', { name: 'Shop' })
    await api.put('/api/projects/3', { name: 'Shop 2' })

    expect(calls.map((c) => `${c.init.method} ${c.url}`)).toEqual([
      'GET /api/me',
      'POST /api/projects',
      'PUT /api/projects/3',
    ])
    expect(header(calls[1], 'X-CSRF-Token')).toBe('tok-1')
    expect(header(calls[2], 'X-CSRF-Token')).toBe('tok-1')
    expect(header(calls[1], 'Content-Type')).toBe('application/json')
    expect(calls[1]?.init.body).toBe(JSON.stringify({ name: 'Shop' }))
  })

  it('uses a token set explicitly (login/setup responses) without calling /api/me', async () => {
    const { fn, calls } = fakeFetch(() => jsonResponse(202, { run_id: 7, status: 'queued' }))
    const api = createApiClient({ basePath: '', fetch: fn })
    api.setCsrfToken('from-login')

    await api.post('/api/projects/1/runs')

    expect(calls).toHaveLength(1)
    expect(header(calls[0], 'X-CSRF-Token')).toBe('from-login')
  })

  it('reports an expired session and forgets the token', async () => {
    const onUnauthenticated = vi.fn()
    let meCalls = 0
    const { fn } = fakeFetch((url) => {
      if (url.endsWith('/api/me')) {
        meCalls++
        return jsonResponse(200, { user: { id: 1, username: 'a' }, csrf_token: 'fresh' })
      }
      return jsonResponse(401, { error: { code: 'unauthenticated', message: 'Authentication required.' } })
    })
    const api = createApiClient({ basePath: '', fetch: fn, onUnauthenticated })
    api.setCsrfToken('stale')

    const error = await api.post('/api/runs/1/cancel').catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(401)
    expect((error as ApiError).code).toBe('unauthenticated')
    expect(onUnauthenticated).toHaveBeenCalledTimes(1)

    await api.post('/api/runs/1/cancel').catch(() => undefined)
    expect(meCalls).toBe(1)
  })

  it('does not treat wrong login credentials as an expired session', async () => {
    const onUnauthenticated = vi.fn()
    const { fn } = fakeFetch(() =>
      jsonResponse(401, { error: { code: 'invalid_credentials', message: 'Invalid username or password.' } }),
    )
    const api = createApiClient({ basePath: '', fetch: fn, onUnauthenticated })

    const error = await api.post('/api/login', { username: 'a', password: 'b' }, { csrf: false }).catch((e: unknown) => e)

    expect((error as ApiError).code).toBe('invalid_credentials')
    expect((error as ApiError).message).toBe('Invalid username or password.')
    expect(onUnauthenticated).not.toHaveBeenCalled()
  })

  it('refreshes the CSRF token once and retries after a csrf_mismatch', async () => {
    let attempts = 0
    const { fn, calls } = fakeFetch((url) => {
      if (url.endsWith('/api/me')) return jsonResponse(200, { user: { id: 1, username: 'a' }, csrf_token: 'rotated' })
      attempts++
      return attempts === 1
        ? jsonResponse(403, { error: { code: 'csrf_mismatch', message: 'Missing or invalid X-CSRF-Token header.' } })
        : jsonResponse(200, { result: 'cancelled' })
    })
    const api = createApiClient({ basePath: '', fetch: fn })
    api.setCsrfToken('old')

    const result = await api.post<{ result: string }>('/api/runs/4/cancel')

    expect(result).toEqual({ result: 'cancelled' })
    expect(calls.map((c) => header(c, 'X-CSRF-Token'))).toEqual(['old', null, 'rotated'])
  })

  it('exposes validation details from the uniform error shape', async () => {
    const { fn } = fakeFetch(() =>
      jsonResponse(422, {
        error: { code: 'validation_failed', message: 'Validation failed.', details: { slug: 'Use 1-63 lowercase letters.' } },
      }),
    )
    const api = createApiClient({ basePath: '', fetch: fn })
    api.setCsrfToken('t')

    const error = (await api.post('/api/projects', {}).catch((e: unknown) => e)) as ApiError

    expect(error.status).toBe(422)
    expect(error.fieldErrors()).toEqual({ slug: 'Use 1-63 lowercase letters.' })
  })

  it('returns undefined for 204 responses', async () => {
    const { fn } = fakeFetch(() => jsonResponse(204, undefined))
    const api = createApiClient({ basePath: '', fetch: fn })
    api.setCsrfToken('t')

    await expect(api.del('/api/projects/1')).resolves.toBeUndefined()
  })

  it('wraps network failures and non-JSON errors in ApiError', async () => {
    const offline = createApiClient({
      basePath: '',
      fetch: (async () => {
        throw new TypeError('Failed to fetch')
      }) as typeof fetch,
    })
    const networkError = (await offline.get('/api/me').catch((e: unknown) => e)) as ApiError
    expect(networkError).toBeInstanceOf(ApiError)
    expect(networkError.status).toBe(0)
    expect(networkError.code).toBe('network_error')

    const proxy = createApiClient({
      basePath: '',
      fetch: (async () => new Response('<html>Bad gateway</html>', { status: 502 })) as typeof fetch,
    })
    const proxyError = (await proxy.get('/api/me').catch((e: unknown) => e)) as ApiError
    expect(proxyError.status).toBe(502)
    expect(proxyError.code).toBe('http_error')
  })
})
