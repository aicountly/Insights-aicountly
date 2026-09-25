import { ExternalLink } from 'lucide-react'
import { Badge, Button, Drawer, Facts, WarningList } from '../ui'
import { resolveAppOrigin, getAppById } from '../services/appLauncher'
import { isSandboxHost } from '../auth/hostnames'
import type { MetricValue } from '../services/types'

/**
 * Where a figure came from, and how to see the records behind it.
 *
 * A NUMBER ON A DASHBOARD IS ONLY AS GOOD AS THE READER'S ABILITY TO CHECK IT.
 * This panel is that ability: the definition in words, which product answered,
 * under which contract, on which endpoint, for which scope and period, when it
 * was fetched, how fresh the source said it was, whether the coverage was
 * complete, and anything that qualifies it.
 *
 * The drill-down is a DESCRIPTION resolved here, never a URL the server sent.
 * The product's own origin comes from the launcher catalogue and the parameters
 * are ids — so a drill-down cannot become an open redirect, and it lands the
 * reader in the owning product where that product's own permissions apply.
 */
export function EvidenceDrawer({ metric, onClose }: { metric: MetricValue; onClose: () => void }) {
  const target = metric.drilldown as
    | { product?: string; route?: string; params?: Record<string, string | number>; label?: string }
    | null

  const href = buildDrilldownHref(target)

  return (
    <Drawer title={metric.label} onClose={onClose}>
      <div style={{ display: 'grid', gap: 18 }}>
        <section>
          <p style={{ margin: 0, fontSize: 22, fontWeight: 650, fontVariantNumeric: 'tabular-nums' }}>{metric.formatted}</p>
          {metric.comparison && metric.comparison.change !== null ? (
            <p className="insights-muted" style={{ margin: '4px 0 0', fontSize: 13 }}>
              {metric.comparison.change_formatted} {metric.period.comparison_label} ({metric.comparison.formatted})
            </p>
          ) : null}
          {metric.message ? (
            <p className="insights-muted" style={{ margin: '8px 0 0', fontSize: 13, lineHeight: 1.55 }}>
              {metric.message}
            </p>
          ) : null}
        </section>

        <section>
          <h3 style={{ fontSize: 13, margin: '0 0 8px' }}>What this counts</h3>
          <p className="insights-muted" style={{ margin: 0, fontSize: 13, lineHeight: 1.6 }}>
            {metric.definition.text}
          </p>
        </section>

        <section>
          <h3 style={{ fontSize: 13, margin: '0 0 8px' }}>Scope and period</h3>
          <Facts
            rows={[
              { label: 'Company', value: `#${metric.scope.company_id}` },
              { label: 'Branch', value: metric.scope.branch_id === 0 ? 'All branches' : `#${metric.scope.branch_id}` },
              { label: 'Financial year', value: `#${metric.scope.financial_year_id}` },
              { label: 'Period', value: metric.period.label },
              { label: 'Comparison', value: metric.period.comparison_label },
              { label: 'Unit', value: metric.currency ? `${metric.unit} (${metric.currency})` : metric.unit },
            ]}
          />
        </section>

        <section>
          <h3 style={{ fontSize: 13, margin: '0 0 8px' }}>Where it came from</h3>
          <Facts
            rows={[
              { label: 'Owned by', value: metric.definition.owner },
              { label: 'Basis', value: metric.definition.accounting_basis },
              { label: 'Definition version', value: metric.definition.formula_version },
              {
                label: 'Read from',
                value:
                  metric.provenance.length === 0 ? (
                    'Nothing answered'
                  ) : (
                    <ul style={{ margin: 0, paddingLeft: 16 }}>
                      {metric.provenance.map((entry, index) => (
                        <li key={index} style={{ marginBottom: 4 }}>
                          <strong>{entry.product}</strong>
                          <br />
                          <code style={{ fontSize: 11.5, wordBreak: 'break-all' }}>{entry.endpoint}</code>
                        </li>
                      ))}
                    </ul>
                  ),
              },
              { label: 'Fetched', value: new Date(metric.fetched_at).toLocaleString() },
              {
                label: 'Source as at',
                value: metric.source_as_of ? (
                  new Date(metric.source_as_of).toLocaleString()
                ) : (
                  // Stated plainly rather than implied by the fetch time: we do
                  // not know how fresh the source's own answer was.
                  <span className="insights-muted">Not reported by the source</span>
                ),
              },
              {
                label: 'Coverage',
                value:
                  metric.coverage === 'complete' ? (
                    <Badge tone="ready">Complete</Badge>
                  ) : metric.coverage === 'partial' ? (
                    <Badge tone="warn">Part of the data</Badge>
                  ) : (
                    <Badge tone="neutral">Unknown</Badge>
                  ),
              },
            ]}
          />
        </section>

        <WarningList warnings={metric.warnings} />

        {href ? (
          <section>
            <h3 style={{ fontSize: 13, margin: '0 0 8px' }}>See the records</h3>
            <p className="insights-muted" style={{ fontSize: 12.5, marginTop: 0, lineHeight: 1.55 }}>
              Opens {metric.definition.owner} in a new tab, where that product's own permissions apply.
            </p>
            <Button onClick={() => window.open(href, '_blank', 'noopener,noreferrer')}>
              <ExternalLink size={14} aria-hidden />
              {target?.label ?? 'Open the source'}
            </Button>
          </section>
        ) : null}
      </div>
    </Drawer>
  )
}

/**
 * Turn a drill-down description into a URL.
 *
 * The origin comes from the launcher catalogue — the same one the app grid
 * uses — and NOT from anything the API sent. The route is checked to be a path
 * and the parameters are encoded. A caller that tried to smuggle an absolute
 * URL through `route` gets nothing.
 */
export function buildDrilldownHref(
  target: { product?: string; route?: string; params?: Record<string, string | number> } | null | undefined,
): string | null {
  if (!target?.product || !target.route) return null
  if (!/^\/[A-Za-z0-9/_-]*$/.test(target.route)) return null

  const app = getAppById(target.product)
  if (!app) return null

  const url = new URL(resolveAppOrigin(app, isSandboxHost()))
  url.pathname = target.route
  for (const [key, value] of Object.entries(target.params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    url.searchParams.set(key, String(value))
  }

  return url.toString()
}
