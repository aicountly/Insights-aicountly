import { useCallback, useState } from 'react'
import { ExternalLink } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { anomalies as anomaliesApi } from '../services/insights'
import { ContextBar } from '../shell/ContextBar'
import { SourceStatusBar } from '../components/SourceBadge'
import { buildDrilldownHref } from '../components/EvidenceDrawer'
import { Badge, Button, Dialog, EmptyState, ErrorState, LoadingState, PageHeader, Panel } from '../ui'
import type { AnomalyException } from '../services/types'

/**
 * Business exceptions.
 *
 * FOUND LIVE, EVERY TIME. There is no overnight job and no table of yesterday's
 * findings: the rules run against figures fetched on the request that drew this
 * page, so an exception somebody fixed an hour ago is simply not here.
 *
 * EACH ONE SHOWS ITS WORKING. The rule, what it is measured against, the
 * evidence, and why it is the severity it is. Nothing is called fraud: "this
 * head is four times its recent average" is a fact somebody can check, and
 * "suspicious payment" is an accusation this product has no standing to make.
 *
 * WHAT IS STORED IS THE REVIEW, not the exception — acknowledged, dismissed
 * with a reason, reopened.
 */

const SEVERITY_TONE = { high: 'danger', medium: 'warn', low: 'neutral' } as const

export default function Anomalies() {
  const { period, refreshToken } = useInsights()
  const [includeReviewed, setIncludeReviewed] = useState(false)
  const [reviewing, setReviewing] = useState<AnomalyException | null>(null)

  const load = useCallback(
    (signal: AbortSignal) => anomaliesApi.list(period, includeReviewed, signal),
    [period, includeReviewed],
  )

  const { data, loading, error, reload } = useApi(load, [
    period.preset,
    period.from,
    period.to,
    period.compare,
    includeReviewed,
    refreshToken,
  ])

  return (
    <div className="insights-workspace">
      <PageHeader
        title="Exceptions"
        subtitle="Things worth a second look, found from the figures on this period — not from a copy of your transactions."
      />

      <ContextBar
        busy={loading}
        showGrain={false}
        extra={
          <label className="insights-checkbox">
            <input type="checkbox" checked={includeReviewed} onChange={(event) => setIncludeReviewed(event.target.checked)} />
            Include reviewed
          </label>
        }
      />

      {data ? <SourceStatusBar sources={data.sources} /> : null}

      {loading && !data ? (
        <Panel>
          <LoadingState label="Checking this period against the rules…" rows={4} />
        </Panel>
      ) : error ? (
        <Panel>
          <ErrorState detail={error} onRetry={reload} />
        </Panel>
      ) : data && data.exceptions.length === 0 ? (
        <Panel>
          <EmptyState
            title="Nothing stands out"
            detail={`${data.evaluated.length} of ${Object.keys(data.rules).length} rules could run on this period. The rest could not read the figures they need — Data sources says which.`}
          />
        </Panel>
      ) : (
        <div style={{ display: 'grid', gap: 12 }}>
          {data?.exceptions.map((exception) => (
            <ExceptionCard
              key={exception.fingerprint}
              exception={exception}
              canReview={data.can_review}
              onReview={() => setReviewing(exception)}
            />
          ))}
        </div>
      )}

      {data ? (
        <p className="insights-muted" style={{ fontSize: 11.5, marginTop: 16, lineHeight: 1.55 }}>
          {data.note}
        </p>
      ) : null}

      {reviewing ? (
        <ReviewDialog
          exception={reviewing}
          onClose={() => setReviewing(null)}
          onDone={() => {
            setReviewing(null)
            reload()
          }}
        />
      ) : null}
    </div>
  )
}

function ExceptionCard({
  exception,
  canReview,
  onReview,
}: {
  exception: AnomalyException
  canReview: boolean
  onReview: () => void
}) {
  const href = buildDrilldownHref(exception.drilldown)

  return (
    <article className="insights-panel" style={{ display: 'grid', gap: 10 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'flex-start', flexWrap: 'wrap' }}>
        <div style={{ minWidth: 0 }}>
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 650 }}>{exception.rule}</h2>
          <p className="insights-muted" style={{ margin: '3px 0 0', fontSize: 12.5 }}>
            {exception.subject}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', flex: 'none' }}>
          <Badge tone={SEVERITY_TONE[exception.severity]}>{exception.severity} severity</Badge>
          {exception.review.status !== 'open' ? <Badge tone="neutral">{exception.review.status}</Badge> : null}
        </div>
      </div>

      <p style={{ margin: 0, fontSize: 13, lineHeight: 1.6 }}>{exception.description}</p>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {exception.evidence.map((item, index) => (
          <span key={index} className="insights-chip">
            {item.label}: <strong style={{ fontVariantNumeric: 'tabular-nums' }}>{item.formatted}</strong>
          </span>
        ))}
      </div>

      <p className="insights-muted" style={{ margin: 0, fontSize: 12, lineHeight: 1.55 }}>
        <strong>Why this severity:</strong> {exception.severity_reason}
      </p>

      {exception.review.reason ? (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12, lineHeight: 1.55 }}>
          <strong>Review note:</strong> {exception.review.reason}
        </p>
      ) : null}

      <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap' }}>
        {href ? (
          <Button onClick={() => window.open(href, '_blank', 'noopener,noreferrer')}>
            <ExternalLink size={13} aria-hidden /> See the records
          </Button>
        ) : null}
        {canReview ? (
          <Button variant="primary" onClick={onReview}>
            {exception.review.status === 'open' ? 'Review' : 'Change review'}
          </Button>
        ) : null}
      </div>
    </article>
  )
}

function ReviewDialog({
  exception,
  onClose,
  onDone,
}: {
  exception: AnomalyException
  onClose: () => void
  onDone: () => void
}) {
  const [status, setStatus] = useState<'acknowledged' | 'dismissed' | 'open'>(
    exception.review.status === 'open' ? 'acknowledged' : exception.review.status,
  )
  const [reason, setReason] = useState(exception.review.reason)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit() {
    setBusy(true)
    setError(null)
    try {
      await anomaliesApi.review(exception.fingerprint, {
        status,
        reason,
        rule_id: exception.rule_id,
        subject: exception.subject,
      })
      onDone()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That review could not be saved.')
      setBusy(false)
    }
  }

  return (
    <Dialog
      title={exception.rule}
      description="Your decision is recorded against this exception. The transactions behind it stay where they are — nothing is copied here."
      onClose={onClose}
      width={560}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            onClick={() => void submit()}
            busy={busy}
            disabled={status === 'dismissed' && reason.trim() === ''}
          >
            Save review
          </Button>
        </>
      }
    >
      {error ? (
        <p role="alert" style={{ marginTop: 0, color: 'var(--danger)', fontSize: 13 }}>
          {error}
        </p>
      ) : null}

      <div style={{ display: 'grid', gap: 14 }}>
        <div className="insights-field">
          <label htmlFor="review-status">Decision</label>
          <select
            id="review-status"
            className="insights-select"
            value={status}
            onChange={(event) => setStatus(event.target.value as typeof status)}
            data-autofocus
          >
            <option value="acknowledged">Acknowledge — seen, being dealt with</option>
            <option value="dismissed">Dismiss — not a problem</option>
            <option value="open">Reopen — put it back on the list</option>
          </select>
        </div>

        <div className="insights-field">
          <label htmlFor="review-reason">
            Why{status === 'dismissed' ? ' (required)' : ''}
          </label>
          <textarea
            id="review-reason"
            className="insights-textarea"
            value={reason}
            maxLength={600}
            placeholder={
              status === 'dismissed'
                ? 'A dismissal without a reason is a finding that quietly disappears. Say what you checked.'
                : 'Optional — for whoever reads this next.'
            }
            onChange={(event) => setReason(event.target.value)}
          />
        </div>
      </div>
    </Dialog>
  )
}
