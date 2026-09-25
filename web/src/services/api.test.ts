/**
 * The scope epoch.
 *
 * Insights fans out to five products, so a slow reply can land after the user
 * has switched company, branch or financial year. Acceptance criterion 3 is
 * that such a reply is never painted — one company's figures under another
 * company's name is the worst thing a multi-tenant dashboard can do.
 *
 * These tests drive `api` through a fake `fetch` whose resolution we control,
 * so "the reply arrives late" is a thing we can actually make happen rather
 * than something we hope a timeout approximates.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../auth/portal', () => ({
  ensureSesKey: vi.fn(async () => 'ses-key-for-tests'),
}))

vi.mock('../config', () => ({
  getApiBaseUrl: () => 'https://insights.test/api',
  APP_NAME: 'Insights',
  APP_ENV: 'local',
}))

import { api, ApiError, getEpoch, getScope, isStale, setScope, StaleScopeError } from './api'
import { ensureSesKey } from '../auth/portal'

/** A fetch we can hold open, so a reply can be made to arrive late on purpose. */
interface Deferred {
  url: string
  init: RequestInit
  settle: (response: Response) => void
  fail: (error: unknown) => void
  aborted: () => boolean
}

let pending: Deferred[] = []

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

beforeEach(() => {
  pending = []
  setScope(null)

  vi.stubGlobal(
    'fetch',
    vi.fn(
      (input: RequestInfo | URL, init: RequestInit = {}) =>
        new Promise<Response>((resolve, reject) => {
          const entry: Deferred = {
            url: String(input),
            init,
            settle: resolve,
            fail: reject,
            aborted: () => init.signal?.aborted === true,
          }
          pending.push(entry)

          init.signal?.addEventListener('abort', () => {
            reject(new DOMException('The operation was aborted.', 'AbortError'))
          })
        }),
    ),
  )
})

afterEach(() => {
  setScope(null)
  vi.unstubAllGlobals()
})

/** Let the microtask queue drain so the request has actually reached fetch. */
const settleMicrotasks = () => new Promise<void>((resolve) => setTimeout(resolve, 0))

describe('scope registration', () => {
  it('bumps the epoch only when the scope really changes', () => {
    const start = getEpoch()

    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })
    expect(getEpoch()).toBe(start + 1)

    // Re-registering the same scope on every render must not cancel the page's
    // own loads.
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })
    expect(getEpoch()).toBe(start + 1)

    // A branch change is a change: consolidated and one branch are different
    // questions with different answers.
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 2 })
    expect(getEpoch()).toBe(start + 2)

    setScope({ cmp_id: 7, fy_id: 4, bo_id: 2 })
    expect(getEpoch()).toBe(start + 3)

    expect(getScope()).toEqual({ cmp_id: 7, fy_id: 4, bo_id: 2 })
  })
})

describe('late replies', () => {
  it('never paints a reply that arrives after the company changed', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    const call = api.get<{ data: unknown }>('metrics/finance.net_revenue')
    await settleMicrotasks()
    expect(pending).toHaveLength(1)

    const inFlight = pending[0]
    expect(inFlight.url).toContain('cmp_id=7')

    // The user switches company while that is still loading.
    setScope({ cmp_id: 8, fy_id: 3, bo_id: 0 })

    // Company 7's answer turns up anyway.
    inFlight.settle(jsonResponse({ data: { value: '1250000.55' } }))

    // Whether the abort or the epoch check got there first does not matter to
    // the caller: both mean "the question this answers is no longer the
    // question being asked", and both are swallowed rather than shown.
    const error = await call.catch((e: unknown) => e)
    expect(isStale(error)).toBe(true)
    await expect(call).rejects.toThrow()
  })

  it('rejects a reply already in hand when the scope moves before it is read', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    const call = api.get<{ data: unknown }>('metrics/finance.net_revenue')
    await settleMicrotasks()

    // The wire is done — aborting achieves nothing now, which is exactly the
    // case the epoch exists for. Company 7's bytes are sitting there waiting to
    // be parsed when the user switches to company 8.
    pending[0].settle(jsonResponse({ data: { value: '1250000.55' } }))
    setScope({ cmp_id: 8, fy_id: 3, bo_id: 0 })

    await expect(call).rejects.toBeInstanceOf(StaleScopeError)
  })

  it('aborts everything in flight when the scope changes', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    const first = api.get('overview')
    const second = api.get('metrics/finance.net_revenue')
    await settleMicrotasks()
    expect(pending).toHaveLength(2)

    setScope({ cmp_id: 9, fy_id: 3, bo_id: 0 })

    expect(pending[0].aborted()).toBe(true)
    expect(pending[1].aborted()).toBe(true)

    await expect(first).rejects.toSatisfy(isStale)
    await expect(second).rejects.toSatisfy(isStale)
  })

  it('still resolves when the scope did not move', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    const call = api.get<{ data: { value: string } }>('metrics/finance.net_revenue')
    await settleMicrotasks()

    pending[0].settle(jsonResponse({ data: { value: '1250000.55' } }))

    await expect(call).resolves.toEqual({ data: { value: '1250000.55' } })
  })

  it('rejects a late export download rather than saving another company’s file', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    const call = api.download('reports/export', { format: 'csv' })
    await settleMicrotasks()

    setScope({ cmp_id: 8, fy_id: 3, bo_id: 0 })

    pending[0].settle(
      new Response('a,b\n1,2', {
        status: 200,
        headers: { 'Content-Disposition': 'attachment; filename="company-7.csv"' },
      }),
    )

    await expect(call).rejects.toBeInstanceOf(StaleScopeError)
  })
})

describe('request shape', () => {
  it('puts the company context on every scoped call, in the query and the body', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 5 })

    void api.post('dashboards', { name: 'Trading' })
    await settleMicrotasks()

    const url = new URL(pending[0].url)
    expect(url.searchParams.get('cmp_id')).toBe('7')
    expect(url.searchParams.get('fy_id')).toBe('3')
    expect(url.searchParams.get('bo_id')).toBe('5')

    // The backend's Http::param() reads the body first on a POST; context that
    // travelled only in the query string fails there in a way that looks like
    // the company was never chosen.
    expect(JSON.parse(String(pending[0].init.body))).toEqual({
      cmp_id: 7,
      fy_id: 3,
      bo_id: 5,
      name: 'Trading',
    })

    pending[0].settle(jsonResponse({ data: {} }))
  })

  it('leaves context off the calls that must work before a company is chosen', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    void api.unscoped('health')
    await settleMicrotasks()

    const url = new URL(pending[0].url)
    expect(url.searchParams.get('cmp_id')).toBeNull()

    pending[0].settle(jsonResponse({ data: {} }))
  })

  it('drops empty parameters instead of sending cmp_id=&q=', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    void api.get('metrics', { q: '', period: 'this_month', limit: undefined })
    await settleMicrotasks()

    const url = new URL(pending[0].url)
    expect(url.searchParams.has('q')).toBe(false)
    expect(url.searchParams.has('limit')).toBe(false)
    expect(url.searchParams.get('period')).toBe('this_month')

    pending[0].settle(jsonResponse({ data: [] }))
  })
})

describe('errors', () => {
  it('surfaces the backend envelope, including whether retrying could help', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    const call = api.get('metrics/finance.net_revenue')
    await settleMicrotasks()

    pending[0].settle(
      jsonResponse(
        {
          error: {
            code: 'source_unavailable',
            message: 'Books did not answer in time. This figure is unknown, not zero.',
            details: { retryable: true, correlation_id: 'cid-42' },
          },
        },
        503,
      ),
    )

    const error = await call.catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    const apiError = error as ApiError
    expect(apiError.status).toBe(503)
    expect(apiError.code).toBe('source_unavailable')
    expect(apiError.retryable).toBe(true)
    expect(apiError.correlationId).toBe('cid-42')
    expect(apiError.isDenied).toBe(false)
  })

  it('treats a 403 as denied and not worth retrying', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    const call = api.get('dashboards/abc')
    await settleMicrotasks()

    pending[0].settle(
      jsonResponse({ error: { code: 'forbidden', message: 'You do not have access to this dashboard.' } }, 403),
    )

    const error = (await call.catch((e: unknown) => e)) as ApiError
    expect(error.isDenied).toBe(true)
    expect(error.retryable).toBe(false)
  })

  it('retries a 401 once against a freshly minted key, then gives up', async () => {
    setScope({ cmp_id: 7, fy_id: 3, bo_id: 0 })

    const call = api.get('overview')
    await settleMicrotasks()

    pending[0].settle(jsonResponse({ error: { code: 'unauthorized', message: 'Session expired.' } }, 401))
    await settleMicrotasks()

    // A key can be revoked server-side before it expires locally; making the
    // user sign in again for that is a bad trade.
    expect(vi.mocked(ensureSesKey)).toHaveBeenLastCalledWith(true)
    expect(pending).toHaveLength(2)

    pending[1].settle(jsonResponse({ error: { code: 'unauthorized', message: 'Session expired.' } }, 401))

    const error = (await call.catch((e: unknown) => e)) as ApiError
    expect(error.status).toBe(401)
    // Exactly one retry — not a loop.
    expect(pending).toHaveLength(2)
  })
})
