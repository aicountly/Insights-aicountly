import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Copy, LayoutTemplate, Search, Star, Trash2 } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { dashboards as dashboardsApi } from '../services/insights'
import {
  Badge,
  Button,
  Dialog,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  Panel,
  PanelHeader,
} from '../ui'
import type { DashboardSummary, TemplateSummary } from '../services/types'

/**
 * My dashboards, shared with me, team dashboards and templates.
 *
 * A TEMPLATE SAYS WHAT IT NEEDS BEFORE IT IS CREATED. Instantiating one that
 * half works teaches people the product is broken; saying "four of these six
 * panels need Inventory, which is not connected here" teaches them what to do
 * next. That check is a live one, made against this deployment and this viewer.
 */

const SCOPES = [
  { value: 'all', label: 'All' },
  { value: 'mine', label: 'Mine' },
  { value: 'shared', label: 'Shared with me' },
  { value: 'team', label: 'Team' },
] as const

export default function DashboardLibrary() {
  const navigate = useNavigate()
  const { can, refreshToken } = useInsights()
  const [params, setParams] = useSearchParams()

  const [scope, setScope] = useState<string>('all')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [creating, setCreating] = useState(params.get('new') === '1')
  const [confirmDelete, setConfirmDelete] = useState<DashboardSummary | null>(null)
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  // Search is debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => setQuery(search), 280)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(
    (signal: AbortSignal) => dashboardsApi.list({ scope, q: query, limit: 60 }, signal),
    [scope, query],
  )

  const { data, loading, error, reload } = useApi(load, [scope, query, refreshToken])

  async function toggleFavourite(dashboard: DashboardSummary) {
    setBusy(true)
    try {
      await dashboardsApi.favourite(dashboard.id, !dashboard.is_favourite)
      reload()
    } catch (err) {
      setActionError(err instanceof Error ? err.message : 'That could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  async function duplicate(dashboard: DashboardSummary) {
    setBusy(true)
    try {
      const copy = await dashboardsApi.duplicate(dashboard.id)
      navigate(`/dashboards/${copy.id}/edit`)
    } catch (err) {
      setActionError(err instanceof Error ? err.message : 'That could not be copied.')
    } finally {
      setBusy(false)
    }
  }

  async function remove(dashboard: DashboardSummary) {
    setBusy(true)
    try {
      await dashboardsApi.remove(dashboard.id)
      setConfirmDelete(null)
      reload()
    } catch (err) {
      setActionError(err instanceof Error ? err.message : 'That could not be deleted.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="insights-workspace">
      <PageHeader
        title="Dashboards"
        subtitle="Your own boards, the ones shared with you, and the templates to start from."
        actions={
          can('dashboard.create') ? (
            <Button
              variant="primary"
              onClick={() => {
                setCreating(true)
                setParams({ new: '1' })
              }}
            >
              Create dashboard
            </Button>
          ) : null
        }
      />

      <div className="insights-context">
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }} role="group" aria-label="Which dashboards">
          {SCOPES.map((entry) => (
            <button
              key={entry.value}
              type="button"
              className={`insights-chip${scope === entry.value ? ' insights-chip--active' : ''}`}
              aria-pressed={scope === entry.value}
              onClick={() => setScope(entry.value)}
              style={{ cursor: 'pointer' }}
            >
              {entry.label}
            </button>
          ))}
        </div>

        <div style={{ position: 'relative', flex: '1 1 220px', minWidth: 180 }}>
          <Search
            size={14}
            aria-hidden
            style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--ix-muted)' }}
          />
          <input
            type="search"
            className="insights-input"
            style={{ paddingLeft: 30 }}
            placeholder="Search by name or description"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            aria-label="Search dashboards"
          />
        </div>
      </div>

      {actionError ? (
        <Panel>
          <ErrorState detail={actionError} onRetry={() => setActionError(null)} />
        </Panel>
      ) : null}

      {loading && !data ? (
        <Panel>
          <LoadingState label="Loading your dashboards…" />
        </Panel>
      ) : error ? (
        <Panel>
          <ErrorState detail={error} onRetry={reload} />
        </Panel>
      ) : data && data.data.length === 0 ? (
        <Panel>
          <EmptyState
            title={query ? 'Nothing matches that search' : 'No dashboards yet'}
            detail={
              query
                ? 'Try a shorter search, or switch to All.'
                : 'Start from a template — each one says which of your connected products it needs before you create it.'
            }
            action={
              can('dashboard.create') ? (
                <Button variant="primary" onClick={() => setCreating(true)}>
                  Create one
                </Button>
              ) : null
            }
          />
        </Panel>
      ) : (
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))',
            gap: 14,
          }}
        >
          {data?.data.map((dashboard) => (
            <article key={dashboard.id} className="insights-panel" style={{ display: 'grid', gap: 10, alignContent: 'start' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'flex-start' }}>
                <button
                  type="button"
                  className="insights-button insights-button--quiet"
                  style={{ padding: 0, minHeight: 0, fontSize: 15, fontWeight: 650, color: 'var(--ix-text)', textAlign: 'left' }}
                  onClick={() => navigate(`/dashboards/${dashboard.id}`)}
                >
                  {dashboard.title}
                </button>
                <Button
                  variant="quiet"
                  onClick={() => void toggleFavourite(dashboard)}
                  aria-label={dashboard.is_favourite ? 'Remove from favourites' : 'Add to favourites'}
                  aria-pressed={dashboard.is_favourite}
                  disabled={busy}
                >
                  <Star
                    size={15}
                    aria-hidden
                    fill={dashboard.is_favourite ? 'var(--warning)' : 'none'}
                    style={{ color: dashboard.is_favourite ? 'var(--warning)' : 'var(--ix-muted)' }}
                  />
                </Button>
              </div>

              {dashboard.description ? (
                <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.5 }}>
                  {dashboard.description}
                </p>
              ) : null}

              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <Badge tone="neutral">{dashboard.widget_count ?? 0} widgets</Badge>
                <Badge tone={dashboard.visibility === 'private' ? 'neutral' : 'info'}>
                  {dashboard.visibility === 'organisation'
                    ? 'Everyone here'
                    : dashboard.visibility === 'team'
                      ? 'Team'
                      : 'Private'}
                </Badge>
                {dashboard.published_at ? <Badge tone="ready">Published</Badge> : <Badge tone="warn">Draft only</Badge>}
                {!dashboard.is_owner ? <Badge tone="info">Shared with you</Badge> : null}
              </div>

              {dashboard.tags.length > 0 ? (
                <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' }}>
                  {dashboard.tags.map((tag) => (
                    <span key={tag} className="insights-chip">
                      {tag}
                    </span>
                  ))}
                </div>
              ) : null}

              <p className="insights-muted" style={{ margin: 0, fontSize: 11.5 }}>
                Updated {new Date(dashboard.updated_at).toLocaleDateString()}
              </p>

              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <Button onClick={() => navigate(`/dashboards/${dashboard.id}`)}>Open</Button>
                {can('dashboard.edit') ? (
                  <Button onClick={() => navigate(`/dashboards/${dashboard.id}/edit`)}>Edit</Button>
                ) : null}
                {can('dashboard.create') ? (
                  <Button variant="quiet" onClick={() => void duplicate(dashboard)} aria-label="Duplicate" disabled={busy}>
                    <Copy size={13} aria-hidden />
                  </Button>
                ) : null}
                {dashboard.is_owner && can('dashboard.delete') ? (
                  <Button variant="quiet" onClick={() => setConfirmDelete(dashboard)} aria-label="Delete" disabled={busy}>
                    <Trash2 size={13} aria-hidden />
                  </Button>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      )}

      {creating ? (
        <TemplateChooser
          onClose={() => {
            setCreating(false)
            setParams({})
          }}
        />
      ) : null}

      {confirmDelete ? (
        <Dialog
          title={`Delete “${confirmDelete.title}”?`}
          description="Anybody it is shared with loses access immediately. This cannot be undone from here."
          onClose={() => setConfirmDelete(null)}
          width={480}
          footer={
            <>
              <Button onClick={() => setConfirmDelete(null)}>Keep it</Button>
              <Button variant="danger" onClick={() => void remove(confirmDelete)} busy={busy}>
                Delete
              </Button>
            </>
          }
        >
          <p className="insights-muted" style={{ margin: 0, fontSize: 13, lineHeight: 1.6 }}>
            The dashboard holds no business data — it is a set of questions — so nothing is lost from Smart Books or
            Inventory. What goes is the layout, its revisions and its sharing.
          </p>
        </Dialog>
      ) : null}
    </div>
  )
}

/** Start from a template, having been told what it needs. */
function TemplateChooser({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [blankTitle, setBlankTitle] = useState('')

  const load = useCallback((signal: AbortSignal) => dashboardsApi.templates(signal), [])
  const { data, loading, error: loadError } = useApi(load, [])

  async function create(template?: TemplateSummary) {
    setBusy(true)
    setError(null)
    try {
      const created = await dashboardsApi.create(
        template
          ? { template_key: template.key, title: template.title }
          : { title: blankTitle.trim() || 'New dashboard', widgets: [] },
      )
      navigate(`/dashboards/${created.id}/edit`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That could not be created.')
      setBusy(false)
    }
  }

  return (
    <Dialog
      title="Create a dashboard"
      description="Start blank, or from a template. Templates carry configuration only — no template contains a sample figure."
      onClose={onClose}
      width={760}
      footer={<Button onClick={onClose}>Cancel</Button>}
    >
      {error ? (
        <p role="alert" style={{ marginTop: 0, color: 'var(--danger)', fontSize: 13 }}>
          {error}
        </p>
      ) : null}

      <section style={{ marginBottom: 18 }}>
        <PanelHeader title="Start blank" />
        <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <div className="insights-field" style={{ flex: '1 1 240px' }}>
            <label htmlFor="blank-title">Name</label>
            <input
              id="blank-title"
              className="insights-input"
              value={blankTitle}
              placeholder="New dashboard"
              onChange={(event) => setBlankTitle(event.target.value)}
              data-autofocus
            />
          </div>
          <Button variant="primary" onClick={() => void create()} busy={busy}>
            Create
          </Button>
        </div>
      </section>

      <section>
        <PanelHeader title="From a template" subtitle="Each one is checked against what this deployment can actually answer." />

        {loading ? <LoadingState label="Checking what your templates need…" /> : null}
        {loadError ? <ErrorState detail={loadError} /> : null}

        <div style={{ display: 'grid', gap: 10 }}>
          {data?.templates.map((template) => (
            <article
              key={template.key}
              style={{ border: '1px solid var(--ix-border)', borderRadius: 11, padding: 14, display: 'grid', gap: 8 }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'flex-start' }}>
                <div style={{ minWidth: 0 }}>
                  <h3 style={{ margin: 0, fontSize: 14, fontWeight: 650 }}>
                    <LayoutTemplate size={14} aria-hidden style={{ verticalAlign: -2, marginRight: 6, color: 'var(--ix-green-strong)' }} />
                    {template.title}
                  </h3>
                  <p className="insights-muted" style={{ margin: '4px 0 0', fontSize: 12.5, lineHeight: 1.5 }}>
                    {template.description}
                  </p>
                </div>
                <Button variant="primary" onClick={() => void create(template)} busy={busy} style={{ flex: 'none' }}>
                  Use this
                </Button>
              </div>

              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                <Badge tone="neutral">{template.audience}</Badge>
                <Badge tone="neutral">{template.widget_count} widgets</Badge>
                {template.requirements.ready ? (
                  <Badge tone="ready">Every panel will work here</Badge>
                ) : (
                  <Badge tone="warn">{template.requirements.unavailable_widgets.length} panels will not work yet</Badge>
                )}
              </div>

              {template.requirements.unavailable_widgets.length > 0 ? (
                <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12, color: 'var(--ix-muted)', lineHeight: 1.55 }}>
                  {template.requirements.unavailable_widgets.slice(0, 4).map((entry, index) => (
                    <li key={index}>
                      <strong>{entry.title}</strong> — {entry.reason}
                    </li>
                  ))}
                </ul>
              ) : null}
            </article>
          ))}
        </div>

        {data ? (
          <p className="insights-muted" style={{ fontSize: 11.5, marginTop: 12, lineHeight: 1.5 }}>
            {data.note}
          </p>
        ) : null}
      </section>
    </Dialog>
  )
}
