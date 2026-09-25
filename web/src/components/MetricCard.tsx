import { ArrowDownRight, ArrowRight, ArrowUpRight, Info } from 'lucide-react'
import { Badge } from '../ui'
import { Sparkline } from '../ui/charts'
import type { MetricValue } from '../services/types'

/**
 * One figure, with its change and a way to see where it came from.
 *
 * THE VALUE IS THE SERVER'S FORMATTED STRING. It is never re-derived from the
 * exact decimal, because doing that in JavaScript means parsing it to a float.
 *
 * AN UNAVAILABLE FIGURE IS NOT A ZERO, and this card refuses to let it look
 * like one: there is no "—" standing in for a number, no faded zero, and no
 * empty space. It says what happened and, where there is one, what to do.
 *
 * WHICH WAY IS GOOD DEPENDS ON THE METRIC. Overdue receivables falling is a
 * win; sales falling is not. The direction arrow and the tone both come from
 * the metric's own `better_when`, so the card never congratulates somebody on
 * a collapse.
 */
export function MetricCard({
  metric,
  onOpenEvidence,
  sparkline,
}: {
  metric: MetricValue
  onOpenEvidence?: (metric: MetricValue) => void
  sparkline?: MetricValue | null
}) {
  const comparison = metric.comparison
  const available = metric.status === 'available' || metric.status === 'partial'

  const tone =
    !comparison || comparison.change === null || comparison.direction === 'flat' || comparison.better_when === 'neutral'
      ? 'neutral'
      : (comparison.direction === 'up') === (comparison.better_when === 'up')
        ? 'ready'
        : 'danger'

  const DirectionIcon =
    comparison?.direction === 'up' ? ArrowUpRight : comparison?.direction === 'down' ? ArrowDownRight : ArrowRight

  return (
    <article className="insights-widget">
      <div className="insights-widget__head">
        <h3 className="insights-widget__title">{metric.label}</h3>
        {onOpenEvidence ? (
          <button
            type="button"
            className="insights-button insights-button--quiet"
            onClick={() => onOpenEvidence(metric)}
            aria-label={`Where ${metric.label} comes from`}
            title={`Where ${metric.label} comes from`}
          >
            <Info size={14} aria-hidden />
          </button>
        ) : null}
      </div>

      {available ? (
        <p className="insights-widget__value">{metric.formatted}</p>
      ) : (
        <p className="insights-widget__value insights-widget__value--empty">{metric.formatted}</p>
      )}

      {available && comparison && comparison.change !== null ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <Badge tone={tone}>
            <DirectionIcon size={12} aria-hidden />
            {comparison.change_formatted}
          </Badge>
          <span className="insights-muted" style={{ fontSize: 12 }}>
            {comparison.formatted} {metric.period.comparison_label}
          </span>
        </div>
      ) : null}

      {!available && metric.message ? (
        <p className="insights-muted" style={{ fontSize: 12, margin: 0, lineHeight: 1.5 }}>
          {metric.message}
        </p>
      ) : null}

      {metric.status === 'partial' ? <Badge tone="warn">Part of the data</Badge> : null}

      {sparkline && sparkline.series.length > 1 ? (
        <Sparkline points={sparkline.series} tone={tone === 'danger' ? 'rose' : 'brand'} />
      ) : null}

      {metric.target ? (
        <p className="insights-muted" style={{ fontSize: 11.5, margin: 0 }}>
          Target {metric.target_formatted}
        </p>
      ) : null}
    </article>
  )
}
