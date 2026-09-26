import { useCallback, useState } from 'react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { forecasts as forecastsApi, metrics as metricsApi } from '../services/insights'
import { ContextBar } from '../shell/ContextBar'
import { SourceStatusBar } from '../components/SourceBadge'
import { Badge, ErrorState, LoadingState, PageHeader, Panel, PanelHeader, UnavailableState, WarningList } from '../ui'
import { ForecastChart } from '../ui/charts'

/**
 * Forecasts and a cash-flow view.
 *
 * WHAT THIS PAGE REFUSES TO DO is as much the point as what it does. There are
 * no prediction intervals, because neither a moving average nor a straight line
 * through a handful of months supports one honestly. There is no confidence
 * score, because there is no defensible way to produce one and a number between
 * 0 and 100 invites people to read it as a probability. What there is instead:
 * the method, its assumption in a sentence, how far it was out when it was
 * tested against history it had not seen, and the data-quality warnings.
 *
 * A SCENARIO IS LABELLED A SCENARIO wherever it appears. The user's own "what
 * if collections move 10%" is an assumption they chose, and calling it anything
 * else would be dressing an input up as a finding.
 */

const METRICS = [
  { value: 'finance.net_revenue', label: 'Net sales' },
  { value: 'finance.collections', label: 'Collections' },
  { value: 'finance.purchase_spend', label: 'Purchase spend' },
  { value: 'finance.expense', label: 'Expenses' },
]

const METHODS = [
  { value: 'moving_average', label: 'Recent average' },
  { value: 'linear_trend', label: 'Straight-line trend' },
  { value: 'seasonal_naive', label: 'Same period last year' },
]

export default function Forecasts() {
  const { period, refreshToken } = useInsights()

  const [metric, setMetric] = useState('finance.net_revenue')
  const [method, setMethod] = useState('moving_average')
  const [horizon, setHorizon] = useState(3)
  const [scenario, setScenario] = useState('')
  const [view, setView] = useState<'metric' | 'cash'>('metric')

  const loadCatalogue = useCallback((signal: AbortSignal) => metricsApi.catalogue(signal), [])
  const catalogue = useApi(loadCatalogue, [])

  const loadMetric = useCallback(
    (signal: AbortSignal) =>
      forecastsApi.metric({ metric, method, horizon, scenario_percent: scenario || null }, period, signal),
    [metric, method, horizon, scenario, period],
  )

  const loadCash = useCallback(
    (signal: AbortSignal) =>
      forecastsApi.cashFlow({ method, horizon, scenario_percent: scenario || null }, period, signal),
    [method, horizon, scenario, period],
  )

  const forecast = useApi(loadMetric, [metric, method, horizon, scenario, period.preset, period.from, period.to, period.grain, refreshToken], view === 'metric')
  const cash = useApi(loadCash, [method, horizon, scenario, period.preset, period.from, period.to, period.grain, refreshToken], view === 'cash')

  const chartable = (catalogue.data?.metrics ?? []).filter((entry) => !entry.is_balance && entry.owning_product === 'books')

  return (
    <div className="insights-workspace">
      <PageHeader
        title="Forecasts"
        subtitle="Projected from your own posted history, with the method and its assumptions stated."
      />

      <ContextBar busy={forecast.loading || cash.loading} />

      <div className="insights-context">
        <div style={{ display: 'flex', gap: 6 }} role="group" aria-label="What to forecast">
          <button
            type="button"
            className={`insights-chip${view === 'metric' ? ' insights-chip--active' : ''}`}
            aria-pressed={view === 'metric'}
            onClick={() => setView('metric')}
            style={{ cursor: 'pointer' }}
          >
            One metric
          </button>
          <button
            type="button"
            className={`insights-chip${view === 'cash' ? ' insights-chip--active' : ''}`}
            aria-pressed={view === 'cash'}
            onClick={() => setView('cash')}
            style={{ cursor: 'pointer' }}
          >
            Cash flow
          </button>
        </div>

        {view === 'metric' ? (
          <div className="insights-field" style={{ minWidth: 190 }}>
            <label htmlFor="forecast-metric" className="insights-sr-only">
              Metric
            </label>
            <select
              id="forecast-metric"
              className="insights-select"
              value={metric}
              onChange={(event) => setMetric(event.target.value)}
            >
              {(chartable.length > 0
                ? chartable.map((entry) => ({ value: entry.id, label: entry.label }))
                : METRICS
              ).map((entry) => (
                <option key={entry.value} value={entry.value}>
                  {entry.label}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        <div className="insights-field" style={{ minWidth: 180 }}>
          <label htmlFor="forecast-method" className="insights-sr-only">
            Method
          </label>
          <select id="forecast-method" className="insights-select" value={method} onChange={(event) => setMethod(event.target.value)}>
            {METHODS.map((entry) => (
              <option key={entry.value} value={entry.value}>
                {entry.label}
              </option>
            ))}
          </select>
        </div>

        <div className="insights-field" style={{ minWidth: 120 }}>
          <label htmlFor="forecast-horizon" className="insights-sr-only">
            Periods ahead
          </label>
          <input
            id="forecast-horizon"
            type="number"
            min={1}
            max={24}
            className="insights-input"
            value={horizon}
            onChange={(event) => setHorizon(Number(event.target.value) || 3)}
            aria-label="Periods ahead"
          />
        </div>

        <div className="insights-field" style={{ minWidth: 150 }}>
          <label htmlFor="forecast-scenario" className="insights-sr-only">
            Scenario adjustment, percent
          </label>
          <input
            id="forecast-scenario"
            type="number"
            min={-100}
            max={100}
            className="insights-input"
            value={scenario}
            placeholder="Scenario %"
            onChange={(event) => setScenario(event.target.value)}
            aria-label="Scenario adjustment, percent"
          />
        </div>
      </div>

      {view === 'metric' ? (
        <MetricForecast state={forecast} />
      ) : (
        <CashFlowView state={cash} />
      )}
    </div>
  )
}

function MetricForecast({ state }: { state: ReturnType<typeof useApi<Awaited<ReturnType<typeof forecastsApi.metric>>>> }) {
  if (state.loading && !state.data) {
    return (
      <Panel>
        <LoadingState label="Reading your history…" rows={5} />
      </Panel>
    )
  }

  if (state.error) {
    return (
      <Panel>
        <ErrorState detail={state.error} onRetry={state.reload} />
      </Panel>
    )
  }

  const payload = state.data
  if (!payload) return null

  if (payload.status !== 'ok') {
    return (
      <Panel>
        <UnavailableState detail={payload.message} />
      </Panel>
    )
  }

  return (
    <>
      {payload.sources ? <SourceStatusBar sources={payload.sources} /> : null}

      <Panel>
        <PanelHeader
          title={payload.metric?.label ?? 'Forecast'}
          subtitle={`${payload.horizon} ${payload.grain}${(payload.horizon ?? 0) === 1 ? '' : 's'} ahead`}
          actions={<Badge tone="info">{payload.method?.label}</Badge>}
        />

        <ForecastChart
          title={payload.metric?.label ?? 'Forecast'}
          unitLabel="₹"
          history={payload.history.map((point) => ({ ...point, partial: false }))}
          projection={payload.projection.map((point) => ({
            ...point,
            partial: false,
            scenarioFormatted: point.scenario_formatted ?? null,
          }))}
          scenarioLabel={payload.scenario?.label ?? null}
        />

        <div style={{ marginTop: 16, display: 'grid', gap: 10 }}>
          <div>
            <h3 style={{ fontSize: 12.5, margin: '0 0 4px' }}>What this method assumes</h3>
            <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.6 }}>
              {payload.method?.assumption}
            </p>
          </div>

          {payload.accuracy ? (
            <div>
              <h3 style={{ fontSize: 12.5, margin: '0 0 4px' }}>How accurate it has been</h3>
              <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.6 }}>
                Out by <strong>{payload.accuracy.mape_formatted}</strong> on average. {payload.accuracy.note}
              </p>
            </div>
          ) : (
            <div>
              <h3 style={{ fontSize: 12.5, margin: '0 0 4px' }}>How accurate it has been</h3>
              <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.6 }}>
                Not tested — there is not enough history behind this projection to hold some back and score it.
              </p>
            </div>
          )}

          {payload.scenario ? (
            <div>
              <h3 style={{ fontSize: 12.5, margin: '0 0 4px' }}>{payload.scenario.label}</h3>
              <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.6 }}>
                {payload.scenario.note}
              </p>
            </div>
          ) : null}

          <WarningList warnings={payload.warnings} />

          <p className="insights-muted" style={{ margin: 0, fontSize: 11.5, fontStyle: 'italic', lineHeight: 1.55 }}>
            {payload.note}
          </p>
        </div>
      </Panel>
    </>
  )
}

function CashFlowView({ state }: { state: ReturnType<typeof useApi<Awaited<ReturnType<typeof forecastsApi.cashFlow>>>> }) {
  if (state.loading && !state.data) {
    return (
      <Panel>
        <LoadingState label="Reading receipts and payments…" rows={5} />
      </Panel>
    )
  }

  if (state.error) {
    return (
      <Panel>
        <ErrorState detail={state.error} onRetry={state.reload} />
      </Panel>
    )
  }

  const payload = state.data
  if (!payload) return null

  if (payload.status !== 'ok' || payload.rows.length === 0) {
    return (
      <Panel>
        <UnavailableState detail={payload.warnings[0] ?? 'There is not enough history to project a cash flow.'} />
      </Panel>
    )
  }

  return (
    <>
      {payload.sources ? <SourceStatusBar sources={payload.sources} /> : null}

      <Panel className="insights-panel--flush">
        <div style={{ padding: 20, paddingBottom: 0 }}>
          <PanelHeader
            title="Projected cash flow"
            subtitle="Opening balance, expected receipts and expected payments — shown separately, because that is how the question is asked."
            actions={payload.method ? <Badge tone="info">{payload.method.label}</Badge> : null}
          />
        </div>

        <div className="insights-table-scroll">
          <table className="insights-table">
            <caption className="insights-sr-only">Projected cash flow by {payload.grain}</caption>
            <thead>
              <tr>
                <th scope="col">Period</th>
                <th scope="col" className="is-numeric">
                  Opening
                </th>
                <th scope="col" className="is-numeric">
                  Expected in
                </th>
                <th scope="col" className="is-numeric">
                  Expected out
                </th>
                <th scope="col" className="is-numeric">
                  Closing
                </th>
              </tr>
            </thead>
            <tbody>
              {payload.rows.map((row) => (
                <tr key={row.key}>
                  <th scope="row" style={{ fontWeight: 500 }}>
                    {row.label}
                  </th>
                  <td className="is-numeric">{row.opening_formatted}</td>
                  <td className="is-numeric">{row.receipts_formatted}</td>
                  <td className="is-numeric">{row.payments_formatted}</td>
                  <td
                    className="is-numeric"
                    style={{
                      fontWeight: 650,
                      color: row.closing !== null && row.closing.startsWith('-') ? 'var(--danger)' : undefined,
                    }}
                  >
                    {row.closing_formatted}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div style={{ padding: 20, paddingTop: 14 }}>
          <WarningList warnings={payload.warnings} />
          <p className="insights-muted" style={{ margin: '12px 0 0', fontSize: 12, lineHeight: 1.6 }}>
            {payload.note}
          </p>
        </div>
      </Panel>
    </>
  )
}
