import { Info } from 'lucide-react'
import { Badge, DeniedState, EmptyState, ErrorState, LoadingState, UnavailableState, WarningList } from '../ui'
import { ColumnChart, DonutChart, ForecastChart, LineChart, RankingChart } from '../ui/charts'
import { SourceStatusBar } from './SourceBadge'
import type { MetricValue, RenderedWidget, SourceRow } from '../services/types'


/**
 * A single-column body that may be NARROWER THAN ITS CONTENT.
 *
 * A plain `display: grid` creates an implicit `auto` column, and an auto track
 * is sized to the widest thing in it. In a three-column card on a laptop that
 * means a rupee total lays the track out at its own full width and the figure
 * is printed over the widget beside it — both unreadable. `minmax(0, 1fr)` lets
 * the column shrink, and `overflow-wrap: anywhere` on the value (see
 * insights-ui.css) lets the figure wrap inside it instead.
 */
const stacked = { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr)' } as const

/**
 * One widget, rendered from what the server answered.
 *
 * EVERY WIDGET HAS FIVE STATES AND ALL FIVE ARE HERE: loading, empty, error,
 * denied and unavailable. They are not interchangeable, and collapsing them is
 * how a dashboard lies — "denied" means this viewer may not see it, which is a
 * correct and permanent answer; "unavailable" means nobody could read it right
 * now, which may fix itself; "empty" means the source answered and there was
 * nothing there.
 *
 * A widget on a SHARED dashboard whose viewer lacks the source permission
 * renders as a denied panel WITH THE LAYOUT INTACT. That is the intended
 * behaviour: the configuration was shared, the data was not.
 */

function unitLabel(metric: MetricValue | undefined): string {
  if (!metric) return ''
  switch (metric.unit) {
    case 'currency':
      return metric.currency ?? 'INR'
    case 'percent':
      return '%'
    case 'percentage_points':
      return 'pp'
    case 'days':
      return 'days'
    case 'count':
      return 'count'
    default:
      return metric.unit
  }
}

export function WidgetBody({
  rendered,
  loading,
  onOpenEvidence,
  dashboardSources,
}: {
  rendered: RenderedWidget | undefined
  loading: boolean
  onOpenEvidence?: (metric: MetricValue) => void
  dashboardSources?: SourceRow[]
}) {
  if (loading && !rendered) return <LoadingState rows={2} />

  if (!rendered) {
    return <EmptyState title="Not loaded" detail="This widget has not been asked for yet." />
  }

  if (rendered.status === 'error') {
    return <ErrorState title="This widget is misconfigured" detail={rendered.message} />
  }

  if (rendered.widget_type === 'text') {
    // Rendered as text, never as markup. A "rich text" widget on a shared
    // dashboard is a stored cross-site script with a friendly name.
    return (
      <p style={{ margin: 0, fontSize: 13.5, lineHeight: 1.65, whiteSpace: 'pre-wrap' }}>{rendered.text}</p>
    )
  }

  if (rendered.widget_type === 'source_status') {
    return <SourceStatusBar sources={rendered.sources ?? dashboardSources ?? []} compact />
  }

  if (rendered.widget_type === 'forecast') {
    return <ForecastBody rendered={rendered} />
  }

  if (rendered.widget_type === 'ai_summary') {
    return <SummaryBody rendered={rendered} onOpenEvidence={onOpenEvidence} />
  }

  if (rendered.status === 'unavailable') {
    return <UnavailableState detail={rendered.message} />
  }

  const metric = rendered.metric
  if (!metric) {
    return <UnavailableState detail={rendered.message} />
  }

  if (metric.status === 'denied') {
    return <DeniedState detail={metric.message ?? undefined} />
  }

  if (metric.status === 'unavailable') {
    return <UnavailableState detail={metric.message ?? undefined} />
  }

  if (metric.status === 'not_applicable') {
    return (
      <EmptyState
        title="Not applicable"
        detail={metric.message ?? 'This calculation has no answer for this period — usually a zero denominator.'}
      />
    )
  }

  switch (rendered.widget_type) {
    case 'kpi':
      return <KpiBody metric={metric} onOpenEvidence={onOpenEvidence} />

    case 'comparison':
      return <ComparisonBody metric={metric} />

    case 'line':
    case 'area':
    case 'bar':
      return <SeriesBody rendered={rendered} metric={metric} />

    case 'ranking':
    case 'stacked_bar':
      return metric.breakdown.length > 0 ? (
        <>
          <RankingChart title={rendered.title || metric.label} unitLabel={unitLabel(metric)} points={metric.breakdown} />
          <WarningList warnings={metric.warnings} />
        </>
      ) : (
        <EmptyState title="Nothing in this breakdown" detail="The source answered and returned no rows for this period." />
      )

    case 'donut':
      return metric.breakdown.length > 0 ? (
        <>
          <DonutChart
            title={rendered.title || metric.label}
            unitLabel={unitLabel(metric)}
            points={metric.breakdown}
            centreLabel={metric.label}
            centreValue={metric.formatted}
          />
          <WarningList warnings={metric.warnings} />
        </>
      ) : (
        <EmptyState title="Nothing to split" detail="The source answered and returned no rows for this period." />
      )

    case 'ageing':
      return metric.breakdown.length > 0 ? (
        <>
          <RankingChart title={rendered.title || metric.label} unitLabel={unitLabel(metric)} points={metric.breakdown} tone="amber" />
          <WarningList warnings={metric.warnings} />
        </>
      ) : (
        <EmptyState title="No ageing to show" detail={metric.message ?? 'The source did not return an ageing split.'} />
      )

    case 'table':
      return <TableBody rendered={rendered} metric={metric} />

    default:
      return <ErrorState title="Unknown widget" detail={`Insights does not know how to draw "${rendered.widget_type}".`} />
  }
}

function KpiBody({ metric, onOpenEvidence }: { metric: MetricValue; onOpenEvidence?: (metric: MetricValue) => void }) {
  const comparison = metric.comparison
  const tone =
    !comparison || comparison.change === null || comparison.direction === 'flat' || comparison.better_when === 'neutral'
      ? 'neutral'
      : (comparison.direction === 'up') === (comparison.better_when === 'up')
        ? 'ready'
        : 'danger'

  return (
    <div style={{ ...stacked, gap: 7 }}>
      <p className="insights-widget__value">{metric.formatted}</p>
      {comparison && comparison.change !== null ? (
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <Badge tone={tone}>{comparison.change_formatted}</Badge>
          <span className="insights-muted" style={{ fontSize: 12 }}>
            {comparison.formatted} {metric.period.comparison_label}
          </span>
        </div>
      ) : null}
      {metric.target ? (
        <p className="insights-muted" style={{ fontSize: 11.5, margin: 0 }}>
          Target {metric.target_formatted}
        </p>
      ) : null}
      {metric.status === 'partial' ? <Badge tone="warn">Part of the data</Badge> : null}
      <WarningList warnings={metric.warnings} />
      {onOpenEvidence ? (
        <button
          type="button"
          className="insights-button insights-button--quiet"
          style={{ justifySelf: 'start' }}
          onClick={() => onOpenEvidence(metric)}
        >
          <Info size={12} aria-hidden /> Where this came from
        </button>
      ) : null}
    </div>
  )
}

function ComparisonBody({ metric }: { metric: MetricValue }) {
  const comparison = metric.comparison

  return (
    <div style={{ ...stacked, gap: 12 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))', gap: 12 }}>
        <div>
          <p className="insights-muted" style={{ margin: 0, fontSize: 12 }}>
            {metric.period.label}
          </p>
          <p className="insights-widget__value" style={{ fontSize: 22 }}>
            {metric.formatted}
          </p>
        </div>
        <div>
          <p className="insights-muted" style={{ margin: 0, fontSize: 12 }}>
            {metric.period.comparison_label}
          </p>
          <p className="insights-widget__value" style={{ fontSize: 22, color: 'var(--ix-muted)' }}>
            {comparison?.formatted ?? '—'}
          </p>
        </div>
      </div>
      {comparison && comparison.change !== null ? (
        <p style={{ margin: 0, fontSize: 13 }}>
          <strong>{comparison.change_formatted}</strong>{' '}
          {comparison.change_kind === 'percentage_points' ? 'percentage points' : 'change'}
        </p>
      ) : (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12.5 }}>
          There is no comparable figure for the previous period, so the change cannot be stated.
        </p>
      )}
    </div>
  )
}

function SeriesBody({ rendered, metric }: { rendered: RenderedWidget; metric: MetricValue }) {
  if (metric.series.length === 0) {
    return <EmptyState title="No trend" detail={rendered.message ?? 'The source returned no points for this period.'} />
  }

  const title = rendered.title || metric.label
  const chart = rendered.chart_type ?? rendered.widget_type

  return (
    <>
      {chart === 'bar' ? (
        <ColumnChart title={title} unitLabel={unitLabel(metric)} points={metric.series} />
      ) : (
        <LineChart title={title} unitLabel={unitLabel(metric)} points={metric.series} filled={chart === 'area'} />
      )}
      <WarningList warnings={metric.warnings} />
    </>
  )
}

function TableBody({ rendered, metric }: { rendered: RenderedWidget; metric: MetricValue }) {
  if (metric.breakdown.length === 0) {
    return <EmptyState title="Nothing to list" detail={rendered.message ?? 'The source returned no rows for this period.'} />
  }

  return (
    <div className="insights-table-scroll">
      <table className="insights-table">
        <caption className="insights-sr-only">
          {rendered.title || metric.label} by {rendered.dimension}
        </caption>
        <thead>
          <tr>
            <th scope="col">{rendered.dimension ?? 'Entry'}</th>
            <th scope="col" className="is-numeric">
              {metric.label}
            </th>
          </tr>
        </thead>
        <tbody>
          {metric.breakdown.map((row) => (
            <tr key={row.key}>
              <th scope="row" style={{ fontWeight: 500 }}>
                {row.label}
              </th>
              <td className="is-numeric">{row.formatted}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <WarningList warnings={metric.warnings} />
    </div>
  )
}

function ForecastBody({ rendered }: { rendered: RenderedWidget }) {
  if (rendered.status !== 'ok' || !rendered.projection || rendered.projection.length === 0) {
    return <UnavailableState detail={rendered.message} />
  }

  return (
    <div style={{ ...stacked, gap: 10 }}>
      <ForecastChart
        title={rendered.title}
        unitLabel="₹"
        history={(rendered.history ?? []).map((point) => ({ ...point, partial: false }))}
        projection={(rendered.projection ?? []).map((point) => ({
          ...point,
          partial: false,
          scenarioFormatted: point.scenario_formatted ?? null,
        }))}
        scenarioLabel={rendered.scenario?.label ?? null}
      />
      {rendered.method ? (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12, lineHeight: 1.55 }}>
          <strong>{rendered.method.label}.</strong> {rendered.method.assumption}
        </p>
      ) : null}
      {rendered.accuracy ? (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12 }}>
          Backtested: out by {rendered.accuracy.mape_formatted} on average. {rendered.accuracy.note}
        </p>
      ) : null}
      {rendered.scenario ? (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12 }}>
          {rendered.scenario.note}
        </p>
      ) : null}
      <WarningList warnings={rendered.warnings ?? []} />
    </div>
  )
}

/**
 * The grounded summary card.
 *
 * It states the figures and the plain reading of them WITHOUT calling a model.
 * A card that made a model call on every render — and again on every resize and
 * drag — would be slow, expensive and slightly different each time somebody
 * pressed refresh. Ask Insights is where a written answer is requested, once,
 * deliberately.
 */
function SummaryBody({
  rendered,
  onOpenEvidence,
}: {
  rendered: RenderedWidget
  onOpenEvidence?: (metric: MetricValue) => void
}) {
  const metrics = rendered.metrics ?? []

  if (metrics.length === 0) {
    return <EmptyState title="Nothing to summarise" detail="No metric on this card could be read." />
  }

  return (
    <div style={{ ...stacked, gap: 10 }}>
      {metrics.map((metric) => (
        <div
          key={metric.metric_id}
          // Wraps rather than pushing the figure out of the card: this widget is
          // often three columns wide, and a label and a rupee total do not share
          // one line at that width.
          style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'baseline', flexWrap: 'wrap' }}
        >
          <button
            type="button"
            className="insights-button insights-button--quiet"
            style={{ padding: 0, minHeight: 0, fontSize: 12.5, color: 'var(--ix-text)', textAlign: 'left', minWidth: 0 }}
            onClick={onOpenEvidence ? () => onOpenEvidence(metric) : undefined}
          >
            {metric.label}
          </button>
          <span className="num" style={{ fontWeight: 600, fontSize: 13, minWidth: 0 }}>
            {metric.formatted}
            {metric.comparison?.change_formatted && metric.comparison.change !== null ? (
              <span className="insights-muted" style={{ fontWeight: 400, marginLeft: 6, fontSize: 12 }}>
                {metric.comparison.change_formatted}
              </span>
            ) : null}
          </span>
        </div>
      ))}

      {(rendered.missing ?? []).length > 0 ? (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12, lineHeight: 1.5 }}>
          Could not be read: {(rendered.missing ?? []).join(', ')}. Shown as unavailable rather than zero.
        </p>
      ) : null}
    </div>
  )
}
