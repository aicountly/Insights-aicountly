/**
 * Company scope, session, permissions and the period, for the whole app.
 *
 * The scope is three ids. The names behind them — company, branch, financial
 * year — belong to Manage and are read from Manage through the API; this
 * provider holds only what it needs to make calls and to remember the user's
 * last choice between visits.
 *
 * THE PERIOD LIVES HERE TOO, and that is deliberate. Every page answers for a
 * window, and a window that reset on navigation would make moving from the
 * overview to a dashboard silently change the question. Changing it, like
 * changing the company, invalidates what is on screen rather than letting an
 * old answer sit under a new label.
 */

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, isStale, setScope, type CompanyScope } from '../services/api'
import { session as sessionApi } from '../services/insights'
import type { InsightsSession, PeriodInfo } from '../services/types'

export interface PeriodChoice {
  preset: string
  from: string | null
  to: string | null
  grain: string
  compare: string
}

interface InsightsContextValue {
  scope: CompanyScope | null
  setCompanyScope: (scope: CompanyScope) => void
  session: InsightsSession | null
  /** Has this user got that permission? Company owners hold everything. */
  can: (permission: string) => boolean
  period: PeriodChoice
  setPeriod: (period: Partial<PeriodChoice>) => void
  /** Bumped whenever the user asks for fresh figures. Pages depend on it. */
  refreshToken: number
  refresh: () => void
  loading: boolean
  error: string | null
  reload: () => void
}

const InsightsContextObject = createContext<InsightsContextValue | null>(null)

const SCOPE_KEY = 'insights:scope'
const PERIOD_KEY = 'insights:period'

const DEFAULT_PERIOD: PeriodChoice = {
  preset: 'this_month',
  from: null,
  to: null,
  grain: 'month',
  compare: 'previous_period',
}

function readStoredScope(): CompanyScope | null {
  try {
    const raw = window.localStorage.getItem(SCOPE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<CompanyScope>
    if (!parsed.cmp_id || !parsed.fy_id) return null
    return { cmp_id: Number(parsed.cmp_id), fy_id: Number(parsed.fy_id), bo_id: Number(parsed.bo_id ?? 0) }
  } catch {
    // A private window, or storage the browser refuses. The app still works;
    // the user just picks their company again.
    return null
  }
}

function readStoredPeriod(): PeriodChoice {
  try {
    const raw = window.localStorage.getItem(PERIOD_KEY)
    if (!raw) return DEFAULT_PERIOD
    const parsed = JSON.parse(raw) as Partial<PeriodChoice>
    return { ...DEFAULT_PERIOD, ...parsed }
  } catch {
    return DEFAULT_PERIOD
  }
}

export function InsightsProvider({ children }: { children: ReactNode }) {
  const [scope, setScopeState] = useState<CompanyScope | null>(() => readStoredScope())
  const [session, setSession] = useState<InsightsSession | null>(null)
  const [period, setPeriodState] = useState<PeriodChoice>(() => readStoredPeriod())
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [refreshToken, setRefreshToken] = useState(0)

  // The API module reads the scope on every call, so it is registered as soon
  // as it changes rather than being threaded through each request. Registering
  // it is also what aborts everything in flight for the previous company.
  useEffect(() => {
    setScope(scope)
  }, [scope])

  const setCompanyScope = useCallback((next: CompanyScope) => {
    setScopeState((current) => {
      if (current && current.cmp_id === next.cmp_id && current.fy_id === next.fy_id && current.bo_id === next.bo_id) {
        return current
      }
      try {
        window.localStorage.setItem(SCOPE_KEY, JSON.stringify(next))
      } catch {
        /* storage refused; the choice still applies for this visit */
      }
      return next
    })
  }, [])

  const setPeriod = useCallback((next: Partial<PeriodChoice>) => {
    setPeriodState((current) => {
      const merged = { ...current, ...next }
      // Choosing a preset drops any custom dates, and choosing dates makes the
      // preset custom. Leaving both set is how a filter bar starts lying about
      // which window it is showing.
      if (next.preset && next.preset !== 'custom') {
        merged.from = null
        merged.to = null
      }
      if ((next.from || next.to) && !next.preset) {
        merged.preset = 'custom'
      }
      try {
        window.localStorage.setItem(PERIOD_KEY, JSON.stringify(merged))
      } catch {
        /* ignore */
      }
      return merged
    })
  }, [])

  useEffect(() => {
    if (!scope) {
      setSession(null)
      return
    }

    let cancelled = false
    const controller = new AbortController()

    setLoading(true)
    setError(null)

    sessionApi
      .load(controller.signal)
      .then((loaded) => {
        if (!cancelled) setSession(loaded)
      })
      .catch((err: Error) => {
        if (cancelled || isStale(err)) return
        setError(err.message)
        setSession(null)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [scope, reloadToken])

  const can = useCallback(
    (permission: string) => {
      if (!session) return false
      if (session.is_owner) return true
      return session.permissions.includes(permission)
    },
    [session],
  )

  const value = useMemo<InsightsContextValue>(
    () => ({
      scope,
      setCompanyScope,
      session,
      can,
      period,
      setPeriod,
      refreshToken,
      refresh: () => setRefreshToken((n) => n + 1),
      loading,
      error,
      reload: () => setReloadToken((n) => n + 1),
    }),
    [scope, setCompanyScope, session, can, period, setPeriod, refreshToken, loading, error],
  )

  return <InsightsContextObject.Provider value={value}>{children}</InsightsContextObject.Provider>
}

export function useInsights(): InsightsContextValue {
  const value = useContext(InsightsContextObject)
  if (!value) throw new Error('useInsights must be used inside InsightsProvider')
  return value
}

/** The period, in the shape the service layer takes. */
export function usePeriodQuery(): PeriodChoice {
  return useInsights().period
}

/** Turn an API PeriodInfo back into a choice, for "show me this period" links. */
export function periodFromInfo(info: PeriodInfo): PeriodChoice {
  return {
    preset: info.preset,
    from: info.preset === 'custom' ? info.from : null,
    to: info.preset === 'custom' ? info.to : null,
    grain: info.grain,
    compare: info.comparison_mode,
  }
}

export { api }
