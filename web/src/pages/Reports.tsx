import { useCallback, useState } from 'react'
import { Download, Plus, Save, Trash2 } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { metrics as metricsApi, reports as reportsApi } from '../services/insights'
import { ContextBar } from '../shell/ContextBar'
import { ExportDialog } from '../components/ExportDialog'
import { SourceStatusBar } from '../components/SourceBadge'
import { Badge, Button, Dialog, EmptyState, ErrorState, Facts, LoadingState, PageHeader, Panel, PanelHeader, WarningList } from '../ui'
import type { ReportDefinition } from '../services/types'

/**
 * Saved reports and their files.
 *
 * A REPORT DEFINITION IS SAVED; A REPORT RESULT IS NOT. Opening one re-asks the
 * same question of the live products — so a report saved in March and run in
 * June shows June's figures under March's definition, rather than March's
 * numbers under a June date.
 *
 * THE PREVIEW AND THE DOWNLOAD ARE THE SAME QUERY. There is no path that
 * renders one thing on screen and another into the file.
 */
export default function Reports() {
  const { period, can, refreshToken } = useInsights()

  const [selected, setSelected] = useState<ReportDefinition | null>(null)
  const [adHoc, setAdHoc] = useState<{ title: string; metrics: string[]; dimension: string | null } | null>({
    title: 'Period summary',
    metrics: ['finance.net_revenue', 'finance.collections', 'finance.receivables', 'finance.cash_and_bank'],
    dimension: null,
  })
  const [editing, setEditing] = useState(false)
  const [exporting, setExporting] = useState(false)

  const loadList = useCallback((signal: AbortSignal) => reportsApi.list(signal), [])
  const list = useApi(loadList, [refreshToken])

  const config = selected
    ? { ...selected.config, title: selected.title }
    : { metrics: adHoc?.metrics ?? [], dimension: adHoc?.dimension ?? null, title: adHoc?.title ?? 'Report' }

  const loadPreview = useCallback(
    (signal: AbortSignal) =>
      reportsApi.preview(
        selected ? { report_id: selected.id } : { config, title: config.title as string },
        period,
        signal,
      ),
    // `config` is derived from selected/adHoc, which are both in the dep list
    // below; including it directly would re-run on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [selected, adHoc, period],
  )

  const preview = useApi(loadPreview, [
    selected?.id,
    adHoc?.metrics.join(','),
    adHoc?.dimension,
    period.preset,
    period.from,
    period.to,
    period.grain,
    refreshToken,
  ])

  return (
    <div className="insights-workspace">
      <PageHeader
        title="Reports"
        subtitle="Saved questions, answered from live data every time they are opened."
        actions={
          <>
            {can('report.export') ? (
              <Button onClick={() => setExporting(true)} disabled={!preview.data}>
                <Download size={14} aria-hidden /> Export
              </Button>
            ) : null}
            {can('report.manage') ? (
              <Button variant="primary" onClick={() => setEditing(true)}>
                <Plus size={14} aria-hidden /> Save this as a report
              </Button>
            ) : null}
          </>
        }
      />

      <ContextBar busy={preview.loading} />

      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 14 }}>
        <button
          type="button"
          className={`insights-chip${selected === null ? ' insights-chip--active' : ''}`}
          aria-pressed={selected === null}
          onClick={() => setSelected(null)}
          style={{ cursor: 'pointer' }}
        >
          Ad-hoc summary
        </button>
        {(list.data?.data ?? []).map((report) => (
          <button
            key={report.id}
            type="button"
            className={`insights-chip${selected?.id === report.id ? ' insights-chip--active' : ''}`}
            aria-pressed={selected?.id === report.id}
            onClick={() => setSelected(report)}
            style={{ cursor: 'pointer' }}
          >
            {report.title}
          </button>
        ))}
      </div>

      {preview.loading && !preview.data ? (
        <Panel>
          <LoadingState label="Running the report…" rows={5} />
        </Panel>
      ) : preview.error ? (
        <Panel>
          <ErrorState detail={preview.error} onRetry={preview.reload} />
        </Panel>
      ) : preview.data ? (
        <>
          <SourceStatusBar sources={preview.data.sources} />

          <Panel className="insights-panel--flush">
            <div style={{ padding: 20, paddingBottom: 12 }}>
              <PanelHeader
                title={preview.data.title}
                subtitle={`${preview.data.scope.company} · ${preview.data.scope.branch} · ${preview.data.period.label}`}
                actions={
                  preview.data.coverage === 'partial' ? <Badge tone="warn">Covers part of the data</Badge> : <Badge tone="ready">Complete</Badge>
                }
              />
            </div>

            {preview.data.rows.length === 0 ? (
              <div style={{ padding: 20 }}>
                <EmptyState
                  title="Nothing to report"
                  detail="The sources answered, and there is nothing in this period matching these filters."
                />
              </div>
            ) : (
              <div className="insights-table-scroll">
                <table className="insights-table">
                  <caption className="insights-sr-only">{preview.data.title}</caption>
                  <thead>
                    <tr>
                      {preview.data.columns.map((column) => (
                        <th key={column.key} scope="col" className={column.type === 'text' ? undefined : 'is-numeric'}>
                          {column.label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {preview.data.rows.map((row, index) => (
                      <tr key={index}>
                        {preview.data!.columns.map((column, columnIndex) => {
                          const formatted = row[`${column.key}_formatted`] ?? row[column.key] ?? '—'

                          return columnIndex === 0 ? (
                            <th key={column.key} scope="row" style={{ fontWeight: 500 }}>
                              {formatted}
                            </th>
                          ) : (
                            <td key={column.key} className="is-numeric">
                              {formatted}
                            </td>
                          )
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <div style={{ padding: 20, paddingTop: 14 }}>
              <WarningList warnings={preview.data.warnings} />

              <details style={{ marginTop: 14 }}>
                <summary style={{ cursor: 'pointer', fontSize: 12.5, color: 'var(--ix-muted)' }}>
                  What these figures mean
                </summary>
                <div style={{ marginTop: 10, display: 'grid', gap: 12 }}>
                  {preview.data.metrics.filter(Boolean).map((metric) => (
                    <div key={metric!.id}>
                      <strong style={{ fontSize: 12.5 }}>{metric!.label}</strong>
                      <p className="insights-muted" style={{ margin: '3px 0 0', fontSize: 12, lineHeight: 1.55 }}>
                        {metric!.definition}
                      </p>
                      <p className="insights-muted" style={{ margin: '3px 0 0', fontSize: 11 }}>
                        {metric!.owning_product} · definition version {metric!.formula_version}
                      </p>
                    </div>
                  ))}
                </div>
              </details>

              <div style={{ marginTop: 16 }}>
                <Facts
                  rows={[
                    { label: 'Generated', value: new Date(preview.data.generated_at).toLocaleString() },
                    { label: 'By', value: preview.data.generated_by },
                    { label: 'Financial year', value: preview.data.scope.financial_year },
                  ]}
                />
              </div>
            </div>
          </Panel>
        </>
      ) : null}

      {selected && selected.is_owner && can('report.manage') ? (
        <div style={{ marginTop: 14 }}>
          <Button
            variant="danger"
            onClick={async () => {
              await reportsApi.remove(selected.id)
              setSelected(null)
              list.reload()
            }}
          >
            <Trash2 size={14} aria-hidden /> Delete this report
          </Button>
        </div>
      ) : null}

      {editing ? (
        <ReportEditor
          initial={config as { title: string; metrics: string[]; dimension: string | null }}
          existing={selected}
          onClose={() => setEditing(false)}
          onSaved={(saved) => {
            setEditing(false)
            setSelected(saved)
            list.reload()
          }}
          onAdHocChange={(next) => setAdHoc(next)}
        />
      ) : null}

      {exporting && preview.data ? (
        <ExportDialog
          title={preview.data.title}
          reportId={selected?.id}
          config={selected ? undefined : (config as Record<string, unknown>)}
          period={period}
          coverage={preview.data.coverage}
          onClose={() => setExporting(false)}
        />
      ) : null}
    </div>
  )
}

function ReportEditor({
  initial,
  existing,
  onClose,
  onSaved,
  onAdHocChange,
}: {
  initial: { title: string; metrics: string[]; dimension: string | null }
  existing: ReportDefinition | null
  onClose: () => void
  onSaved: (report: ReportDefinition) => void
  onAdHocChange: (next: { title: string; metrics: string[]; dimension: string | null }) => void
}) {
  const [title, setTitle] = useState(initial.title)
  const [chosen, setChosen] = useState<string[]>(initial.metrics)
  const [dimension, setDimension] = useState<string | null>(initial.dimension)
  const [visibility, setVisibility] = useState<ReportDefinition['visibility']>(existing?.visibility ?? 'private')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const loadCatalogue = useCallback((signal: AbortSignal) => metricsApi.catalogue(signal), [])
  const catalogue = useApi(loadCatalogue, [])

  async function save() {
    setBusy(true)
    setError(null)
    try {
      const body = {
        title,
        visibility,
        config: { title, metrics: chosen, dimension, formats: ['pdf', 'csv', 'xlsx'] },
      }
      const saved = existing ? await reportsApi.update(existing.id, body) : await reportsApi.create(body)
      onAdHocChange({ title, metrics: chosen, dimension })
      onSaved(saved)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That report could not be saved.')
      setBusy(false)
    }
  }

  const available = [...(catalogue.data?.custom ?? []), ...(catalogue.data?.metrics ?? [])]
  // A dimensional report needs a dimension every chosen metric supports, or a
  // column comes back empty with an explanation nobody asked for.
  const dimensions = Object.entries(catalogue.data?.dimensions ?? {}).filter(([key]) =>
    chosen.every((id) => available.find((metric) => metric.id === id)?.dimensions.includes(key)),
  )

  return (
    <Dialog
      title={existing ? `Edit “${existing.title}”` : 'Save this as a report'}
      description="A report stores the question, not the answer. Opening it asks your connected products again."
      onClose={onClose}
      width={680}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" onClick={() => void save()} busy={busy} disabled={!title.trim() || chosen.length === 0}>
            <Save size={14} aria-hidden /> Save
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
          <label htmlFor="report-title">Name</label>
          <input id="report-title" className="insights-input" value={title} onChange={(event) => setTitle(event.target.value)} data-autofocus />
        </div>

        <div className="insights-field">
          <label htmlFor="report-dimension">Split by</label>
          <select
            id="report-dimension"
            className="insights-select"
            value={dimension ?? ''}
            onChange={(event) => setDimension(event.target.value || null)}
          >
            <option value="">No split — one row per metric</option>
            {dimensions.map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
          {dimension !== null && dimensions.length === 0 ? (
            <span className="insights-hint">
              No dimension is supported by every metric you picked, so this report cannot be split.
            </span>
          ) : null}
        </div>

        <fieldset style={{ border: '1px solid var(--ix-border)', borderRadius: 9, padding: 12, margin: 0 }}>
          <legend style={{ fontSize: 12, fontWeight: 600, color: 'var(--ix-muted)', padding: '0 6px' }}>Metrics</legend>
          <div style={{ maxHeight: 240, overflowY: 'auto', display: 'grid', gap: 5 }}>
            {available.map((metric) => (
              <label key={metric.id} className="insights-checkbox">
                <input
                  type="checkbox"
                  checked={chosen.includes(metric.id)}
                  onChange={(event) =>
                    setChosen((current) =>
                      event.target.checked ? [...current, metric.id] : current.filter((id) => id !== metric.id),
                    )
                  }
                />
                {metric.label}
                <span className="insights-muted" style={{ fontSize: 11 }}>
                  ({metric.owning_product})
                </span>
              </label>
            ))}
          </div>
        </fieldset>

        <div className="insights-field">
          <label htmlFor="report-visibility">Who can open it</label>
          <select
            id="report-visibility"
            className="insights-select"
            value={visibility}
            onChange={(event) => setVisibility(event.target.value as ReportDefinition['visibility'])}
          >
            <option value="private">Only me</option>
            <option value="team">My team</option>
            <option value="organisation">Everyone in this company</option>
          </select>
          <span className="insights-hint">
            Sharing a report shares the question. What it shows each reader is still decided by their own access in
            Smart Books and Inventory.
          </span>
        </div>
      </div>
    </Dialog>
  )
}
