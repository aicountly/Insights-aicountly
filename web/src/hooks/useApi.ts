/**
 * Fetch-on-mount with loading, error and reload, cancelled cleanly on unmount.
 *
 * The abort matters: without it, a user clicking through a list faster than the
 * network answers gets the FIRST response painted last, and the screen shows a
 * record they have already navigated away from.
 *
 * SO DOES DROPPING THE OLD ANSWER. When the dependencies change — most
 * importantly when somebody switches company — the previous result stops being
 * an answer to the question now being asked. Keeping it on screen while the new
 * one loads shows one company's figures under another company's name, which is
 * the worst thing a multi-tenant screen can do. The old data is cleared the
 * moment the question changes, and the caller's skeleton covers the gap.
 *
 * A plain `reload()` is not a new question, so it keeps what is on screen.
 */

import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { getEpoch, isStale, onScopeChange } from '../services/api'

export interface AsyncState<T> {
  data: T | null
  loading: boolean
  error: string | null
  reload: () => void
}

export function useApi<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  enabled = true,
): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<string | null>(null)
  const [token, setToken] = useState(0)

  const reload = useCallback(() => setToken((n) => n + 1), [])

  // THE SCOPE IS PART OF EVERY QUESTION, whether the caller remembered it or
  // not. Subscribing here rather than asking each page to list `scope` in its
  // dependencies is the difference between an invariant and a convention: a
  // page that forgets keeps showing the previous company's figures under the
  // new company's name, and never re-asks.
  const epoch = useSyncExternalStore(onScopeChange, getEpoch, getEpoch)

  // The identity of the question being asked. `reload` is deliberately not part
  // of it: pressing Retry re-asks the same question and should not blank the
  // screen it is retrying.
  const key = JSON.stringify([...deps, epoch])
  const previousKey = useRef(key)
  if (previousKey.current !== key) {
    previousKey.current = key
    if (data !== null) setData(null)
  }

  useEffect(() => {
    if (!enabled) {
      setLoading(false)
      return
    }

    const controller = new AbortController()
    let cancelled = false

    setLoading(true)
    setError(null)

    fetcher(controller.signal)
      .then((result) => {
        if (!cancelled) setData(result)
      })
      .catch((err: Error) => {
        // An abort is this component going away, and a stale-scope rejection is
        // the answer to a question nobody is asking any more. Neither is a
        // failure, and neither has a message worth showing anybody: the browser
        // writes things like "signal is aborted without reason", which reads as
        // a crash.
        if (cancelled || controller.signal.aborted || isStale(err)) return
        setError(err.message)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, epoch, token, enabled])

  return { data, loading, error, reload }
}
