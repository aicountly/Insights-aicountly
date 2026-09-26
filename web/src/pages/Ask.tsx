import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ExternalLink, Send, Sparkles } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { ai as aiApi } from '../services/insights'
import { ContextBar } from '../shell/ContextBar'
import { MetricCard } from '../components/MetricCard'
import { EvidenceDrawer, buildDrilldownHref } from '../components/EvidenceDrawer'
import { SourceStatusBar } from '../components/SourceBadge'
import { DashboardCanvas } from '../components/DashboardCanvas'
import { Badge, Button, EmptyState, ErrorState, LoadingState, PageHeader, Panel, PanelHeader } from '../ui'
import type { AiAnswer, AiProposal, AiResponse, MetricValue, Widget } from '../services/types'

/**
 * Ask Insights.
 *
 * THE ANSWER IS NEVER JUST PROSE. Every reply carries the figures it was
 * written from, the scope and period it covers, links into the owning products,
 * and what it could not answer. A written sentence with no evidence under it is
 * a sentence somebody has to take on trust, and business figures are the last
 * place for that.
 *
 * IT SAYS WHO WROTE IT. `generated_by` distinguishes a model answer from the
 * rules-based reading — and the rules-based reading is what appears when no
 * model is configured, when the budget is spent, or when the model quoted a
 * figure that was not among the ones fetched.
 *
 * A DASHBOARD PROPOSAL IS A PREVIEW. Nothing is created until Apply.
 */
export default function Ask() {
  const { period, can } = useInsights()
  const navigate = useNavigate()

  const [question, setQuestion] = useState('')
  const [asking, setAsking] = useState(false)
  const [answer, setAnswer] = useState<AiResponse | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [evidence, setEvidence] = useState<MetricValue | null>(null)

  const loadStatus = useCallback((signal: AbortSignal) => aiApi.status(signal), [])
  const status = useApi(loadStatus, [])

  const suggestions = [
    'Why did collections fall this month?',
    'Which items are tying up working capital?',
    'Compare branch-wise sales and margins',
    'Create a dashboard for overdue collections and stock ageing',
  ]

  async function ask(text: string) {
    const trimmed = text.trim()
    if (!trimmed) return

    setAsking(true)
    setError(null)
    setAnswer(null)
    try {
      setAnswer(await aiApi.ask(trimmed, period))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That question could not be answered.')
    } finally {
      setAsking(false)
    }
  }

  return (
    <div className="insights-workspace">
      <PageHeader
        title="Ask Insights"
        subtitle="Ask about your business in plain English. Every answer shows the figures behind it."
      />

      <ContextBar busy={asking} showGrain={false} />

      {status.data && !status.data.available ? (
        <Panel style={{ marginBottom: 14 }}>
          <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
            <Sparkles size={18} aria-hidden style={{ color: 'var(--ix-muted)', flex: 'none', marginTop: 2 }} />
            <div>
              <p style={{ margin: 0, fontSize: 13.5, fontWeight: 600 }}>Answers here are rules-based</p>
              <p className="insights-muted" style={{ margin: '4px 0 0', fontSize: 12.5, lineHeight: 1.55 }}>
                {status.data.reason} Questions still work — the answer is assembled from the figures rather than
                written by a model.
              </p>
              {status.data.admin_hint ? (
                <p className="insights-muted" style={{ margin: '6px 0 0', fontSize: 12 }}>
                  {status.data.admin_hint}
                </p>
              ) : null}
            </div>
          </div>
        </Panel>
      ) : null}

      <Panel style={{ marginBottom: 16 }}>
        <form
          onSubmit={(event) => {
            event.preventDefault()
            void ask(question)
          }}
          style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}
        >
          <div className="insights-field" style={{ flex: '1 1 320px' }}>
            <label htmlFor="ask-question">Your question</label>
            <input
              id="ask-question"
              className="insights-input"
              value={question}
              maxLength={500}
              placeholder="Why did collections fall this month?"
              onChange={(event) => setQuestion(event.target.value)}
            />
          </div>
          <Button type="submit" variant="primary" busy={asking} disabled={!question.trim()}>
            <Send size={14} aria-hidden /> Ask
          </Button>
        </form>

        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 12 }}>
          {suggestions.map((suggestion) => (
            <button
              key={suggestion}
              type="button"
              className="insights-chip insights-chip--wrap"
              style={{ cursor: 'pointer' }}
              onClick={() => {
                setQuestion(suggestion)
                void ask(suggestion)
              }}
            >
              {suggestion}
            </button>
          ))}
        </div>
      </Panel>

      {asking ? (
        <Panel>
          <LoadingState label="Reading your connected products…" rows={4} />
        </Panel>
      ) : null}

      {error ? (
        <Panel>
          <ErrorState detail={error} onRetry={() => void ask(question)} />
        </Panel>
      ) : null}

      {answer?.kind === 'answer' ? (
        <AnswerPanel answer={answer} onOpenEvidence={setEvidence} />
      ) : answer?.kind === 'proposal' ? (
        <ProposalPanel
          proposal={answer}
          canApply={can('dashboard.create')}
          onApplied={(dashboardId) => navigate(`/dashboards/${dashboardId}/edit`)}
        />
      ) : null}

      {!answer && !asking && !error ? (
        <Panel>
          <EmptyState
            title="Ask something"
            detail="Insights reads your connected products live, as you, and answers from the figures they return. It cannot post a voucher, change a master or send anything."
          />
        </Panel>
      ) : null}

      {evidence ? <EvidenceDrawer metric={evidence} onClose={() => setEvidence(null)} /> : null}
    </div>
  )
}

function AnswerPanel({ answer, onOpenEvidence }: { answer: AiAnswer; onOpenEvidence: (metric: MetricValue) => void }) {
  return (
    <>
      <Panel style={{ marginBottom: 16 }}>
        <PanelHeader
          title="Answer"
          subtitle={`${answer.period.label} · ${answer.period.comparison_label}`}
          actions={
            <Badge tone={answer.generated_by === 'model' ? 'info' : 'neutral'}>
              {answer.generated_by === 'model' ? 'Written by the model' : 'Assembled from the figures'}
            </Badge>
          }
        />

        <p style={{ margin: 0, fontSize: 14.5, lineHeight: 1.7 }}>{answer.narrative}</p>

        {answer.findings.length > 0 ? (
          <ul style={{ margin: '14px 0 0', paddingLeft: 18, fontSize: 13, lineHeight: 1.7 }}>
            {answer.findings.map((finding, index) => (
              <li key={index}>{finding}</li>
            ))}
          </ul>
        ) : null}

        {answer.limitations.length > 0 ? (
          <div style={{ marginTop: 16 }}>
            <h3 style={{ fontSize: 12.5, margin: '0 0 6px', color: 'var(--warning)' }}>What this does not tell you</h3>
            <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, lineHeight: 1.65, color: 'var(--ix-muted)' }}>
              {answer.limitations.map((limitation, index) => (
                <li key={index}>{limitation}</li>
              ))}
            </ul>
          </div>
        ) : null}

        {answer.next_steps.length > 0 ? (
          <div style={{ marginTop: 14 }}>
            <h3 style={{ fontSize: 12.5, margin: '0 0 6px' }}>What to look at next</h3>
            <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12.5, lineHeight: 1.65, color: 'var(--ix-muted)' }}>
              {answer.next_steps.map((step, index) => (
                <li key={index}>{step}</li>
              ))}
            </ul>
          </div>
        ) : null}

        {answer.evidence_links.length > 0 ? (
          <div style={{ marginTop: 14, display: 'flex', gap: 7, flexWrap: 'wrap' }}>
            {answer.evidence_links.map((link) => {
              const href = buildDrilldownHref(link)
              if (!href) return null

              return (
                <a key={link.metric_id} className="insights-chip" href={href} target="_blank" rel="noopener noreferrer">
                  {link.label} <ExternalLink size={11} aria-hidden />
                </a>
              )
            })}
          </div>
        ) : null}

        <p className="insights-muted" style={{ margin: '14px 0 0', fontSize: 11.5, fontStyle: 'italic', lineHeight: 1.55 }}>
          This describes what the figures show. A correlation between two of them is not evidence that one caused the
          other.
        </p>
      </Panel>

      <SourceStatusBar sources={answer.sources} />

      {answer.supporting_metrics.length > 0 ? (
        <div className="insights-kpis">
          {answer.supporting_metrics.map((metric) => (
            <MetricCard key={metric.metric_id} metric={metric} onOpenEvidence={onOpenEvidence} />
          ))}
        </div>
      ) : null}
    </>
  )
}

function ProposalPanel({
  proposal,
  canApply,
  onApplied,
}: {
  proposal: AiProposal
  canApply: boolean
  onApplied: (dashboardId: string) => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [title, setTitle] = useState(proposal.title)

  const widgets: Widget[] = proposal.widgets.map((widget, index) => ({
    ...widget,
    id: `proposal-${index}`,
    position: index,
  }))

  async function apply() {
    if (!proposal.proposal_id) return
    setBusy(true)
    setError(null)
    try {
      const created = await aiApi.apply(proposal.proposal_id, title)
      onApplied(created.id)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That proposal could not be applied.')
      setBusy(false)
    }
  }

  return (
    <Panel>
      <PanelHeader
        title="Proposed dashboard"
        subtitle={proposal.apply_note}
        actions={
          <Badge tone={proposal.generated_by === 'model' ? 'info' : 'neutral'}>
            {proposal.generated_by === 'model' ? 'Laid out by the model' : 'Assembled from your question'}
          </Badge>
        }
      />

      {error ? (
        <p role="alert" style={{ color: 'var(--danger)', fontSize: 13 }}>
          {error}
        </p>
      ) : null}

      <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap', marginBottom: 16 }}>
        <div className="insights-field" style={{ flex: '1 1 260px' }}>
          <label htmlFor="proposal-title">Name it</label>
          <input id="proposal-title" className="insights-input" value={title} onChange={(event) => setTitle(event.target.value)} />
        </div>
        <Button
          variant="primary"
          onClick={() => void apply()}
          busy={busy}
          disabled={!canApply || !proposal.proposal_id || widgets.length === 0}
        >
          Create this dashboard
        </Button>
      </div>

      {!canApply ? (
        <p className="insights-muted" style={{ fontSize: 12.5, marginTop: 0 }}>
          You do not have permission to create dashboards in this company, so this is a preview only.
        </p>
      ) : null}

      {proposal.rejected.length > 0 ? (
        <div
          style={{
            padding: '10px 12px',
            background: 'var(--warning-bg)',
            borderRadius: 9,
            fontSize: 12.5,
            color: 'var(--warning)',
            marginBottom: 14,
            lineHeight: 1.55,
          }}
        >
          <strong>Some proposed panels were refused and are not shown:</strong>
          <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
            {proposal.rejected.map((entry, index) => (
              <li key={index}>
                {entry.title} — {entry.reason}
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {widgets.length === 0 ? (
        <EmptyState title="Nothing could be proposed" detail="No metric in the catalogue matched that request." />
      ) : (
        // The preview renders the proposal with no data behind it: the point of
        // the screen is the SHAPE. Figures arrive when it is created, from the
        // live products, under the viewer's own permissions.
        <DashboardCanvas widgets={widgets} rendered={{}} sources={proposal.sources} loading={false} />
      )}
    </Panel>
  )
}
