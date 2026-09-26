/**
 * Typed fetch wrapper for the Insights API.
 *
 * What it encodes so pages do not have to:
 *
 *  - `Authorization: Bearer <ses_key>` from the portal session, minted on
 *    demand, with one silent retry on 401 against a fresh key.
 *  - Company context (cmp_id, fy_id, bo_id) on every scoped call, as query
 *    parameters and — for JSON bodies — in the body too, which is what the
 *    backend's Http::param() reads.
 *  - The fleet's envelopes: `{data}`, `{data, meta}`, and errors as ApiError.
 *
 *  - AND THE THING THIS PRODUCT PARTICULARLY NEEDS: a scope EPOCH. Insights
 *    fans out to five products, and a slow reply can arrive after the user has
 *    switched company, branch or financial year. Painting it would put one
 *    company's figures on screen under another company's name, which is the
 *    worst thing a multi-tenant dashboard can do.
 *
 *    So every request records the epoch it was sent under. Changing the scope
 *    bumps the epoch AND aborts everything in flight; a reply that arrives from
 *    an earlier epoch is rejected as `StaleScopeError`, which callers treat
 *    like an abort — silently — rather than as data.
 */

import { getApiBaseUrl } from '../config'
import { ensureSesKey } from '../auth/portal'

export interface CompanyScope {
  cmp_id: number
  fy_id: number
  /** 0 = consolidated, all branches. */
  bo_id: number
}

export interface ListMeta {
  total: number
  limit: number
  offset: number
  [key: string]: unknown
}

export interface ListResponse<T> {
  data: T[]
  meta: ListMeta
}

export interface ItemResponse<T> {
  data: T
}

export type QueryValue = string | number | boolean | null | undefined
export type QueryParams = Record<string, QueryValue>

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

  /**
   * True when pressing the same button again could reasonably work.
   *
   * The backend says so explicitly for cross-service failures, because the
   * difference between "Books was unreachable" and "Books refused this"
   * decides whether the UI offers Retry or asks the user to change something.
   */
  get retryable(): boolean {
    if (typeof this.details.retryable === 'boolean') return this.details.retryable
    return this.status === 0 || this.status === 502 || this.status === 503 || this.status === 504
  }

  get isDenied(): boolean {
    return this.status === 403
  }

  get correlationId(): string | null {
    return typeof this.details.correlation_id === 'string' ? this.details.correlation_id : null
  }
}

/**
 * A reply that arrived after the scope moved on.
 *
 * Not an error the user should see — the question it answers is no longer the
 * question being asked. Callers swallow it exactly as they swallow an abort.
 */
export class StaleScopeError extends Error {
  constructor() {
    super('The company or period changed while this was loading.')
    this.name = 'StaleScopeError'
  }
}

export function isStale(error: unknown): boolean {
  return error instanceof StaleScopeError || (error instanceof DOMException && error.name === 'AbortError')
}

// ---------------------------------------------------------------------------
// Scope and epoch
// ---------------------------------------------------------------------------

let scope: CompanyScope | null = null
let epoch = 0

/** Every request in flight, so a scope change can abort all of them at once. */
const inFlight = new Set<AbortController>()

/**
 * Anyone who needs to re-ask their question when the scope moves.
 *
 * `useApi` subscribes so that EVERY scoped read re-runs on a company, branch or
 * financial-year change without each page having to remember to put the scope
 * in its dependency list. Ten of eleven pages forgot; making it structural is
 * what stops the eleventh.
 */
const scopeListeners = new Set<() => void>()

export function onScopeChange(listener: () => void): () => void {
  scopeListeners.add(listener)
  return () => {
    scopeListeners.delete(listener)
  }
}

export function getScope(): CompanyScope | null {
  return scope
}

export function getEpoch(): number {
  return epoch
}

/**
 * Register the company scope.
 *
 * A CHANGE ABORTS EVERYTHING IN FLIGHT AND BUMPS THE EPOCH. Setting the same
 * scope again does neither — re-registering on every render would cancel the
 * page's own loads.
 */
export function setScope(next: CompanyScope | null): void {
  const changed =
    (scope === null) !== (next === null) ||
    (scope !== null && next !== null && (scope.cmp_id !== next.cmp_id || scope.fy_id !== next.fy_id || scope.bo_id !== next.bo_id))

  scope = next
  if (!changed) return

  epoch += 1
  for (const controller of inFlight) {
    controller.abort()
  }
  inFlight.clear()

  for (const listener of scopeListeners) {
    listener()
  }
}

function buildUrl(path: string, params: QueryParams | undefined, scoped: boolean): string {
  const url = new URL(`${getApiBaseUrl()}/${path.replace(/^\//, '')}`, window.location.origin)

  if (scoped && scope) {
    url.searchParams.set('cmp_id', String(scope.cmp_id))
    url.searchParams.set('fy_id', String(scope.fy_id))
    url.searchParams.set('bo_id', String(scope.bo_id))
  }

  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    url.searchParams.set(key, String(value))
  }

  return url.toString()
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  params?: QueryParams
  body?: unknown
  /** Pass false for calls that take no company context. */
  scoped?: boolean
  signal?: AbortSignal
  /** Raw bytes rather than JSON — used by the export download. */
  raw?: boolean
}

async function send<T>(path: string, options: RequestOptions, sesKey: string, sentAtEpoch: number): Promise<T> {
  const scoped = options.scoped !== false
  const method = options.method ?? 'GET'

  const headers: Record<string, string> = {
    Accept: options.raw ? '*/*' : 'application/json',
    Authorization: `Bearer ${sesKey}`,
  }

  let body: string | undefined
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    // Context travels in the body as well: a POST that carries it only in the
    // query string works until somebody reads the body first, and then fails in
    // a way that looks like the company was never chosen.
    const payload =
      scoped && scope && typeof options.body === 'object' && options.body !== null && !Array.isArray(options.body)
        ? { ...scope, ...(options.body as Record<string, unknown>) }
        : options.body
    body = JSON.stringify(payload)
  }

  // One controller per request, registered so a scope change can abort it, and
  // chained to any signal the caller supplied.
  const controller = new AbortController()
  inFlight.add(controller)
  const onCallerAbort = () => controller.abort()
  options.signal?.addEventListener('abort', onCallerAbort)

  let response: Response
  try {
    response = await fetch(buildUrl(path, options.params, scoped), { method, headers, body, signal: controller.signal })
  } finally {
    inFlight.delete(controller)
    options.signal?.removeEventListener('abort', onCallerAbort)
  }

  // The answer to a question nobody is asking any more.
  if (sentAtEpoch !== epoch) throw new StaleScopeError()

  if (options.raw) {
    if (!response.ok) {
      throw new ApiError(response.status, 'download_failed', `That download failed (${response.status}).`)
    }
    return (await response.blob()) as unknown as T
  }

  const text = await response.text()
  let parsed: unknown = null
  if (text) {
    try {
      parsed = JSON.parse(text)
    } catch {
      parsed = null
    }
  }

  if (sentAtEpoch !== epoch) throw new StaleScopeError()

  if (!response.ok) {
    const envelope = parsed as
      | { error?: { code?: string; message?: string; details?: Record<string, unknown> }; message?: string }
      | null
    throw new ApiError(
      response.status,
      envelope?.error?.code ?? 'error',
      envelope?.error?.message ?? envelope?.message ?? `Request failed (${response.status})`,
      envelope?.error?.details ?? {},
    )
  }

  return parsed as T
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const sentAtEpoch = epoch
  const sesKey = await ensureSesKey()

  try {
    return await send<T>(path, options, sesKey, sentAtEpoch)
  } catch (error) {
    // Exactly one retry, and only for 401: a key can be revoked server-side
    // before it expires locally, and making the user sign in again for that is
    // a bad trade.
    if (error instanceof ApiError && error.status === 401) {
      const freshKey = await ensureSesKey(true)
      return await send<T>(path, options, freshKey, sentAtEpoch)
    }
    throw error
  }
}

export const api = {
  get: <T,>(path: string, params?: QueryParams, signal?: AbortSignal) => request<T>(path, { method: 'GET', params, signal }),

  list: <T,>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<ListResponse<T>>(path, { method: 'GET', params, signal }),

  one: <T,>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<ItemResponse<T>>(path, { method: 'GET', params, signal }),

  post: <T,>(path: string, body?: unknown, params?: QueryParams, signal?: AbortSignal) =>
    request<ItemResponse<T>>(path, { method: 'POST', body: body ?? {}, params, signal }),

  put: <T,>(path: string, body?: unknown, params?: QueryParams) => request<ItemResponse<T>>(path, { method: 'PUT', body: body ?? {}, params }),

  del: <T,>(path: string, params?: QueryParams) => request<ItemResponse<T>>(path, { method: 'DELETE', params }),

  /**
   * Context-free: the health check, the portal relay, and the company switcher.
   *
   * The switcher is what the caller uses to CHOOSE a company, so requiring one
   * would be a chicken and egg.
   */
  unscoped: <T,>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', params, scoped: false, signal }),

  /** A file download. Returns the blob and the filename the server chose. */
  download: async (path: string, body: unknown, params?: QueryParams): Promise<{ blob: Blob; filename: string }> => {
    const sentAtEpoch = epoch
    const sesKey = await ensureSesKey()
    const url = buildUrl(path, params, true)

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: '*/*',
        Authorization: `Bearer ${sesKey}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(scope ? { ...scope, ...(body as Record<string, unknown>) } : body),
    })

    if (sentAtEpoch !== epoch) throw new StaleScopeError()

    if (!response.ok) {
      const text = await response.text().catch(() => '')
      let message = `That export failed (${response.status}).`
      try {
        const parsed = JSON.parse(text) as { error?: { message?: string }; message?: string }
        message = parsed?.error?.message ?? parsed?.message ?? message
      } catch {
        /* keep the generic message */
      }
      throw new ApiError(response.status, 'export_failed', message)
    }

    const disposition = response.headers.get('Content-Disposition') ?? ''
    const match = /filename="([^"]+)"/.exec(disposition)

    return { blob: await response.blob(), filename: match?.[1] ?? 'insights-export' }
  },
}
