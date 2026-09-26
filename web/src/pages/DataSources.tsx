import { useCallback } from 'react'
import { CheckCircle2, CircleSlash, Plug, ShieldAlert, Wifi } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { sources as sourcesApi } from '../services/insights'
import { Badge, Button, ErrorState, Facts, LoadingState, PageHeader, Panel, PanelHeader } from '../ui'
import type { SourceCapability } from '../services/types'

/**
 * Which products are connected, and what this viewer may see in each.
 *
 * NOTHING ON THIS PAGE IS INFERRED FROM THE CODE BEING PRESENT. Every row is a
 * live call, made with this person's own session, on the request that drew the
 * page. A compiled adapter is not a working integration, and this screen is
 * where the difference is reported.
 *
 * THREE ANSWERS, NOT ONE. Configured, reachable and permitted are separate
 * facts with separate fixes: an unconfigured source needs an administrator, an
 * unreachable one needs the product to come back, and one that refuses this
 * person needs a permission in THAT product. Collapsing them into a red dot
 * tells nobody what to do.
 */

const ICON = { ready: CheckCircle2, degraded: Wifi, unavailable: ShieldAlert, not_configured: Plug } as const
const TONE = { ready: 'ready', degraded: 'warn', unavailable: 'danger', not_configured: 'neutral' } as const

export default function DataSources() {
  const { refreshToken, refresh } = useInsights()

  const load = useCallback((signal: AbortSignal) => sourcesApi.list(signal), [])
  const { data, loading, error, reload } = useApi(load, [refreshToken])

  return (
    <div className="insights-workspace">
      <PageHeader
        title="Data sources"
        subtitle="Insights reads every figure from the product that owns it, as you. This is what each one said just now."
        actions={<Button onClick={refresh}>Check again</Button>}
      />

      {loading && !data ? (
        <Panel>
          <LoadingState label="Asking each product…" rows={4} />
        </Panel>
      ) : error ? (
        <Panel>
          <ErrorState detail={error} onRetry={reload} />
        </Panel>
      ) : data ? (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))', gap: 14 }}>
            {data.sources.map((source) => (
              <SourceCard key={source.product} source={source} />
            ))}
          </div>

          <Panel style={{ marginTop: 18 }}>
            <PanelHeader
              title="AI"
              subtitle="Provider keys live in Console, never in this product and never in the browser."
            />
            <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
              <Badge tone={data.ai.available ? 'ready' : 'neutral'}>
                {data.ai.available ? 'Connected' : 'Not connected'}
              </Badge>
              <div style={{ minWidth: 0 }}>
                {data.ai.available ? (
                  <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.55 }}>
                    Bound to {data.ai.provider} · {data.ai.model} for {data.ai.domain}.
                  </p>
                ) : (
                  <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.55 }}>
                    {data.ai.reason}
                  </p>
                )}
                {data.ai.admin_hint ? (
                  <p className="insights-muted" style={{ margin: '6px 0 0', fontSize: 12 }}>
                    {data.ai.admin_hint}
                  </p>
                ) : null}
              </div>
            </div>
          </Panel>

          {data.unbound_metrics.length > 0 ? (
            <Panel style={{ marginTop: 18 }}>
              <PanelHeader
                title="Figures nothing can answer yet"
                subtitle="Listed here so the gap is visible rather than silent. Anything depending on one of these reports unavailable — never zero."
              />
              <div className="insights-table-scroll">
                <table className="insights-table">
                  <caption className="insights-sr-only">Metrics with no verified endpoint</caption>
                  <thead>
                    <tr>
                      <th scope="col">Figure</th>
                      <th scope="col">Should come from</th>
                      <th scope="col">Why not yet</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.unbound_metrics.map((metric) => (
                      <tr key={metric.metric_id}>
                        <th scope="row" style={{ fontWeight: 600 }}>
                          {metric.label}
                          <br />
                          <span className="insights-muted" style={{ fontWeight: 400, fontSize: 11.5 }}>
                            {metric.definition}
                          </span>
                        </th>
                        <td>{metric.owner}</td>
                        <td style={{ fontSize: 12, color: 'var(--ix-muted)' }}>{metric.reason}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          ) : null}

          <p className="insights-muted" style={{ fontSize: 11.5, marginTop: 16, lineHeight: 1.55 }}>
            {data.note} Checked {new Date(data.checked_at).toLocaleString()}.
          </p>
        </>
      ) : null}
    </div>
  )
}

function SourceCard({ source }: { source: SourceCapability }) {
  const Icon = ICON[source.status] ?? CircleSlash

  return (
    <article className="insights-panel" style={{ display: 'grid', gap: 12, alignContent: 'start' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'flex-start' }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 650, display: 'flex', alignItems: 'center', gap: 7 }}>
          <Icon size={16} aria-hidden style={{ color: 'var(--ix-muted)' }} />
          {source.label}
        </h2>
        <Badge tone={TONE[source.status] ?? 'neutral'}>
          {source.status === 'ready'
            ? 'Connected'
            : source.status === 'not_configured'
              ? 'Setup required'
              : source.status === 'degraded'
                ? 'Partial'
                : 'Unavailable'}
        </Badge>
      </div>

      {source.message ? (
        <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.55 }}>
          {source.message}
        </p>
      ) : null}

      <Facts
        rows={[
          { label: 'Configured', value: source.configured ? 'Yes' : 'No — an administrator must enable it' },
          { label: 'Reachable', value: source.reachable ? 'Yes' : 'No — it did not answer from this server' },
          { label: 'You are permitted', value: source.permitted ? 'Yes' : 'No — ask for access in that product' },
          ...(source.metrics.length > 0
            ? [{ label: 'Answers', value: `${source.metrics.length} metric${source.metrics.length === 1 ? '' : 's'}` }]
            : [{ label: 'Answers', value: 'Status and links only' }]),
          ...(source.dimensions.length > 0 ? [{ label: 'Splits by', value: source.dimensions.join(', ') }] : []),
          { label: 'Last checked', value: new Date(source.checked_at).toLocaleTimeString() },
        ]}
      />

      {source.metrics.length > 0 ? (
        <details>
          <summary style={{ cursor: 'pointer', fontSize: 12, color: 'var(--ix-muted)' }}>
            The figures it answers
          </summary>
          <ul style={{ margin: '8px 0 0', paddingLeft: 18, fontSize: 11.5, lineHeight: 1.7 }}>
            {source.metrics.map((metricId) => (
              <li key={metricId}>
                <code>{metricId}</code>
              </li>
            ))}
          </ul>
        </details>
      ) : null}
    </article>
  )
}
