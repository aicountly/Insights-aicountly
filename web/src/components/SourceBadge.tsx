import { Badge, type Tone } from '../ui'
import type { SourceRow, SourceStatus } from '../services/types'

/**
 * Which products answered, and when.
 *
 * A dashboard composed from five live products will sometimes be composed from
 * three, and this is how the screen says so. "We could not ask" and "we asked
 * and the answer was zero" are different facts, and merging them is how a
 * dashboard quietly understates a business.
 *
 * `fetched_at` is when INSIGHTS fetched. Where a product tells us how fresh its
 * own answer is that goes in `source_as_of` and is shown instead, because it is
 * the one a reader should trust. Nothing here is ever labelled "real time" on
 * the strength of a fetch timestamp.
 */

const TONE: Record<SourceStatus, Tone> = {
  ready: 'ready',
  degraded: 'warn',
  unavailable: 'danger',
  not_configured: 'neutral',
}

function when(iso: string | null): string {
  if (!iso) return ''
  const parsed = new Date(iso)
  if (Number.isNaN(parsed.getTime())) return ''

  const seconds = Math.round((Date.now() - parsed.getTime()) / 1000)
  if (seconds < 45) return 'just now'
  if (seconds < 3600) return `${Math.round(seconds / 60)} min ago`
  if (seconds < 86_400) return `${Math.round(seconds / 3600)} h ago`

  return parsed.toLocaleDateString()
}

export function SourceBadge({ source }: { source: SourceRow }) {
  const freshness = source.source_as_of
    ? `as at ${new Date(source.source_as_of).toLocaleString()}`
    : source.fetched_at
      ? `read ${when(source.fetched_at)}`
      : null

  return (
    <span title={source.message ?? undefined} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
      <Badge tone={TONE[source.status]}>
        {source.label}: {source.status_label}
      </Badge>
      {freshness ? (
        <span className="insights-muted" style={{ fontSize: 11.5 }}>
          {freshness}
        </span>
      ) : null}
    </span>
  )
}

export function SourceStatusBar({ sources, compact = false }: { sources: SourceRow[]; compact?: boolean }) {
  if (sources.length === 0) return null

  const problems = sources.filter((source) => source.status !== 'ready')

  return (
    <div
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        gap: 10,
        padding: compact ? 0 : '10px 12px',
        border: compact ? undefined : '1px solid var(--ix-border)',
        borderRadius: compact ? undefined : 10,
        background: compact ? undefined : 'var(--ix-surface)',
        marginBottom: compact ? 0 : 14,
      }}
      role="group"
      aria-label="Where these figures come from"
    >
      {sources.map((source) => (
        <SourceBadge key={source.id} source={source} />
      ))}

      {problems.length > 0 ? (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12, flexBasis: '100%', lineHeight: 1.5 }}>
          {problems
            .map((source) => source.message)
            .filter(Boolean)
            .join(' ')}
        </p>
      ) : null}
    </div>
  )
}
