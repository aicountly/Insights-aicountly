import { useCallback, useEffect, useMemo, useState } from 'react'
import { Plus, Search, Trash2 } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { metrics as metricsApi } from '../services/insights'
import type { FormulaCheck } from '../services/insights'
import { Badge, Button, Dialog, EmptyState, ErrorState, Facts, LoadingState, PageHeader, Panel } from '../ui'
import type { MetricDefinitionRow } from '../services/types'

/**
 * The metric catalogue, and the KPI editor.
 *
 * THE CATALOGUE IS THE CONTRACT. Every entry says what it counts — including
 * its GST and returns treatment — who owns it, which endpoint it is read from,
 * and what permission the reader needs over there. That is what makes a figure
 * on a dashboard arguable rather than merely displayed.
 *
 * THE KPI EDITOR CHECKS AS YOU TYPE, and it checks the MEANING, not just the
 * syntax: rupees plus a percentage is refused, an accounting figure added to an
 * operational one is refused, and a formula that divides by something that can
 * be zero is told what will happen when it is. Nothing typed here is ever
 * executed as code.
 */
export default function Metrics() {
  const { can } = useInsights()
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<MetricDefinitionRow | null>(null)
  const [editing, setEditing] = useState<MetricDefinitionRow | null | 'new'>(null)

  const load = useCallback((signal: AbortSignal) => metricsApi.catalogue(signal), [])
  const { data, loading, error, reload } = useApi(load, [])

  const shown = useMemo(() => {
    if (!data) return []
    const all = [...data.custom, ...data.metrics]
    const term = search.trim().toLowerCase()
    if (!term) return all

    return all.filter(
      (metric) =>
        metric.label.toLowerCase().includes(term) ||
        metric.id.toLowerCase().includes(term) ||
        metric.definition.toLowerCase().includes(term) ||
        metric.owning_product.toLowerCase().includes(term),
    )
  }, [data, search])

  return (
    <div className="insights-workspace">
      <PageHeader
        title="Metrics"
        subtitle="What every figure in Insights counts, who owns it, and where it is read from."
        actions={
          data?.can_manage && can('metric.manage') ? (
            <Button variant="primary" onClick={() => setEditing('new')}>
              <Plus size={14} aria-hidden /> New KPI
            </Button>
          ) : null
        }
      />

      <div className="insights-context">
        <div style={{ position: 'relative', flex: '1 1 260px' }}>
          <Search
            size={14}
            aria-hidden
            style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--ix-muted)' }}
          />
          <input
            type="search"
            className="insights-input"
            style={{ paddingLeft: 30 }}
            placeholder="Search metrics, definitions and owners"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            aria-label="Search metrics"
          />
        </div>
      </div>

      {loading && !data ? (
        <Panel>
          <LoadingState label="Loading the catalogue…" />
        </Panel>
      ) : error ? (
        <Panel>
          <ErrorState detail={error} onRetry={reload} />
        </Panel>
      ) : (
        <Panel className="insights-panel--flush">
          <div className="insights-table-scroll">
            <table className="insights-table">
              <caption className="insights-sr-only">The Insights metric catalogue</caption>
              <thead>
                <tr>
                  <th scope="col">Metric</th>
                  <th scope="col">What it counts</th>
                  <th scope="col">Owner</th>
                  <th scope="col">Unit</th>
                  <th scope="col">Basis</th>
                  <th scope="col" />
                </tr>
              </thead>
              <tbody>
                {shown.map((metric) => (
                  <tr key={metric.id}>
                    <th scope="row" style={{ fontWeight: 600, minWidth: 160 }}>
                      {metric.label}
                      <br />
                      <code style={{ fontSize: 11, color: 'var(--ix-muted)' }}>{metric.id}</code>
                    </th>
                    <td style={{ maxWidth: 420, fontSize: 12.5, lineHeight: 1.5, color: 'var(--ix-muted)' }}>
                      {metric.definition}
                    </td>
                    <td>
                      <Badge tone={metric.is_custom ? 'info' : 'neutral'}>
                        {metric.is_custom ? 'Your KPI' : metric.owning_product}
                      </Badge>
                    </td>
                    <td style={{ fontSize: 12.5 }}>{metric.unit}</td>
                    <td style={{ fontSize: 12.5 }}>{metric.accounting_basis}</td>
                    <td className="is-numeric">
                      <Button variant="quiet" onClick={() => setSelected(metric)}>
                        Details
                      </Button>
                      {metric.is_custom && can('metric.manage') ? (
                        <Button variant="quiet" onClick={() => setEditing(metric)}>
                          Edit
                        </Button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {shown.length === 0 ? <EmptyState title="Nothing matches" detail="Try a shorter search." /> : null}
        </Panel>
      )}

      {selected ? <MetricDetails metric={selected} onClose={() => setSelected(null)} /> : null}

      {editing && data ? (
        <KpiEditor
          metric={editing === 'new' ? null : editing}
          catalogue={data.metrics.concat(data.custom)}
          functions={data.functions}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            reload()
          }}
        />
      ) : null}
    </div>
  )
}

function MetricDetails({ metric, onClose }: { metric: MetricDefinitionRow; onClose: () => void }) {
  return (
    <Dialog title={metric.label} onClose={onClose} width={640} footer={<Button onClick={onClose}>Close</Button>}>
      <div style={{ display: 'grid', gap: 16 }}>
        <p style={{ margin: 0, fontSize: 13.5, lineHeight: 1.65 }}>{metric.definition}</p>

        <Facts
          rows={[
            { label: 'Id', value: <code>{metric.id}</code> },
            { label: 'Owned by', value: metric.owning_product },
            { label: 'Read from', value: <code style={{ fontSize: 11.5, wordBreak: 'break-all' }}>{metric.binding}</code> },
            { label: 'Unit', value: `${metric.unit} (${metric.precision} decimal places)` },
            { label: 'Accounting basis', value: metric.accounting_basis },
            { label: 'Change is stated in', value: metric.comparison_kind === 'percentage_points' ? 'percentage points' : 'percent' },
            { label: 'Better when it', value: metric.better_when === 'neutral' ? 'neither — it is context' : `goes ${metric.better_when}` },
            { label: 'Can be split by', value: metric.dimensions.length > 0 ? metric.dimensions.join(', ') : 'nothing — it is a single figure' },
            { label: 'Reported by', value: metric.grains.join(', ') },
            {
              label: 'You need',
              value:
                metric.source_permissions.length > 0
                  ? `${metric.source_permissions.join(' or ')} in ${metric.owning_product}`
                  : 'no additional permission',
            },
            { label: 'Definition version', value: metric.formula_version },
            ...(metric.depends_on.length > 0
              ? [{ label: 'Calculated from', value: metric.depends_on.join(', ') }]
              : []),
          ]}
        />

        {metric.binding.startsWith('NOT BOUND') ? (
          <p
            style={{
              margin: 0,
              padding: '10px 12px',
              background: 'var(--warning-bg)',
              borderRadius: 9,
              fontSize: 12.5,
              color: 'var(--warning)',
              lineHeight: 1.55,
            }}
          >
            No connected product exposes this yet, so it always reports unavailable. It is listed here so the gap is
            visible rather than silent.
          </p>
        ) : null}
      </div>
    </Dialog>
  )
}

/**
 * The KPI editor.
 *
 * The formula is checked against the catalogue as it is typed — debounced, so
 * it is one request per pause rather than per keystroke — and the check reports
 * what the formula PRODUCES: its unit, its basis, the metrics it reads. Seeing
 * "this will be a percentage" before saving is what stops somebody labelling a
 * ratio as rupees.
 */
function KpiEditor({
  metric,
  catalogue,
  functions,
  onClose,
  onSaved,
}: {
  metric: MetricDefinitionRow | null
  catalogue: MetricDefinitionRow[]
  functions: string[]
  onClose: () => void
  onSaved: () => void
}) {
  const [label, setLabel] = useState(metric?.label ?? '')
  const [definition, setDefinition] = useState(metric?.definition ?? '')
  const [formula, setFormula] = useState(metric?.measure ?? '')
  const [betterWhen, setBetterWhen] = useState(metric?.better_when ?? 'up')
  const [check, setCheck] = useState<FormulaCheck | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!formula.trim()) {
      setCheck(null)
      return undefined
    }

    const controller = new AbortController()
    const timer = setTimeout(() => {
      metricsApi
        .validate(formula, controller.signal)
        .then(setCheck)
        .catch(() => setCheck(null))
    }, 380)

    return () => {
      clearTimeout(timer)
      controller.abort()
    }
  }, [formula])

  async function save() {
    setBusy(true)
    setError(null)
    try {
      if (metric) {
        await metricsApi.updateCustom(metric.id, { label, definition, formula, better_when: betterWhen })
      } else {
        await metricsApi.saveCustom({ label, definition, formula, better_when: betterWhen })
      }
      onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That KPI could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  async function retire() {
    if (!metric) return
    setBusy(true)
    try {
      await metricsApi.retireCustom(metric.id)
      onSaved()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That KPI could not be retired.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog
      title={metric ? `Edit “${metric.label}”` : 'New KPI'}
      description="A KPI is arithmetic over metrics in the catalogue. Nothing typed here is ever run as code."
      onClose={onClose}
      width={680}
      footer={
        <>
          {metric ? (
            <Button variant="danger" onClick={() => void retire()} busy={busy}>
              <Trash2 size={14} aria-hidden /> Retire
            </Button>
          ) : null}
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" onClick={() => void save()} busy={busy} disabled={!label.trim() || check?.valid !== true}>
            Save
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
          <label htmlFor="kpi-label">Name</label>
          <input
            id="kpi-label"
            className="insights-input"
            value={label}
            maxLength={120}
            onChange={(event) => setLabel(event.target.value)}
            data-autofocus
          />
        </div>

        <div className="insights-field">
          <label htmlFor="kpi-definition">What it counts</label>
          <textarea
            id="kpi-definition"
            className="insights-textarea"
            value={definition}
            maxLength={600}
            placeholder="One sentence, so the next person reading this figure knows what it means."
            onChange={(event) => setDefinition(event.target.value)}
          />
        </div>

        <div className="insights-field">
          <label htmlFor="kpi-formula">Formula</label>
          <textarea
            id="kpi-formula"
            className="insights-textarea"
            value={formula}
            spellCheck={false}
            style={{ fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', fontSize: 13 }}
            placeholder="finance.net_revenue - finance.expense"
            onChange={(event) => setFormula(event.target.value)}
            aria-describedby="kpi-formula-check"
          />
          <span className="insights-hint">
            Metric ids, numbers, + − × ÷, brackets, and the functions {functions.join(', ')}.
          </span>
        </div>

        <div id="kpi-formula-check" aria-live="polite">
          {check === null ? null : check.valid ? (
            <div
              style={{
                padding: '10px 12px',
                borderRadius: 9,
                background: 'var(--success-bg)',
                color: 'var(--success)',
                fontSize: 12.5,
                lineHeight: 1.55,
              }}
            >
              <strong>This will work.</strong> It produces a {check.unit} figure from {check.references?.join(', ')}.
              {check.basis === 'derived' ? ' It is a derived figure, so it inherits the freshness of its inputs.' : ''}
            </div>
          ) : (
            <div
              role="alert"
              style={{
                padding: '10px 12px',
                borderRadius: 9,
                background: 'var(--danger-bg)',
                color: 'var(--danger)',
                fontSize: 12.5,
                lineHeight: 1.55,
              }}
            >
              {check.message}
            </div>
          )}
        </div>

        <div className="insights-field">
          <label htmlFor="kpi-better">Better when it</label>
          <select
            id="kpi-better"
            className="insights-select"
            value={betterWhen}
            onChange={(event) => setBetterWhen(event.target.value as 'up' | 'down' | 'neutral')}
          >
            <option value="up">Goes up</option>
            <option value="down">Goes down</option>
            <option value="neutral">Neither — it is context</option>
          </select>
          <span className="insights-hint">Decides whether a rise is shown as good or as a warning.</span>
        </div>

        <details>
          <summary style={{ cursor: 'pointer', fontSize: 12.5, color: 'var(--ix-muted)' }}>
            Metric ids you can use ({catalogue.length})
          </summary>
          <ul style={{ margin: '10px 0 0', paddingLeft: 18, fontSize: 12, lineHeight: 1.7, maxHeight: 200, overflowY: 'auto' }}>
            {catalogue.map((entry) => (
              <li key={entry.id}>
                <code>{entry.id}</code> — {entry.label} ({entry.unit})
              </li>
            ))}
          </ul>
        </details>
      </div>
    </Dialog>
  )
}
