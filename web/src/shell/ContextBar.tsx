/**
 * The period every screen answers for, and the filters narrowing it.
 *
 * ONE BAR, ONE PERIOD. The window lives in the app context rather than on each
 * page, so moving from the overview to a dashboard keeps the question the same.
 * Changing it here changes what every panel below is showing — which is why the
 * comparison is stated in words rather than implied.
 *
 * Refresh is explicit. A screen that reloads itself on a timer is a screen that
 * moves under somebody reading it, and one that fans out to five products on a
 * timer is a screen that costs five products a request per user per tick.
 */

import { CalendarRange, RefreshCw } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { Button } from '../ui'

const PRESETS: { value: string; label: string }[] = [
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'last_7_days', label: 'Last 7 days' },
  { value: 'last_30_days', label: 'Last 30 days' },
  { value: 'last_90_days', label: 'Last 90 days' },
  { value: 'this_quarter', label: 'This quarter' },
  { value: 'last_quarter', label: 'Last quarter' },
  { value: 'this_year', label: 'This calendar year' },
  { value: 'financial_year', label: 'This financial year' },
  { value: 'custom', label: 'Custom dates' },
]

const GRAINS: { value: string; label: string }[] = [
  { value: 'day', label: 'Daily' },
  { value: 'week', label: 'Weekly' },
  { value: 'month', label: 'Monthly' },
  { value: 'quarter', label: 'Quarterly' },
  { value: 'year', label: 'Yearly' },
]

const COMPARISONS: { value: string; label: string }[] = [
  { value: 'previous_period', label: 'vs the period before' },
  { value: 'previous_year', label: 'vs the same period last year' },
  { value: 'none', label: 'No comparison' },
]

export function ContextBar({
  busy = false,
  showGrain = true,
  extra,
}: {
  busy?: boolean
  showGrain?: boolean
  extra?: React.ReactNode
}) {
  const { period, setPeriod, refresh } = useInsights()

  return (
    <div className="insights-context" role="group" aria-label="Period and comparison">
      <CalendarRange size={16} aria-hidden style={{ color: 'var(--ix-muted)', flex: 'none' }} />

      <div className="insights-field" style={{ minWidth: 150 }}>
        <label htmlFor="context-preset" className="insights-sr-only">
          Period
        </label>
        <select
          id="context-preset"
          className="insights-select"
          value={period.preset}
          onChange={(event) => setPeriod({ preset: event.target.value })}
        >
          {PRESETS.map((preset) => (
            <option key={preset.value} value={preset.value}>
              {preset.label}
            </option>
          ))}
        </select>
      </div>

      {period.preset === 'custom' ? (
        <>
          <div className="insights-field" style={{ minWidth: 140 }}>
            <label htmlFor="context-from" className="insights-sr-only">
              From
            </label>
            <input
              id="context-from"
              type="date"
              className="insights-input"
              value={period.from ?? ''}
              max={period.to ?? undefined}
              onChange={(event) => setPeriod({ from: event.target.value || null })}
            />
          </div>
          <div className="insights-field" style={{ minWidth: 140 }}>
            <label htmlFor="context-to" className="insights-sr-only">
              To
            </label>
            <input
              id="context-to"
              type="date"
              className="insights-input"
              value={period.to ?? ''}
              min={period.from ?? undefined}
              onChange={(event) => setPeriod({ to: event.target.value || null })}
            />
          </div>
        </>
      ) : null}

      {showGrain ? (
        <div className="insights-field" style={{ minWidth: 130 }}>
          <label htmlFor="context-grain" className="insights-sr-only">
            Group by
          </label>
          <select
            id="context-grain"
            className="insights-select"
            value={period.grain}
            onChange={(event) => setPeriod({ grain: event.target.value })}
          >
            {GRAINS.map((grain) => (
              <option key={grain.value} value={grain.value}>
                {grain.label}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      <div className="insights-field" style={{ minWidth: 190 }}>
        <label htmlFor="context-compare" className="insights-sr-only">
          Compare against
        </label>
        <select
          id="context-compare"
          className="insights-select"
          value={period.compare}
          onChange={(event) => setPeriod({ compare: event.target.value })}
        >
          {COMPARISONS.map((comparison) => (
            <option key={comparison.value} value={comparison.value}>
              {comparison.label}
            </option>
          ))}
        </select>
      </div>

      {extra}

      <Button onClick={refresh} busy={busy} style={{ marginLeft: 'auto' }}>
        <RefreshCw size={14} aria-hidden />
        {busy ? 'Refreshing…' : 'Refresh data'}
      </Button>
    </div>
  )
}
