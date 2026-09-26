import { useMemo } from 'react'
import { MetricPicker } from './MetricPicker'
import { Button } from '../ui'
import type { MetricCatalogue } from '../services/insights'
import type { MetricDefinitionRow, Widget, WidgetConfig } from '../services/types'

/**
 * The properties panel: what one widget shows.
 *
 * EVERY CONTROL HERE MAPS TO A FIELD THE BACKEND VALIDATES, and the panel only
 * offers what the chosen metric and widget type actually support — the
 * dimension list comes from the metric, the grain list comes from the metric,
 * and a metric that is a balance is not offered to a chart-over-time widget at
 * all. A builder that lets somebody configure something the server will refuse
 * is a builder that teaches people to distrust Save.
 *
 * There is deliberately NO free-text field for a query, a URL or any markup.
 * The only free text is a title, a description and the note widget's own text,
 * and all three are rendered as text.
 */
export function WidgetProperties({
  widget,
  catalogue,
  onChange,
  onRemove,
  onDuplicate,
}: {
  widget: Widget
  catalogue: MetricCatalogue
  onChange: (next: Widget) => void
  onRemove: () => void
  onDuplicate: () => void
}) {
  const spec = catalogue.widget_types[widget.widget_type]
  const config = widget.config ?? {}

  const allMetrics = useMemo(
    () => [...catalogue.custom, ...catalogue.metrics],
    [catalogue.custom, catalogue.metrics],
  )
  const metric = allMetrics.find((candidate) => candidate.id === config.metric_id)

  // A series widget cannot chart a balance: a closing receivable is a level at
  // a date, and a line through a set of month-ends is not a trend. The backend
  // refuses it, so the picker does not offer it.
  const metricFilter = useMemo(() => {
    if (!spec?.series || widget.widget_type === 'forecast') return undefined
    return (candidate: MetricDefinitionRow) => !candidate.is_balance
  }, [spec?.series, widget.widget_type])

  function set(patch: Partial<WidgetConfig>) {
    onChange({ ...widget, config: { ...config, ...patch } })
  }

  const dimensions = metric?.dimensions ?? []
  const grains = metric?.grains ?? catalogue.grains

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div className="insights-field">
        <label htmlFor="widget-title">Title</label>
        <input
          id="widget-title"
          className="insights-input"
          value={widget.title}
          maxLength={120}
          onChange={(event) => onChange({ ...widget, title: event.target.value })}
        />
      </div>

      <div className="insights-field">
        <label htmlFor="widget-description">Description</label>
        <input
          id="widget-description"
          className="insights-input"
          value={widget.description}
          maxLength={400}
          placeholder="Optional — shown under the title"
          onChange={(event) => onChange({ ...widget, description: event.target.value })}
        />
      </div>

      <div className="insights-field">
        <label htmlFor="widget-type">Widget type</label>
        <select
          id="widget-type"
          className="insights-select"
          value={widget.widget_type}
          onChange={(event) => onChange({ ...widget, widget_type: event.target.value })}
        >
          {Object.entries(catalogue.widget_types).map(([type, entry]) => (
            <option key={type} value={type}>
              {entry.label}
            </option>
          ))}
        </select>
        {spec ? <span className="insights-hint">{spec.description}</span> : null}
      </div>

      {widget.widget_type === 'text' ? (
        <div className="insights-field">
          <label htmlFor="widget-text">Note</label>
          <textarea
            id="widget-text"
            className="insights-textarea"
            value={config.text ?? ''}
            maxLength={2000}
            onChange={(event) => set({ text: event.target.value })}
          />
          <span className="insights-hint">Shown as plain text. Formatting and links are not rendered.</span>
        </div>
      ) : null}

      {spec?.needs_metric ? (
        <div className="insights-field">
          <label htmlFor="widget-metric">Metric</label>
          <MetricPicker
            id="widget-metric"
            metrics={catalogue.metrics}
            custom={catalogue.custom}
            value={config.metric_id}
            onChange={(metricId) => set({ metric_id: metricId, dimension: undefined })}
            filter={metricFilter}
          />
          {metric ? (
            <span className="insights-hint">
              {metric.definition} Owned by {metric.owning_product}; definition version {metric.formula_version}.
            </span>
          ) : null}
        </div>
      ) : null}

      {spec?.needs_dimension ? (
        <div className="insights-field">
          <label htmlFor="widget-dimension">Split by</label>
          <select
            id="widget-dimension"
            className="insights-select"
            value={config.dimension ?? ''}
            onChange={(event) => set({ dimension: event.target.value || undefined })}
            disabled={dimensions.length === 0}
          >
            <option value="">Choose a dimension</option>
            {dimensions.map((dimension) => (
              <option key={dimension} value={dimension}>
                {catalogue.dimensions[dimension] ?? dimension}
              </option>
            ))}
          </select>
          {dimensions.length === 0 ? (
            <span className="insights-hint">
              {metric
                ? `${metric.label} is not reported by any dimension, so it cannot be split.`
                : 'Choose a metric first — the dimensions available depend on it.'}
            </span>
          ) : null}
        </div>
      ) : null}

      {spec?.series ? (
        <div className="insights-field">
          <label htmlFor="widget-grain">Group by</label>
          <select
            id="widget-grain"
            className="insights-select"
            value={config.grain ?? 'month'}
            onChange={(event) => set({ grain: event.target.value })}
          >
            {grains.map((grain) => (
              <option key={grain} value={grain}>
                {grain}
              </option>
            ))}
          </select>
          {metric && metric.grains.length < catalogue.grains.length ? (
            <span className="insights-hint">{metric.label} is only reported by {metric.grains.join(', ')}.</span>
          ) : null}
        </div>
      ) : null}

      {spec?.needs_metric ? (
        <div className="insights-field">
          <label htmlFor="widget-comparison">Compare against</label>
          <select
            id="widget-comparison"
            className="insights-select"
            value={config.comparison ?? 'previous_period'}
            onChange={(event) => set({ comparison: event.target.value })}
          >
            <option value="previous_period">The period before</option>
            <option value="previous_year">The same period last year</option>
            <option value="none">No comparison</option>
          </select>
        </div>
      ) : null}

      {['ranking', 'table', 'donut'].includes(widget.widget_type) ? (
        <>
          <div className="insights-field">
            <label htmlFor="widget-limit">Rows</label>
            <input
              id="widget-limit"
              type="number"
              min={1}
              max={100}
              className="insights-input"
              value={config.limit ?? 10}
              onChange={(event) => set({ limit: Number(event.target.value) || 10 })}
            />
          </div>

          <div className="insights-field">
            <label htmlFor="widget-order">Order</label>
            <select
              id="widget-order"
              className="insights-select"
              value={config.order ?? 'desc'}
              onChange={(event) => set({ order: event.target.value })}
            >
              <option value="desc">Largest first</option>
              <option value="asc">Smallest first</option>
            </select>
          </div>
        </>
      ) : null}

      {widget.widget_type === 'forecast' ? (
        <>
          <div className="insights-field">
            <label htmlFor="widget-method">Method</label>
            <select
              id="widget-method"
              className="insights-select"
              value={config.method ?? 'moving_average'}
              onChange={(event) => set({ method: event.target.value })}
            >
              <option value="moving_average">Recent average</option>
              <option value="linear_trend">Straight-line trend</option>
              <option value="seasonal_naive">Same period last year</option>
            </select>
            <span className="insights-hint">The assumption behind each method is stated on the widget.</span>
          </div>

          <div className="insights-field">
            <label htmlFor="widget-horizon">Periods ahead</label>
            <input
              id="widget-horizon"
              type="number"
              min={1}
              max={24}
              className="insights-input"
              value={config.horizon ?? 3}
              onChange={(event) => set({ horizon: Number(event.target.value) || 3 })}
            />
          </div>

          <div className="insights-field">
            <label htmlFor="widget-scenario">Scenario adjustment (%)</label>
            <input
              id="widget-scenario"
              type="number"
              min={-100}
              max={100}
              step={1}
              className="insights-input"
              value={config.scenario_adjustment_percent ?? ''}
              placeholder="None"
              onChange={(event) => set({ scenario_adjustment_percent: event.target.value || null })}
            />
            <span className="insights-hint">
              A what-if you chose. It is labelled a scenario wherever it appears — it is not a confidence band.
            </span>
          </div>
        </>
      ) : null}

      {['line', 'area', 'bar', 'stacked_bar', 'ranking', 'donut'].includes(widget.widget_type) ? (
        <div className="insights-field">
          <label htmlFor="widget-tone">Colour</label>
          <select
            id="widget-tone"
            className="insights-select"
            value={config.tone ?? 'brand'}
            onChange={(event) => set({ tone: event.target.value })}
          >
            <option value="brand">Green (brand)</option>
            <option value="teal">Teal</option>
            <option value="indigo">Indigo</option>
            <option value="amber">Amber</option>
            <option value="rose">Rose</option>
            <option value="slate">Slate</option>
          </select>
          <span className="insights-hint">All six clear contrast on white and stay distinguishable in print.</span>
        </div>
      ) : null}

      {spec?.needs_metric && widget.widget_type === 'kpi' ? (
        <div className="insights-field">
          <label htmlFor="widget-target">Target</label>
          <input
            id="widget-target"
            className="insights-input"
            inputMode="decimal"
            value={config.target ?? ''}
            placeholder="Optional"
            onChange={(event) => set({ target: event.target.value || null, show_target: Boolean(event.target.value) })}
          />
        </div>
      ) : null}

      <div className="insights-field">
        <label className="insights-checkbox" htmlFor="widget-own-period">
          <input
            id="widget-own-period"
            type="checkbox"
            checked={Boolean(config.period_override?.enabled)}
            onChange={(event) =>
              set({
                period_override: event.target.checked
                  ? { enabled: true, preset: config.period_override?.preset ?? 'last_month' }
                  : null,
              })
            }
          />
          Use its own period
        </label>
        <span className="insights-hint">
          The card says so on its face when it does — a panel quietly showing a different window is how two figures
          that are not comparable end up side by side.
        </span>
      </div>

      {config.period_override?.enabled ? (
        <div className="insights-field">
          <label htmlFor="widget-own-preset">This widget's period</label>
          <select
            id="widget-own-preset"
            className="insights-select"
            value={config.period_override.preset ?? 'last_month'}
            onChange={(event) => set({ period_override: { enabled: true, preset: event.target.value } })}
          >
            <option value="this_month">This month</option>
            <option value="last_month">Last month</option>
            <option value="last_30_days">Last 30 days</option>
            <option value="last_90_days">Last 90 days</option>
            <option value="this_quarter">This quarter</option>
            <option value="this_year">This calendar year</option>
            <option value="financial_year">This financial year</option>
          </select>
        </div>
      ) : null}

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', paddingTop: 6, borderTop: '1px solid var(--ix-border)' }}>
        <Button onClick={onDuplicate}>Duplicate</Button>
        <Button variant="danger" onClick={onRemove}>
          Remove widget
        </Button>
      </div>
    </div>
  )
}
