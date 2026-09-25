/**
 * The scope is part of every question.
 *
 * REGRESSION. Ten of eleven pages listed the period in their dependencies and
 * not the company, because the API module carries the scope for them. Switching
 * company then did two wrong things at once: the in-flight request was aborted
 * and its rejection was painted as "That did not load — signal is aborted
 * without reason", and the page never re-asked, so it sat on that error showing
 * the previous company's name in the header.
 *
 * Both halves are fixed inside the hook rather than in each page, so a new page
 * cannot reintroduce it by forgetting.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
import { useApi } from './useApi'
import { setScope, StaleScopeError } from '../services/api'

function Probe({ fetcher }: { fetcher: (signal: AbortSignal) => Promise<string> }) {
  const { data, loading, error } = useApi(fetcher, ['fixed-dependency'])

  return (
    <div>
      <span data-testid="state">{loading ? 'loading' : error ? `error:${error}` : (data ?? 'empty')}</span>
    </div>
  )
}

beforeEach(() => {
  setScope(null)
})

afterEach(() => {
  setScope(null)
})

describe('useApi and the scope', () => {
  it('re-asks when the company changes, even though the caller never listed it', async () => {
    setScope({ cmp_id: 1, fy_id: 3, bo_id: 0 })

    const fetcher = vi.fn(async () => 'first company')

    render(<Probe fetcher={fetcher} />)
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('first company'))
    expect(fetcher).toHaveBeenCalledTimes(1)

    fetcher.mockResolvedValue('second company')
    await act(async () => {
      setScope({ cmp_id: 2, fy_id: 3, bo_id: 0 })
    })

    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('second company'))
  })

  it('clears the previous company’s answer rather than leaving it under the new name', async () => {
    setScope({ cmp_id: 1, fy_id: 3, bo_id: 0 })

    let release: ((value: string) => void) | null = null
    const fetcher = vi.fn(
      () =>
        new Promise<string>((resolve) => {
          release = resolve
        }),
    )

    render(<Probe fetcher={fetcher} />)
    await act(async () => {
      release?.('company one: ₹12,50,000.55')
    })
    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('company one: ₹12,50,000.55'))

    await act(async () => {
      setScope({ cmp_id: 2, fy_id: 3, bo_id: 0 })
    })

    // Not the old figure, and not an error: a skeleton while the new answer loads.
    expect(screen.getByTestId('state').textContent).toBe('loading')
  })

  it('never paints a stale-scope rejection as an error', async () => {
    setScope({ cmp_id: 1, fy_id: 3, bo_id: 0 })

    const fetcher = vi.fn(async () => {
      throw new StaleScopeError()
    })

    render(<Probe fetcher={fetcher} />)

    await waitFor(() => expect(fetcher).toHaveBeenCalled())
    await waitFor(() => expect(screen.getByTestId('state').textContent).not.toBe('loading'))
    expect(screen.getByTestId('state').textContent).toBe('empty')
  })

  it('never paints an abort as an error', async () => {
    setScope({ cmp_id: 1, fy_id: 3, bo_id: 0 })

    const fetcher = vi.fn(async () => {
      throw new DOMException('signal is aborted without reason', 'AbortError')
    })

    render(<Probe fetcher={fetcher} />)

    await waitFor(() => expect(fetcher).toHaveBeenCalled())
    await waitFor(() => expect(screen.getByTestId('state').textContent).not.toBe('loading'))
    expect(screen.getByTestId('state').textContent).toBe('empty')
  })

  it('still reports a real failure', async () => {
    setScope({ cmp_id: 1, fy_id: 3, bo_id: 0 })

    const fetcher = vi.fn(async () => {
      throw new Error('Books did not answer in time.')
    })

    render(<Probe fetcher={fetcher} />)

    await waitFor(() =>
      expect(screen.getByTestId('state').textContent).toBe('error:Books did not answer in time.'),
    )
  })
})
