import { useMemo, useState } from 'react'
import { Search } from 'lucide-react'
import { Badge } from '../ui'
import type { MetricDefinitionRow } from '../services/types'

/**
 * Choosing a metric.
 *
 * THE LIST IS THE CATALOGUE, and nothing else can be typed in. A builder that
 * accepted a free-text metric id would produce widgets that save happily and
 * render an apology, so the picker only ever offers what the backend will
 * accept — including this firm's own KPIs, which sit at the top because
 * somebody defined them for a reason.
 *
 * Each entry shows WHO OWNS IT and WHAT IT COUNTS, because "sales" means three
 * different things across a fleet and picking the wrong one is the most
 * expensive mistake available on this screen.
 */
export function MetricPicker({
  metrics,
  custom,
  value,
  onChange,
  filter,
  id,
}: {
  metrics: MetricDefinitionRow[]
  custom: MetricDefinitionRow[]
  value: string | undefined
  onChange: (metricId: string) => void
  /** Narrow the list — a series widget cannot chart a balance. */
  filter?: (metric: MetricDefinitionRow) => boolean
  id?: string
}) {
  const [search, setSearch] = useState('')

  const all = useMemo(() => [...custom, ...metrics], [custom, metrics])
  const allowed = useMemo(() => (filter ? all.filter(filter) : all), [all, filter])

  const shown = useMemo(() => {
    const term = search.trim().toLowerCase()
    if (!term) return allowed

    return allowed.filter(
      (metric) =>
        metric.label.toLowerCase().includes(term) ||
        metric.id.toLowerCase().includes(term) ||
        metric.definition.toLowerCase().includes(term),
    )
  }, [allowed, search])

  const selected = all.find((metric) => metric.id === value)
  // A metric that was valid when the widget was built and is not offered now —
  // because the widget type changed, or the KPI was retired. Saying so beats
  // silently clearing the field.
  const selectedButFiltered = selected && !allowed.some((metric) => metric.id === selected.id)

  return (
    <div style={{ display: 'grid', gap: 8 }}>
      <div style={{ position: 'relative' }}>
        <Search
          size={14}
          aria-hidden
          style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--ix-muted)' }}
        />
        <input
          id={id}
          type="search"
          className="insights-input"
          style={{ paddingLeft: 30 }}
          placeholder="Search metrics"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          aria-label="Search metrics"
        />
      </div>

      {selectedButFiltered ? (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12, lineHeight: 1.5 }}>
          This widget currently uses <strong>{selected.label}</strong>, which this widget type cannot show. Pick another
          metric, or change the widget type back.
        </p>
      ) : null}

      <div
        role="listbox"
        aria-label="Metrics"
        style={{
          maxHeight: 280,
          overflowY: 'auto',
          border: '1px solid var(--ix-border)',
          borderRadius: 9,
          display: 'grid',
        }}
      >
        {shown.length === 0 ? (
          <p className="insights-muted" style={{ margin: 0, padding: 14, fontSize: 12.5 }}>
            Nothing matches. The catalogue only lists metrics a connected product can actually answer.
          </p>
        ) : null}

        {shown.map((metric) => (
          <button
            key={metric.id}
            type="button"
            role="option"
            aria-selected={metric.id === value}
            onClick={() => onChange(metric.id)}
            style={{
              display: 'grid',
              gap: 3,
              textAlign: 'left',
              padding: '9px 11px',
              border: 0,
              borderBottom: '1px solid var(--ix-border)',
              background: metric.id === value ? 'var(--ix-green-soft)' : 'transparent',
              cursor: 'pointer',
              font: 'inherit',
            }}
          >
            <span style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
              <strong style={{ fontSize: 13 }}>{metric.label}</strong>
              <Badge tone={metric.is_custom ? 'info' : 'neutral'}>
                {metric.is_custom ? 'Your KPI' : metric.owning_product}
              </Badge>
              {metric.is_balance ? <Badge tone="neutral">Balance</Badge> : null}
            </span>
            <span className="insights-muted" style={{ fontSize: 11.5, lineHeight: 1.45 }}>
              {metric.definition}
            </span>
          </button>
        ))}
      </div>
    </div>
  )
}
