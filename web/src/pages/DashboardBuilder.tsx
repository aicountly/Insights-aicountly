import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useBlocker, useNavigate, useParams } from 'react-router-dom'
import { Eye, History, Redo2, Save, Share2, Undo2, Upload } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { dashboards as dashboardsApi, metrics as metricsApi } from '../services/insights'
import { ApiError, isStale } from '../services/api'
import { ContextBar } from '../shell/ContextBar'
import { DashboardCanvas, moveWidget, resizeWidget } from '../components/DashboardCanvas'
import { WidgetPicker } from '../components/WidgetPicker'
import { WidgetProperties } from '../components/WidgetProperties'
import { ShareDialog } from '../components/ShareDialog'
import { EvidenceDrawer } from '../components/EvidenceDrawer'
import { SourceStatusBar } from '../components/SourceBadge'
import { Badge, Button, Dialog, Drawer, ErrorState, LoadingState, PageHeader, Panel, PanelHeader } from '../ui'
import type { Dashboard, DashboardData, DashboardSettings, MetricValue, Widget } from '../services/types'

/**
 * The dashboard builder.
 *
 * FOUR THINGS IT IS CAREFUL ABOUT, and each one is how a builder loses somebody's
 * afternoon:
 *
 *  1. UNSAVED WORK IS PROTECTED. Navigating away with changes asks first, and
 *     so does closing the tab. A builder that silently discards twenty minutes
 *     of layout is a builder people stop using.
 *
 *  2. UNDO AND REDO ARE REAL. Every change pushes onto a history stack, so a
 *     mis-drag is one keystroke to fix rather than a reload.
 *
 *  3. THE PREVIEW VALIDATES EXACTLY AS THE SAVE DOES. The canvas is drawn from
 *     `/dashboards/preview`, which runs each widget through the same validator
 *     the save uses — so a board that previews is a board that will save, and
 *     one that will not says why while the work is still on screen.
 *
 *  4. SAVING CARRIES THE REVISION IT READ. Two people editing one board is
 *     normal; the second save silently discarding the first is not. A stale
 *     save comes back as a conflict naming who changed it.
 */

const AUTOSAVE_NOTE =
  'Nothing is saved until you press Save. Publishing is separate — viewers keep seeing the last published version until you do.'

interface HistoryEntry {
  widgets: Widget[]
  settings: DashboardSettings
  title: string
  description: string
}

export default function DashboardBuilder() {
  const { dashboardId = '' } = useParams()
  const navigate = useNavigate()
  const { period, refreshToken, can } = useInsights()

  const [dashboard, setDashboard] = useState<Dashboard | null>(null)
  const [widgets, setWidgets] = useState<Widget[]>([])
  const [settings, setSettings] = useState<DashboardSettings | null>(null)
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const [history, setHistory] = useState<HistoryEntry[]>([])
  const [future, setFuture] = useState<HistoryEntry[]>([])
  const [dirty, setDirty] = useState(false)

  const [preview, setPreview] = useState<DashboardData | null>(null)
  const [previewing, setPreviewing] = useState(false)
  const [previewError, setPreviewError] = useState<string | null>(null)

  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [conflict, setConflict] = useState<Record<string, unknown> | null>(null)

  const [sharing, setSharing] = useState(false)
  const [showVersions, setShowVersions] = useState(false)
  const [propertiesOpen, setPropertiesOpen] = useState(false)
  const [evidence, setEvidence] = useState<MetricValue | null>(null)
  const [readOnlyPreview, setReadOnlyPreview] = useState(false)

  const nextWidgetId = useRef(1)

  // ------------------------------------------------------------------ load

  const loadDashboard = useCallback(
    async (signal: AbortSignal) => {
      const loaded = await dashboardsApi.show(dashboardId, false, signal)
      setDashboard(loaded)
      setWidgets(loaded.widgets)
      setSettings(loaded.settings)
      setTitle(loaded.title)
      setDescription(loaded.description)
      setHistory([])
      setFuture([])
      setDirty(false)
      return loaded
    },
    [dashboardId],
  )

  const layout = useApi(loadDashboard, [dashboardId, refreshToken])

  const loadCatalogue = useCallback((signal: AbortSignal) => metricsApi.catalogue(signal), [])
  const catalogue = useApi(loadCatalogue, [])

  // ------------------------------------------------------- unsaved changes

  // The browser's own guard, for closing the tab or following an external link.
  useEffect(() => {
    if (!dirty) return undefined

    function onBeforeUnload(event: BeforeUnloadEvent) {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [dirty])

  // The router's guard, for navigating inside the app.
  const blocker = useBlocker(useCallback(() => dirty && !saving, [dirty, saving]))

  // ------------------------------------------------------------------ edit

  const pushHistory = useCallback(() => {
    if (!settings) return
    setHistory((stack) => [...stack.slice(-29), { widgets, settings, title, description }])
    setFuture([])
    setDirty(true)
  }, [description, settings, title, widgets])

  const applyEntry = useCallback((entry: HistoryEntry) => {
    setWidgets(entry.widgets)
    setSettings(entry.settings)
    setTitle(entry.title)
    setDescription(entry.description)
  }, [])

  const undo = useCallback(() => {
    setHistory((stack) => {
      if (stack.length === 0 || !settings) return stack
      const previous = stack[stack.length - 1]
      setFuture((redo) => [{ widgets, settings, title, description }, ...redo])
      applyEntry(previous)
      setDirty(true)
      return stack.slice(0, -1)
    })
  }, [applyEntry, description, settings, title, widgets])

  const redo = useCallback(() => {
    setFuture((stack) => {
      if (stack.length === 0 || !settings) return stack
      const [next, ...rest] = stack
      setHistory((undoStack) => [...undoStack, { widgets, settings, title, description }])
      applyEntry(next)
      setDirty(true)
      return rest
    })
  }, [applyEntry, description, settings, title, widgets])

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      const modifier = event.metaKey || event.ctrlKey
      if (!modifier) return

      if (event.key === 'z' && !event.shiftKey) {
        event.preventDefault()
        undo()
      } else if ((event.key === 'z' && event.shiftKey) || event.key === 'y') {
        event.preventDefault()
        redo()
      } else if (event.key === 's') {
        event.preventDefault()
        void save()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [undo, redo, widgets, settings, title, description])

  function addWidget(widgetType: string) {
    pushHistory()
    const id = `new-${nextWidgetId.current++}`
    const spec = catalogue.data?.widget_types[widgetType]

    setWidgets((current) => [
      ...current,
      {
        id,
        widget_type: widgetType,
        title: spec?.label ?? 'New widget',
        description: '',
        config: spec?.needs_metric ? { metric_id: 'finance.net_revenue' } : {},
        layout: {
          desktop: { x: 0, y: 0, w: widgetType === 'kpi' ? 3 : 6, h: widgetType === 'kpi' ? 2 : 4 },
          tablet: { x: 0, y: 0, w: widgetType === 'kpi' ? 2 : 6, h: widgetType === 'kpi' ? 2 : 4 },
          mobile: { x: 0, y: 0, w: 1, h: widgetType === 'kpi' ? 2 : 4 },
        },
        position: current.length,
      },
    ])
    setSelectedId(id)
    setPropertiesOpen(true)
  }

  function updateWidget(next: Widget) {
    pushHistory()
    setWidgets((current) => current.map((widget) => (widget.id === next.id ? next : widget)))
  }

  function duplicateWidget(widgetId: string) {
    pushHistory()
    setWidgets((current) => {
      const index = current.findIndex((widget) => widget.id === widgetId)
      if (index === -1) return current
      const copy = { ...current[index], id: `new-${nextWidgetId.current++}`, title: `${current[index].title} (copy)` }
      const next = [...current]
      next.splice(index + 1, 0, copy)
      return next.map((widget, position) => ({ ...widget, position }))
    })
  }

  function removeWidget(widgetId: string) {
    pushHistory()
    setWidgets((current) => current.filter((widget) => widget.id !== widgetId).map((widget, position) => ({ ...widget, position })))
    setSelectedId((current) => (current === widgetId ? null : current))
  }

  // --------------------------------------------------------------- preview

  const previewBody = useMemo(
    () => ({ widgets: widgets.map((widget) => ({ ...widget })), settings: settings ?? {} }),
    [widgets, settings],
  )

  useEffect(() => {
    if (!settings) return undefined

    // Debounced: dragging a widget must not fire a request per frame, and the
    // preview asks five products.
    const timer = setTimeout(() => {
      const controller = new AbortController()
      setPreviewing(true)
      setPreviewError(null)

      dashboardsApi
        .preview(previewBody, controller.signal)
        .then((result) => setPreview(result))
        .catch((error: Error) => {
          if (isStale(error)) return
          setPreviewError(error.message)
        })
        .finally(() => setPreviewing(false))
    }, 450)

    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [previewBody, period.preset, period.from, period.to, period.grain, period.compare, refreshToken])

  // ------------------------------------------------------------------ save

  async function save(): Promise<boolean> {
    if (!dashboard || !settings) return false

    setSaving(true)
    setSaveError(null)
    setConflict(null)

    try {
      const saved = await dashboardsApi.save(dashboard.id, {
        revision: dashboard.revision,
        title,
        description,
        settings,
        widgets: widgets.map((widget, position) => ({ ...widget, position })),
      })

      setDashboard(saved)
      setWidgets(saved.widgets)
      setDirty(false)
      setHistory([])
      setFuture([])
      return true
    } catch (error) {
      if (error instanceof ApiError && error.status === 409) {
        setConflict(error.details)
      } else {
        setSaveError(error instanceof Error ? error.message : 'That could not be saved.')
      }
      return false
    } finally {
      setSaving(false)
    }
  }

  async function publish() {
    const savedOk = dirty ? await save() : true
    if (!savedOk || !dashboard) return

    setSaving(true)
    try {
      const published = await dashboardsApi.publish(dashboard.id)
      setDashboard(published)
    } catch (error) {
      setSaveError(error instanceof Error ? error.message : 'That could not be published.')
    } finally {
      setSaving(false)
    }
  }

  const selected = widgets.find((widget) => widget.id === selectedId) ?? null
  const canManage = dashboard?.access === 'manage'

  // ---------------------------------------------------------------- render

  if (layout.error) {
    return (
      <div className="insights-workspace">
        <Panel>
          <ErrorState title="That dashboard could not be opened for editing" detail={layout.error} onRetry={() => navigate('/dashboards')} />
        </Panel>
      </div>
    )
  }

  if (!dashboard || !settings || !catalogue.data) {
    return (
      <div className="insights-workspace">
        <Panel>
          <LoadingState label="Opening the builder…" rows={4} />
        </Panel>
      </div>
    )
  }

  return (
    <div className="insights-workspace">
      <PageHeader
        eyebrow="Dashboard builder"
        title={title || 'Untitled dashboard'}
        subtitle={AUTOSAVE_NOTE}
        actions={
          <>
            <Button onClick={undo} disabled={history.length === 0} aria-label="Undo">
              <Undo2 size={14} aria-hidden /> Undo
            </Button>
            <Button onClick={redo} disabled={future.length === 0} aria-label="Redo">
              <Redo2 size={14} aria-hidden /> Redo
            </Button>
            <Button onClick={() => setReadOnlyPreview((open) => !open)}>
              <Eye size={14} aria-hidden /> {readOnlyPreview ? 'Back to editing' : 'Preview'}
            </Button>
            <Button onClick={() => setShowVersions(true)}>
              <History size={14} aria-hidden /> History
            </Button>
            {canManage && can('dashboard.share') ? (
              <Button onClick={() => setSharing(true)}>
                <Share2 size={14} aria-hidden /> Share
              </Button>
            ) : null}
            {can('dashboard.publish') ? (
              <Button onClick={() => void publish()} busy={saving}>
                <Upload size={14} aria-hidden /> Publish
              </Button>
            ) : null}
            <Button variant="primary" onClick={() => void save()} busy={saving} disabled={!dirty}>
              <Save size={14} aria-hidden /> {dirty ? 'Save' : 'Saved'}
            </Button>
          </>
        }
      />

      <ContextBar busy={previewing} />

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', marginBottom: 14 }}>
        <Badge tone={dirty ? 'warn' : 'ready'}>{dirty ? 'Unsaved changes' : 'All changes saved'}</Badge>
        <Badge tone="neutral">Revision {dashboard.revision}</Badge>
        {dashboard.published_at ? (
          <Badge tone="ready">Published {new Date(dashboard.published_at).toLocaleDateString()}</Badge>
        ) : (
          <Badge tone="warn">Never published — viewers see nothing yet</Badge>
        )}
        {preview?.rejected && preview.rejected.length > 0 ? (
          <Badge tone="danger">{preview.rejected.length} widget(s) will not save</Badge>
        ) : null}
      </div>

      {saveError ? (
        <Panel style={{ marginBottom: 14 }}>
          <ErrorState title="That could not be saved" detail={saveError} onRetry={() => void save()} />
        </Panel>
      ) : null}

      {preview?.rejected && preview.rejected.length > 0 ? (
        <Panel style={{ marginBottom: 14 }}>
          <PanelHeader title="These widgets need fixing before this will save" />
          <ul style={{ margin: 0, paddingLeft: 18, fontSize: 13, lineHeight: 1.6 }}>
            {preview.rejected.map((problem, index) => (
              <li key={index}>
                <strong>{problem.field}</strong>: {problem.message}
              </li>
            ))}
          </ul>
        </Panel>
      ) : null}

      {preview ? <SourceStatusBar sources={preview.sources} /> : null}

      {readOnlyPreview ? (
        <DashboardCanvas
          widgets={widgets}
          rendered={preview?.widgets ?? {}}
          sources={preview?.sources ?? []}
          loading={previewing}
          onOpenEvidence={setEvidence}
        />
      ) : (
        <div className="insights-builder">
          <Panel style={{ position: 'sticky', top: 90 }}>
            <PanelHeader title="Widgets" />
            <WidgetPicker types={catalogue.data.widget_types} onAdd={addWidget} disabled={widgets.length >= 40} />

            <div style={{ marginTop: 18, paddingTop: 14, borderTop: '1px solid var(--ix-border)', display: 'grid', gap: 12 }}>
              <PanelHeader title="Dashboard" />
              <div className="insights-field">
                <label htmlFor="dashboard-title">Name</label>
                <input
                  id="dashboard-title"
                  className="insights-input"
                  value={title}
                  onChange={(event) => {
                    pushHistory()
                    setTitle(event.target.value)
                  }}
                />
              </div>
              <div className="insights-field">
                <label htmlFor="dashboard-description">Description</label>
                <textarea
                  id="dashboard-description"
                  className="insights-textarea"
                  value={description}
                  onChange={(event) => {
                    pushHistory()
                    setDescription(event.target.value)
                  }}
                />
              </div>
            </div>
          </Panel>

          <div className="insights-canvas">
            {widgets.length === 0 ? (
              <p className="insights-muted" style={{ textAlign: 'center', padding: '60px 20px', margin: 0, lineHeight: 1.6 }}>
                Empty. Add a widget from the left, then choose what it shows.
                <br />
                <span style={{ fontSize: 12 }}>
                  Focus a widget and use the arrow keys to move it, or shift and the arrow keys to resize it.
                </span>
              </p>
            ) : (
              <DashboardCanvas
                widgets={widgets}
                rendered={preview?.widgets ?? {}}
                sources={preview?.sources ?? []}
                loading={previewing}
                editable
                selectedId={selectedId}
                onOpenEvidence={setEvidence}
                handlers={{
                  onSelect: (id) => setSelectedId(id),
                  onMove: (id, direction) => {
                    pushHistory()
                    setWidgets((current) => moveWidget(current, id, direction))
                  },
                  onResize: (id, breakpoint, delta) => {
                    pushHistory()
                    setWidgets((current) => resizeWidget(current, id, breakpoint, delta))
                  },
                  onDuplicate: duplicateWidget,
                  onRemove: removeWidget,
                }}
              />
            )}

            {previewError ? (
              <p className="insights-muted" style={{ marginTop: 14, fontSize: 12.5 }}>
                The preview could not be refreshed: {previewError}
              </p>
            ) : null}
          </div>

          {/* Above 1200px the properties sit beside the canvas; below it they
              become a drawer, because a three-column builder on a laptop leaves
              no canvas to build on. */}
          <Panel className="builder-properties" style={{ position: 'sticky', top: 90 }}>
            <PanelHeader title="Properties" subtitle={selected ? undefined : 'Select a widget to configure it.'} />
            {selected ? (
              <WidgetProperties
                widget={selected}
                catalogue={catalogue.data}
                onChange={updateWidget}
                onRemove={() => removeWidget(selected.id)}
                onDuplicate={() => duplicateWidget(selected.id)}
              />
            ) : (
              <p className="insights-muted" style={{ fontSize: 13, lineHeight: 1.6 }}>
                Click a widget on the canvas, or add one from the left.
              </p>
            )}
          </Panel>
        </div>
      )}

      {propertiesOpen && selected && window.innerWidth <= 1200 ? (
        <Drawer title="Widget properties" onClose={() => setPropertiesOpen(false)}>
          <WidgetProperties
            widget={selected}
            catalogue={catalogue.data}
            onChange={updateWidget}
            onRemove={() => {
              removeWidget(selected.id)
              setPropertiesOpen(false)
            }}
            onDuplicate={() => duplicateWidget(selected.id)}
          />
        </Drawer>
      ) : null}

      {evidence ? <EvidenceDrawer metric={evidence} onClose={() => setEvidence(null)} /> : null}

      {sharing ? (
        <ShareDialog
          dashboard={dashboard}
          onClose={() => setSharing(false)}
          onChanged={(shares, visibility) =>
            setDashboard((current) => (current ? { ...current, shares, visibility: visibility ?? current.visibility } : current))
          }
        />
      ) : null}

      {showVersions ? (
        <VersionsDialog
          dashboardId={dashboard.id}
          currentRevision={dashboard.revision}
          onClose={() => setShowVersions(false)}
          onRestored={(restored) => {
            setDashboard(restored)
            setWidgets(restored.widgets)
            setSettings(restored.settings)
            setTitle(restored.title)
            setDescription(restored.description)
            setDirty(false)
            setHistory([])
            setFuture([])
            setShowVersions(false)
          }}
        />
      ) : null}

      {conflict ? (
        <Dialog
          title="Somebody else saved this first"
          description="Your changes are still on screen and have not been lost."
          onClose={() => setConflict(null)}
          width={520}
          footer={
            <>
              <Button onClick={() => setConflict(null)}>Keep editing</Button>
              <Button
                variant="primary"
                onClick={() => {
                  setConflict(null)
                  layout.reload()
                }}
              >
                Reload theirs
              </Button>
            </>
          }
        >
          <p className="insights-muted" style={{ margin: 0, fontSize: 13, lineHeight: 1.6 }}>
            You were editing revision {String(conflict.expected_revision)} and the dashboard is now at revision{' '}
            {String(conflict.current_revision)}, saved by {String(conflict.updated_by ?? 'somebody else')}. Reloading
            replaces what is on screen with theirs — copy anything you want to keep first.
          </p>
        </Dialog>
      ) : null}

      {blocker.state === 'blocked' ? (
        <Dialog
          title="You have unsaved changes"
          description="Leaving now discards them."
          onClose={() => blocker.reset?.()}
          width={480}
          footer={
            <>
              <Button onClick={() => blocker.reset?.()}>Stay here</Button>
              <Button
                variant="primary"
                onClick={async () => {
                  const ok = await save()
                  if (ok) blocker.proceed?.()
                }}
                busy={saving}
              >
                Save and leave
              </Button>
              <Button variant="danger" onClick={() => blocker.proceed?.()}>
                Discard
              </Button>
            </>
          }
        >
          <p className="insights-muted" style={{ margin: 0, fontSize: 13, lineHeight: 1.6 }}>
            This dashboard has changes that have not been saved. Saving keeps them and leaves the published version
            alone until you publish.
          </p>
        </Dialog>
      ) : null}
    </div>
  )
}

function VersionsDialog({
  dashboardId,
  currentRevision,
  onClose,
  onRestored,
}: {
  dashboardId: string
  currentRevision: number
  onClose: () => void
  onRestored: (dashboard: Dashboard) => void
}) {
  const load = useCallback((signal: AbortSignal) => dashboardsApi.versions(dashboardId, signal), [dashboardId])
  const { data, loading, error } = useApi(load, [dashboardId])
  const [busy, setBusy] = useState<number | null>(null)
  const [failure, setFailure] = useState<string | null>(null)

  async function restore(revision: number) {
    setBusy(revision)
    setFailure(null)
    try {
      onRestored(await dashboardsApi.restore(dashboardId, revision))
    } catch (err) {
      setFailure(err instanceof Error ? err.message : 'That revision could not be restored.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <Dialog
      title="Revision history"
      description="Restoring brings back a layout. It does not bring back who could see it — sharing set since then stays as it is."
      onClose={onClose}
      width={620}
      footer={<Button onClick={onClose}>Close</Button>}
    >
      {loading ? <LoadingState label="Loading the history…" /> : null}
      {error ? <ErrorState detail={error} /> : null}
      {failure ? (
        <p role="alert" style={{ color: 'var(--danger)', fontSize: 13 }}>
          {failure}
        </p>
      ) : null}

      <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 8 }}>
        {(data ?? []).map((version) => (
          <li
            key={version.id}
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              gap: 10,
              padding: '9px 11px',
              border: '1px solid var(--ix-border)',
              borderRadius: 9,
            }}
          >
            <span style={{ display: 'grid', minWidth: 0 }}>
              <span style={{ fontSize: 13, fontWeight: 600 }}>
                Revision {version.revision}
                {version.label ? ` — ${version.label}` : ''}
              </span>
              <span className="insights-muted" style={{ fontSize: 11.5 }}>
                {new Date(version.created_at).toLocaleString()} by {version.created_by}
              </span>
            </span>
            <span style={{ display: 'flex', gap: 7, alignItems: 'center', flex: 'none' }}>
              {version.is_published ? <Badge tone="ready">Published</Badge> : null}
              {version.revision === currentRevision ? (
                <Badge tone="neutral">Current</Badge>
              ) : (
                <Button onClick={() => void restore(version.revision)} busy={busy === version.revision}>
                  Restore
                </Button>
              )}
            </span>
          </li>
        ))}
      </ul>
    </Dialog>
  )
}
