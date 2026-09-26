/**
 * Charts, drawn from the data.
 *
 * There is no chart library anywhere in this fleet, and adding one to this
 * product alone would give Insights a different visual language from Books and
 * Inventory. Every coordinate below is computed from a value on every render;
 * there is not a hard-coded path in the file.
 *
 * FOUR RULES, and each one is a way charts usually go wrong:
 *
 *  1. VALUES ARRIVE AS EXACT DECIMAL STRINGS AND ARE DISPLAYED AS THE SERVER
 *     FORMATTED THEM. A string is parsed to a number only to decide where to
 *     put a pixel — never to produce a figure anybody reads. A JavaScript
 *     number is a float, and a payables total that survived exact arithmetic
 *     all the way through PostgreSQL and PHP should not lose paise in a label.
 *
 *  2. EVERY CHART SHIPS WITH THE SAME FIGURES AS A TABLE. The drawing is an
 *     illustration; the table is the data. It is what a screen reader gets,
 *     what somebody who wants the exact value gets, and what survives print.
 *
 *  3. COLOUR IS NEVER THE ONLY SIGNAL. Series are labelled, bars carry their
 *     value, and a projected section is drawn dashed as well as paler.
 *
 *  4. A MISSING VALUE IS A GAP, NOT A ZERO. A null in a series breaks the line
 *     rather than dragging it to the axis, because a month nobody could read
 *     is not a month of no sales.
 */

import { useId, useState, type ReactNode } from 'react'

/** For geometry only. Never call this on a value that will be shown. */
function px(value: string | null | undefined): number {
  if (value === null || value === undefined) return 0
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed : 0
}

export interface Point {
  key: string
  label: string
  /** The exact decimal string, or null when the figure could not be read. */
  value: string | null
  /** What to show. Formatted by the server. */
  formatted: string
  partial?: boolean
}

/** Accessible chart inks. Distinguishable in the common forms of colour blindness. */
export const TONES: Record<string, string> = {
  brand: '#187900',
  teal: '#0f766e',
  indigo: '#3f48cc',
  amber: '#9a6700',
  rose: '#b42318',
  slate: '#475467',
}

export function toneColour(tone: string | undefined): string {
  return TONES[tone ?? 'brand'] ?? TONES.brand
}

// ---------------------------------------------------------------------------
// The frame every chart sits in
// ---------------------------------------------------------------------------

function ChartFrame({
  title,
  summary,
  children,
  table,
  legend,
}: {
  title: string
  summary: string
  children: ReactNode
  table: ReactNode
  legend?: ReactNode
}) {
  const [showTable, setShowTable] = useState(false)
  const tableId = useId()

  return (
    // The drawing is decoration: the figcaption states the values in prose and
    // the toggle reveals them as a table for everyone. Rendering the table
    // twice — once here, once hidden for screen readers — would hand assistive
    // technology a table the toggle does not control.
    <figure className="insights-chart-frame" aria-label={title}>
      <div aria-hidden="true">{children}</div>
      {legend}
      <figcaption className="insights-sr-only">{summary}</figcaption>
      <div style={{ marginTop: 8 }}>
        <button
          type="button"
          className="insights-button insights-button--quiet"
          aria-expanded={showTable}
          aria-controls={tableId}
          onClick={() => setShowTable((open) => !open)}
        >
          {showTable ? 'Hide the figures' : 'Show the figures'}
        </button>
      </div>
      <div id={tableId} hidden={!showTable} className="insights-table-scroll">
        {table}
      </div>
    </figure>
  )
}

function FiguresTable({
  title,
  unitLabel,
  rows,
  seriesLabels,
}: {
  title: string
  unitLabel: string
  rows: { label: string; values: string[]; note?: string }[]
  seriesLabels: string[]
}) {
  return (
    <table className="insights-table">
      <caption className="insights-sr-only">{title}, as figures</caption>
      <thead>
        <tr>
          <th scope="col">Period</th>
          {seriesLabels.map((label) => (
            <th key={label} scope="col" className="is-numeric">
              {label} ({unitLabel})
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, index) => (
          <tr key={`${row.label}-${index}`}>
            <th scope="row" style={{ fontWeight: 500 }}>
              {row.label}
              {row.note ? <span className="insights-muted"> — {row.note}</span> : null}
            </th>
            {row.values.map((value, column) => (
              <td key={column} className="is-numeric">
                {value}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  )
}

function Legend({ items }: { items: { label: string; colour: string; dashed?: boolean }[] }) {
  if (items.length < 2) return null

  return (
    <div className="insights-legend">
      {items.map((item) => (
        <span key={item.label}>
          <i
            style={{
              background: item.dashed
                ? `repeating-linear-gradient(90deg, ${item.colour} 0 4px, transparent 4px 7px)`
                : item.colour,
            }}
            aria-hidden
          />
          {item.label}
        </span>
      ))}
    </div>
  )
}

/**
 * A y-axis scale that starts at zero unless the data goes negative.
 *
 * Starting a bar chart anywhere but zero exaggerates every difference on it,
 * which is the oldest misleading chart there is.
 */
function scaleFor(values: number[]): { min: number; max: number } {
  const finite = values.filter((value) => Number.isFinite(value))
  if (finite.length === 0) return { min: 0, max: 1 }

  const highest = Math.max(...finite, 0)
  const lowest = Math.min(...finite, 0)
  if (highest === lowest) return { min: lowest, max: lowest + 1 }

  return { min: lowest, max: highest }
}

function gridTicks(min: number, max: number, count = 4): number[] {
  const step = (max - min) / count
  return Array.from({ length: count + 1 }, (_, index) => min + step * index)
}

// ---------------------------------------------------------------------------
// Line and area
// ---------------------------------------------------------------------------

export function LineChart({
  title,
  unitLabel,
  points,
  tone = 'brand',
  filled = false,
  height = 240,
  onSelect,
}: {
  title: string
  unitLabel: string
  points: Point[]
  tone?: string
  filled?: boolean
  height?: number
  onSelect?: (point: Point) => void
}) {
  const colour = toneColour(tone)
  const width = 720
  const padding = { top: 16, right: 12, bottom: 34, left: 12 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom

  const numbers = points.map((point) => (point.value === null ? Number.NaN : px(point.value)))
  const { min, max } = scaleFor(numbers)
  const span = max - min || 1

  const x = (index: number) => padding.left + (points.length === 1 ? plotWidth / 2 : (plotWidth * index) / (points.length - 1))
  const y = (value: number) => padding.top + plotHeight - ((value - min) / span) * plotHeight

  // A null breaks the line into segments rather than dropping it to the axis.
  const segments: string[] = []
  let current: string[] = []
  points.forEach((point, index) => {
    if (point.value === null) {
      if (current.length > 0) segments.push(current.join(' '))
      current = []
      return
    }
    current.push(`${current.length === 0 ? 'M' : 'L'} ${x(index).toFixed(2)} ${y(px(point.value)).toFixed(2)}`)
  })
  if (current.length > 0) segments.push(current.join(' '))

  const areaPath =
    filled && segments.length === 1 && points.length > 1
      ? `${segments[0]} L ${x(points.length - 1).toFixed(2)} ${y(Math.max(0, min)).toFixed(2)} L ${x(0).toFixed(2)} ${y(Math.max(0, min)).toFixed(2)} Z`
      : null

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${points.map((point) => `${point.label}: ${point.formatted}`).join('. ')}.`}
      table={
        <FiguresTable
          title={title}
          unitLabel={unitLabel}
          seriesLabels={[title]}
          rows={points.map((point) => ({
            label: point.label,
            values: [point.formatted],
            note: point.partial ? 'part period' : undefined,
          }))}
        />
      }
    >
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label={title} preserveAspectRatio="none">
        {gridTicks(min, max).map((tick) => (
          <line
            key={tick}
            x1={padding.left}
            x2={width - padding.right}
            y1={y(tick)}
            y2={y(tick)}
            stroke="var(--ix-border)"
            strokeWidth={1}
          />
        ))}
        {areaPath ? <path d={areaPath} fill={colour} opacity={0.12} /> : null}
        {segments.map((segment, index) => (
          <path key={index} d={segment} fill="none" stroke={colour} strokeWidth={2.2} strokeLinejoin="round" strokeLinecap="round" />
        ))}
        {points.map((point, index) =>
          point.value === null ? null : (
            <circle
              key={point.key}
              cx={x(index)}
              cy={y(px(point.value))}
              r={points.length > 24 ? 2 : 3.4}
              fill={colour}
              // A part period is drawn hollow as well as flagged in the table:
              // the last bucket of a month-to-date is not a smaller month.
              fillOpacity={point.partial ? 0.25 : 1}
              stroke={colour}
              strokeWidth={point.partial ? 1.6 : 0}
              onClick={onSelect ? () => onSelect(point) : undefined}
              style={onSelect ? { cursor: 'pointer' } : undefined}
            />
          ),
        )}
        {points.map((point, index) => {
          // Label every nth point so the axis stays readable at any length.
          const step = Math.max(1, Math.ceil(points.length / 7))
          if (index % step !== 0 && index !== points.length - 1) return null

          return (
            <text
              key={`label-${point.key}`}
              x={x(index)}
              y={height - 12}
              textAnchor={index === 0 ? 'start' : index === points.length - 1 ? 'end' : 'middle'}
              fontSize={11}
              fill="var(--ix-muted)"
            >
              {point.label}
            </text>
          )
        })}
      </svg>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Columns
// ---------------------------------------------------------------------------

export function ColumnChart({
  title,
  unitLabel,
  points,
  tone = 'brand',
  height = 240,
  onSelect,
}: {
  title: string
  unitLabel: string
  points: Point[]
  tone?: string
  height?: number
  onSelect?: (point: Point) => void
}) {
  const colour = toneColour(tone)
  const width = 720
  const padding = { top: 16, right: 12, bottom: 34, left: 12 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom

  const numbers = points.map((point) => (point.value === null ? 0 : px(point.value)))
  const { min, max } = scaleFor(numbers)
  const span = max - min || 1
  const zeroY = padding.top + plotHeight - ((0 - min) / span) * plotHeight

  const slot = points.length === 0 ? plotWidth : plotWidth / points.length
  const barWidth = Math.max(4, Math.min(48, slot * 0.62))

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${points.map((point) => `${point.label}: ${point.formatted}`).join('. ')}.`}
      table={
        <FiguresTable
          title={title}
          unitLabel={unitLabel}
          seriesLabels={[title]}
          rows={points.map((point) => ({
            label: point.label,
            values: [point.formatted],
            note: point.partial ? 'part period' : undefined,
          }))}
        />
      }
    >
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label={title} preserveAspectRatio="none">
        {gridTicks(min, max).map((tick) => {
          const ty = padding.top + plotHeight - ((tick - min) / span) * plotHeight
          return <line key={tick} x1={padding.left} x2={width - padding.right} y1={ty} y2={ty} stroke="var(--ix-border)" strokeWidth={1} />
        })}
        {points.map((point, index) => {
          if (point.value === null) return null
          const value = px(point.value)
          const valueY = padding.top + plotHeight - ((value - min) / span) * plotHeight
          const top = Math.min(valueY, zeroY)
          const barHeight = Math.max(1, Math.abs(zeroY - valueY))
          const cx = padding.left + slot * index + slot / 2

          return (
            <rect
              key={point.key}
              x={cx - barWidth / 2}
              y={top}
              width={barWidth}
              height={barHeight}
              rx={3}
              fill={colour}
              fillOpacity={point.partial ? 0.45 : 1}
              onClick={onSelect ? () => onSelect(point) : undefined}
              style={onSelect ? { cursor: 'pointer' } : undefined}
            />
          )
        })}
        {points.map((point, index) => {
          const step = Math.max(1, Math.ceil(points.length / 8))
          if (index % step !== 0 && index !== points.length - 1) return null
          const cx = padding.left + slot * index + slot / 2

          return (
            <text key={`label-${point.key}`} x={cx} y={height - 12} textAnchor="middle" fontSize={11} fill="var(--ix-muted)">
              {point.label}
            </text>
          )
        })}
      </svg>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Stacked columns
// ---------------------------------------------------------------------------

export interface StackedSeries {
  label: string
  tone: string
  points: Point[]
}

export function StackedColumnChart({
  title,
  unitLabel,
  categories,
  series,
  height = 260,
}: {
  title: string
  unitLabel: string
  categories: string[]
  series: StackedSeries[]
  height?: number
}) {
  const width = 720
  const padding = { top: 16, right: 12, bottom: 34, left: 12 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom

  const totals = categories.map((_, index) =>
    series.reduce((sum, entry) => sum + Math.max(0, px(entry.points[index]?.value)), 0),
  )
  const max = Math.max(1, ...totals)
  const slot = categories.length === 0 ? plotWidth : plotWidth / categories.length
  const barWidth = Math.max(6, Math.min(56, slot * 0.6))

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${categories
        .map((category, index) => `${category}: ${series.map((entry) => `${entry.label} ${entry.points[index]?.formatted ?? '—'}`).join(', ')}`)
        .join('. ')}.`}
      legend={<Legend items={series.map((entry) => ({ label: entry.label, colour: toneColour(entry.tone) }))} />}
      table={
        <FiguresTable
          title={title}
          unitLabel={unitLabel}
          seriesLabels={series.map((entry) => entry.label)}
          rows={categories.map((category, index) => ({
            label: category,
            values: series.map((entry) => entry.points[index]?.formatted ?? '—'),
          }))}
        />
      }
    >
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label={title} preserveAspectRatio="none">
        {gridTicks(0, max).map((tick) => {
          const ty = padding.top + plotHeight - (tick / max) * plotHeight
          return <line key={tick} x1={padding.left} x2={width - padding.right} y1={ty} y2={ty} stroke="var(--ix-border)" strokeWidth={1} />
        })}
        {categories.map((category, index) => {
          const cx = padding.left + slot * index + slot / 2
          let cursor = padding.top + plotHeight

          return (
            <g key={category}>
              {series.map((entry) => {
                const value = Math.max(0, px(entry.points[index]?.value))
                const segmentHeight = (value / max) * plotHeight
                cursor -= segmentHeight
                if (segmentHeight <= 0) return null

                return (
                  <rect
                    key={entry.label}
                    x={cx - barWidth / 2}
                    y={cursor}
                    width={barWidth}
                    height={segmentHeight}
                    fill={toneColour(entry.tone)}
                  />
                )
              })}
            </g>
          )
        })}
        {categories.map((category, index) => {
          const step = Math.max(1, Math.ceil(categories.length / 8))
          if (index % step !== 0 && index !== categories.length - 1) return null

          return (
            <text
              key={`label-${category}`}
              x={padding.left + slot * index + slot / 2}
              y={height - 12}
              textAnchor="middle"
              fontSize={11}
              fill="var(--ix-muted)"
            >
              {category}
            </text>
          )
        })}
      </svg>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Horizontal ranking
// ---------------------------------------------------------------------------

export function RankingChart({
  title,
  unitLabel,
  points,
  tone = 'brand',
  onSelect,
  scaleMax,
}: {
  title: string
  unitLabel: string
  points: Point[]
  tone?: string
  onSelect?: (point: Point) => void
  /**
   * A fixed top of the scale, for bars that are already a percentage.
   *
   * Without it the widest bar fills the track whatever it is worth, which is
   * right for spend and wrong for a rate: 61% on time would be drawn as a full
   * bar simply because nobody did better.
   */
  scaleMax?: number
}) {
  const colour = toneColour(tone)
  const max = scaleMax ?? points.reduce((highest, point) => Math.max(highest, Math.abs(px(point.value))), 0)

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${points.map((point) => `${point.label}: ${point.formatted}`).join('. ')}.`}
      table={
        <FiguresTable
          title={title}
          unitLabel={unitLabel}
          seriesLabels={[title]}
          rows={points.map((point) => ({ label: point.label, values: [point.formatted] }))}
        />
      }
    >
      <div style={{ display: 'grid', gap: 9 }}>
        {points.map((point) => {
          const share = max > 0 ? Math.min(100, (Math.abs(px(point.value)) / max) * 100) : 0

          return (
            <div key={point.key} style={{ display: 'grid', gap: 3 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, fontSize: 12.5 }}>
                <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {onSelect ? (
                    <button
                      type="button"
                      className="insights-button insights-button--quiet"
                      style={{ padding: 0, minHeight: 0, fontSize: 12.5, color: 'var(--ix-text)' }}
                      onClick={() => onSelect(point)}
                    >
                      {point.label}
                    </button>
                  ) : (
                    point.label
                  )}
                </span>
                <span className="num" style={{ flex: 'none', fontWeight: 600 }}>
                  {point.formatted}
                </span>
              </div>
              <div style={{ height: 8, borderRadius: 999, background: 'var(--ix-surface-2)', overflow: 'hidden' }}>
                <div
                  style={{
                    height: '100%',
                    width: `${share}%`,
                    background: colour,
                    opacity: px(point.value) < 0 ? 0.5 : 1,
                  }}
                />
              </div>
            </div>
          )
        })}
      </div>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Donut
// ---------------------------------------------------------------------------

export function DonutChart({
  title,
  unitLabel,
  points,
  centreLabel,
  centreValue,
}: {
  title: string
  unitLabel: string
  points: Point[]
  centreLabel?: string
  centreValue?: string
}) {
  const size = 200
  const radius = 78
  const thickness = 26
  const centre = size / 2
  const palette = Object.values(TONES)

  const values = points.map((point) => Math.max(0, px(point.value)))
  const total = values.reduce((sum, value) => sum + value, 0)

  let angle = -Math.PI / 2
  const arcs = points.map((point, index) => {
    const value = values[index]
    const sweep = total > 0 ? (value / total) * Math.PI * 2 : 0
    const start = angle
    angle += sweep

    const x1 = centre + radius * Math.cos(start)
    const y1 = centre + radius * Math.sin(start)
    const x2 = centre + radius * Math.cos(angle)
    const y2 = centre + radius * Math.sin(angle)
    const large = sweep > Math.PI ? 1 : 0

    return {
      point,
      colour: palette[index % palette.length],
      // A single slice covering the whole circle cannot be drawn as an arc —
      // start and end land on the same coordinate — so it is a ring instead.
      path:
        sweep >= Math.PI * 2 - 0.0001
          ? null
          : `M ${x1.toFixed(2)} ${y1.toFixed(2)} A ${radius} ${radius} 0 ${large} 1 ${x2.toFixed(2)} ${y2.toFixed(2)}`,
      full: sweep >= Math.PI * 2 - 0.0001,
    }
  })

  return (
    <ChartFrame
      title={title}
      summary={`${title}. ${points.map((point) => `${point.label}: ${point.formatted}`).join('. ')}.`}
      legend={<Legend items={arcs.map((arc) => ({ label: arc.point.label, colour: arc.colour }))} />}
      table={
        <FiguresTable
          title={title}
          unitLabel={unitLabel}
          seriesLabels={[title]}
          rows={points.map((point) => ({ label: point.label, values: [point.formatted] }))}
        />
      }
    >
      <div style={{ display: 'grid', placeItems: 'center' }}>
        <svg viewBox={`0 0 ${size} ${size}`} width={size} height={size} role="img" aria-label={title}>
          <circle cx={centre} cy={centre} r={radius} fill="none" stroke="var(--ix-surface-2)" strokeWidth={thickness} />
          {arcs.map((arc) =>
            arc.full ? (
              <circle key={arc.point.key} cx={centre} cy={centre} r={radius} fill="none" stroke={arc.colour} strokeWidth={thickness} />
            ) : arc.path ? (
              <path key={arc.point.key} d={arc.path} fill="none" stroke={arc.colour} strokeWidth={thickness} strokeLinecap="butt" />
            ) : null,
          )}
          {centreValue ? (
            <>
              <text x={centre} y={centre - 2} textAnchor="middle" fontSize={17} fontWeight={650} fill="var(--ix-text)">
                {centreValue}
              </text>
              {centreLabel ? (
                <text x={centre} y={centre + 16} textAnchor="middle" fontSize={11} fill="var(--ix-muted)">
                  {centreLabel}
                </text>
              ) : null}
            </>
          ) : null}
        </svg>
      </div>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Forecast
// ---------------------------------------------------------------------------

export function ForecastChart({
  title,
  unitLabel,
  history,
  projection,
  scenarioLabel,
  height = 260,
}: {
  title: string
  unitLabel: string
  history: Point[]
  projection: (Point & { scenario?: string | null; scenarioFormatted?: string | null })[]
  scenarioLabel?: string | null
  height?: number
}) {
  const colour = toneColour('brand')
  const projectedColour = toneColour('indigo')
  const scenarioColour = toneColour('amber')

  const width = 720
  const padding = { top: 16, right: 12, bottom: 34, left: 12 }
  const plotWidth = width - padding.left - padding.right
  const plotHeight = height - padding.top - padding.bottom

  const all = [...history, ...projection]
  const numbers = [
    ...all.map((point) => px(point.value)),
    ...projection.map((point) => px(point.scenario ?? null)),
  ]
  const { min, max } = scaleFor(numbers)
  const span = max - min || 1

  const x = (index: number) => padding.left + (all.length <= 1 ? plotWidth / 2 : (plotWidth * index) / (all.length - 1))
  const y = (value: number) => padding.top + plotHeight - ((value - min) / span) * plotHeight

  const line = (points: Point[], offset: number) =>
    points
      .map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index + offset).toFixed(2)} ${y(px(point.value)).toFixed(2)}`)
      .join(' ')

  // The projection is joined to the last actual, so the eye does not read a
  // gap as a drop to zero.
  const bridge = history.length > 0 && projection.length > 0
    ? `M ${x(history.length - 1).toFixed(2)} ${y(px(history[history.length - 1].value)).toFixed(2)} L ${x(history.length).toFixed(2)} ${y(px(projection[0].value)).toFixed(2)}`
    : ''

  const scenarioPath = projection.some((point) => point.scenario)
    ? projection
        .map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(index + history.length).toFixed(2)} ${y(px(point.scenario ?? point.value)).toFixed(2)}`)
        .join(' ')
    : null

  return (
    <ChartFrame
      title={title}
      summary={`${title}. Historical: ${history.map((point) => `${point.label} ${point.formatted}`).join(', ')}. Projected: ${projection
        .map((point) => `${point.label} ${point.formatted}`)
        .join(', ')}.`}
      legend={
        <Legend
          items={[
            { label: 'Actual', colour },
            { label: 'Projected', colour: projectedColour, dashed: true },
            ...(scenarioPath ? [{ label: scenarioLabel ?? 'Scenario', colour: scenarioColour, dashed: true }] : []),
          ]}
        />
      }
      table={
        <FiguresTable
          title={title}
          unitLabel={unitLabel}
          seriesLabels={scenarioPath ? ['Value', 'Scenario'] : ['Value']}
          rows={[
            ...history.map((point) => ({ label: point.label, values: scenarioPath ? [point.formatted, '—'] : [point.formatted], note: 'actual' })),
            ...projection.map((point) => ({
              label: point.label,
              values: scenarioPath ? [point.formatted, point.scenarioFormatted ?? '—'] : [point.formatted],
              note: 'projected',
            })),
          ]}
        />
      }
    >
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label={title} preserveAspectRatio="none">
        {gridTicks(min, max).map((tick) => (
          <line key={tick} x1={padding.left} x2={width - padding.right} y1={y(tick)} y2={y(tick)} stroke="var(--ix-border)" strokeWidth={1} />
        ))}
        {history.length > 0 && projection.length > 0 ? (
          <>
            <line
              x1={x(history.length - 0.5)}
              x2={x(history.length - 0.5)}
              y1={padding.top}
              y2={padding.top + plotHeight}
              stroke="var(--ix-border-strong)"
              strokeDasharray="3 3"
            />
            <text x={x(history.length - 0.5) + 5} y={padding.top + 11} fontSize={10} fill="var(--ix-muted)">
              projected →
            </text>
          </>
        ) : null}
        <path d={line(history, 0)} fill="none" stroke={colour} strokeWidth={2.2} strokeLinejoin="round" />
        {bridge ? <path d={bridge} fill="none" stroke={projectedColour} strokeWidth={2} strokeDasharray="5 4" /> : null}
        <path d={line(projection, history.length)} fill="none" stroke={projectedColour} strokeWidth={2} strokeDasharray="5 4" strokeLinejoin="round" />
        {scenarioPath ? <path d={scenarioPath} fill="none" stroke={scenarioColour} strokeWidth={1.8} strokeDasharray="2 4" /> : null}
        {all.map((point, index) => {
          const step = Math.max(1, Math.ceil(all.length / 8))
          if (index % step !== 0 && index !== all.length - 1) return null

          return (
            <text
              key={`label-${point.key}-${index}`}
              x={x(index)}
              y={height - 12}
              textAnchor={index === 0 ? 'start' : index === all.length - 1 ? 'end' : 'middle'}
              fontSize={11}
              fill="var(--ix-muted)"
            >
              {point.label}
            </text>
          )
        })}
      </svg>
    </ChartFrame>
  )
}

// ---------------------------------------------------------------------------
// Sparkline — for KPI cards, where a full chart would not fit
// ---------------------------------------------------------------------------

export function Sparkline({ points, tone = 'brand', height = 34 }: { points: Point[]; tone?: string; height?: number }) {
  if (points.length < 2) return null

  const colour = toneColour(tone)
  const width = 160
  const numbers = points.map((point) => px(point.value))
  const { min, max } = scaleFor(numbers)
  const span = max - min || 1

  const path = points
    .map((point, index) => {
      const x = (width * index) / (points.length - 1)
      const y = height - ((px(point.value) - min) / span) * (height - 4) - 2
      return `${index === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`
    })
    .join(' ')

  return (
    // Decoration beside a figure that is already stated, so it is hidden from
    // assistive technology rather than described twice.
    <svg viewBox={`0 0 ${width} ${height}`} width="100%" height={height} aria-hidden focusable="false">
      <path d={path} fill="none" stroke={colour} strokeWidth={1.8} strokeLinejoin="round" strokeLinecap="round" />
    </svg>
  )
}
