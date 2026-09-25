import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { CheckCircle2, CircleSlash, Info, TriangleAlert } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { overview as overviewApi } from '../services/insights'
import { isStale } from '../services/api'
import { ContextBar } from '../shell/ContextBar'
import { MetricCard } from '../components/MetricCard'
import { EvidenceDrawer, buildDrilldownHref } from '../components/EvidenceDrawer'
import { SourceStatusBar } from '../components/SourceBadge'
import { Button, EmptyState, ErrorState, LoadingState, PageHeader, Panel, PanelHeader } from '../ui'
import { ColumnChart, LineChart, RankingChart } from '../ui/charts'
import type { MetricValue, Observation } from '../services/types'

/**
 * The executive overview.
 *
 * ONE CALL DRAWS THIS PAGE. The backend fans out to Books and Inventory in
 * parallel and returns the KPIs, the trends, the ageing and the observations
 * together — so the screen paints once rather than in six stages.
 *
 * THE OBSERVATIONS ARE ARITHMETIC, NOT A MODEL. Each one is derived from
 * figures that are on this same screen and carries the evidence it came from.
 * Every one of them says so on its face, because "sales rose and collections
 * fell" is a fact and "customers are short of cash" is a guess.
 */

const TONE_ICON = {
  positive: CheckCircle2,
  attention: TriangleAlert,
  unavailable: CircleSlash,
} as const

const TONE_COLOUR = {
  positive: 'var(--success)',
  attention: 'var(--warning)',
  unavailable: 'var(--ix-muted)',
} as const

export default function Overview() {
  const { period, refreshToken, can } = useInsights()
  const navigate = useNavigate()
  const [evidence, setEvidence] = useState<MetricValue | null>(null)

  const load = useCallback((signal: AbortSignal) => overviewApi.load(period, signal), [period])

  const { data, loading, error, reload } = useApi(load, [period.preset, period.from, period.to, period.grain, period.compare, refreshToken])

  const trendPoints = data?.trends.revenue?.series ?? []
  const collectionPoints = data?.trends.collections?.series ?? []
  const ageing = data?.ageing.receivables

  return (
    <div className="insights-workspace">
      <PageHeader
        title="Business overview"
        subtitle="Explore performance with evidence from your connected apps."
        actions={
          <>
            {can('ai.use') ? <Button onClick={() => navigate('/ask')}>Ask Insights</Button> : null}
            {can('dashboard.create') ? (
              <Button variant="primary" onClick={() => navigate('/dashboards?new=1')}>
                Create dashboard
              </Button>
            ) : null}
          </>
        }
      />

      <ContextBar busy={loading} />

      {error && !isStale(new Error(error)) ? (
        <Panel>
          <ErrorState detail={error} onRetry={reload} />
        </Panel>
      ) : null}

      {data ? <SourceStatusBar sources={data.sources} /> : null}

      {loading && !data ? (
        <Panel>
          <LoadingState label="Reading your connected products…" rows={5} />
        </Panel>
      ) : null}

      {data ? (
        <>
          <div className="insights-kpis">
            {data.headline.map((metric) => (
              <MetricCard
                key={metric.metric_id}
                metric={metric}
                onOpenEvidence={setEvidence}
                sparkline={
                  metric.metric_id === 'finance.net_revenue'
                    ? data.trends.revenue
                    : metric.metric_id === 'finance.collections'
                      ? data.trends.collections
                      : null
                }
              />
            ))}
          </div>

          <div className="insights-main-grid">
            <Panel className="insights-chart">
              <PanelHeader
                title="Sales and collections"
                subtitle={`${data.period.label} · ${data.period.comparison_label}`}
              />
              {trendPoints.length > 0 ? (
                data.period.grain === 'day' || trendPoints.length > 14 ? (
                  <LineChart title="Net sales" unitLabel="₹" points={trendPoints} filled />
                ) : (
                  <ColumnChart title="Net sales" unitLabel="₹" points={trendPoints} />
                )
              ) : (
                <EmptyState
                  title="No trend to draw"
                  detail={
                    data.trends.revenue?.message ??
                    'Smart Books did not return a daily trend for this period. The headline figures above are still live.'
                  }
                />
              )}

              {collectionPoints.length > 0 ? (
                <div style={{ marginTop: 20 }}>
                  <PanelHeader title="Collections" subtitle="Money received, as opposed to invoiced" />
                  <LineChart title="Collections" unitLabel="₹" points={collectionPoints} tone="teal" />
                </div>
              ) : null}
            </Panel>

            <Panel>
              <PanelHeader title="What needs attention" subtitle="Derived from the figures on this page" />
              <ObservationList observations={data.observations} />
            </Panel>
          </div>

          <div className="insights-main-grid" style={{ marginTop: 18 }}>
            <Panel>
              <PanelHeader
                title="Receivables by age"
                subtitle={ageing ? `Balance as at ${data.period.to}` : undefined}
              />
              {ageing && ageing.breakdown.length > 0 ? (
                <RankingChart title="Receivables by age" unitLabel="₹" points={ageing.breakdown} tone="amber" />
              ) : (
                <EmptyState
                  title="Ageing unavailable"
                  detail={ageing?.message ?? 'Smart Books did not return a receivables ageing for this company.'}
                />
              )}
            </Panel>

            <Panel>
              <PanelHeader title="Other figures" />
              <div style={{ display: 'grid', gap: 10 }}>
                {data.secondary.map((metric) => (
                  <SecondaryRow key={metric.metric_id} metric={metric} onOpenEvidence={setEvidence} />
                ))}
              </div>
            </Panel>
          </div>
        </>
      ) : null}

      {evidence ? <EvidenceDrawer metric={evidence} onClose={() => setEvidence(null)} /> : null}
    </div>
  )
}

function SecondaryRow({ metric, onOpenEvidence }: { metric: MetricValue; onOpenEvidence: (metric: MetricValue) => void }) {
  const available = metric.status === 'available' || metric.status === 'partial'

  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'baseline',
        justifyContent: 'space-between',
        gap: 12,
        paddingBottom: 9,
        borderBottom: '1px solid var(--ix-border)',
      }}
    >
      <button
        type="button"
        className="insights-button insights-button--quiet"
        onClick={() => onOpenEvidence(metric)}
        style={{ padding: 0, minHeight: 0, fontSize: 13, color: 'var(--ix-text)', textAlign: 'left' }}
      >
        {metric.label}
        <Info size={12} aria-hidden style={{ color: 'var(--ix-muted)' }} />
      </button>
      <span
        className="num"
        style={{ fontWeight: 600, fontSize: 13.5, color: available ? undefined : 'var(--ix-muted)' }}
      >
        {metric.formatted}
      </span>
    </div>
  )
}

function ObservationList({ observations }: { observations: Observation[] }) {
  if (observations.length === 0) {
    return <EmptyState title="Nothing to report" detail="No observation could be drawn from this period's figures." />
  }

  return (
    <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 16 }}>
      {observations.map((observation, index) => {
        const Icon = TONE_ICON[observation.tone]

        return (
          <li key={index} style={{ display: 'grid', gap: 6 }}>
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}>
              <Icon size={16} aria-hidden style={{ color: TONE_COLOUR[observation.tone], flex: 'none', marginTop: 2 }} />
              <div style={{ minWidth: 0 }}>
                <p style={{ margin: 0, fontWeight: 620, fontSize: 13.5, lineHeight: 1.35 }}>{observation.title}</p>
                <p className="insights-muted" style={{ margin: '4px 0 0', fontSize: 12.5, lineHeight: 1.55 }}>
                  {observation.detail}
                </p>
              </div>
            </div>

            {observation.evidence.length > 0 ? (
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, paddingLeft: 24 }}>
                {observation.evidence.map((item) => {
                  const href = buildDrilldownHref(
                    item.drilldown as { product?: string; route?: string; params?: Record<string, string | number> } | null,
                  )

                  return (
                    <span key={item.metric_id} className="insights-chip">
                      {item.label}: <strong style={{ fontVariantNumeric: 'tabular-nums' }}>{item.formatted}</strong>
                      {href ? (
                        <a href={href} target="_blank" rel="noopener noreferrer" aria-label={`Open ${item.label} in the owning app`}>
                          ↗
                        </a>
                      ) : null}
                    </span>
                  )
                })}
              </div>
            ) : null}

            {observation.next_step ? (
              <p className="insights-muted" style={{ margin: 0, paddingLeft: 24, fontSize: 12 }}>
                {observation.next_step}
              </p>
            ) : null}

            {/* Said on every observation, not once at the top: it is the
                difference between a description and a diagnosis. */}
            <p className="insights-muted" style={{ margin: 0, paddingLeft: 24, fontSize: 11, fontStyle: 'italic' }}>
              {observation.basis}
            </p>
          </li>
        )
      })}
    </ul>
  )
}

export { ObservationList }
