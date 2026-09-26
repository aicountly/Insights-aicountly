import { useCallback, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Pencil, Share2 } from 'lucide-react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { dashboards as dashboardsApi } from '../services/insights'
import { ContextBar } from '../shell/ContextBar'
import { DashboardCanvas } from '../components/DashboardCanvas'
import { EvidenceDrawer } from '../components/EvidenceDrawer'
import { ShareDialog } from '../components/ShareDialog'
import { SourceStatusBar } from '../components/SourceBadge'
import { Badge, Button, EmptyState, ErrorState, LoadingState, PageHeader, Panel } from '../ui'
import type { Dashboard, MetricValue } from '../services/types'

/**
 * Reading a dashboard.
 *
 * THE LAYOUT AND THE FIGURES ARE TWO CALLS. The layout is cheap and the figures
 * are not, so the frame paints first and the data fills in — and changing the
 * period re-asks only for the data, leaving the board exactly where it was.
 *
 * VIEWERS SEE THE PUBLISHED VERSION. Somebody with edit rights sees their
 * working copy, with a note saying which they are looking at, because
 * "everybody sees my half-finished edit" is the failure a draft state exists to
 * prevent.
 */
export default function DashboardView() {
  const { dashboardId = '' } = useParams()
  const navigate = useNavigate()
  const { period, refreshToken, can } = useInsights()

  const [evidence, setEvidence] = useState<MetricValue | null>(null)
  const [sharing, setSharing] = useState(false)
  const [dashboard, setDashboard] = useState<Dashboard | null>(null)

  const loadDashboard = useCallback(
    async (signal: AbortSignal) => {
      const loaded = await dashboardsApi.show(dashboardId, true, signal)
      setDashboard(loaded)
      return loaded
    },
    [dashboardId],
  )

  const layout = useApi(loadDashboard, [dashboardId, refreshToken])

  const loadData = useCallback(
    (signal: AbortSignal) => dashboardsApi.data(dashboardId, period, true, signal),
    [dashboardId, period],
  )

  const figures = useApi(loadData, [dashboardId, period.preset, period.from, period.to, period.grain, period.compare, refreshToken])

  const canEdit = dashboard !== null && (dashboard.access === 'edit' || dashboard.access === 'manage') && can('dashboard.edit')
  const canShare = dashboard !== null && dashboard.access === 'manage' && can('dashboard.share')

  if (layout.error) {
    return (
      <div className="insights-workspace">
        <Panel>
          <ErrorState
            title="That dashboard could not be opened"
            detail={layout.error}
            onRetry={() => navigate('/dashboards')}
          />
        </Panel>
      </div>
    )
  }

  return (
    <div className="insights-workspace">
      <PageHeader
        eyebrow={dashboard?.viewing === 'published' ? 'Published dashboard' : 'Working copy'}
        title={dashboard?.title ?? 'Dashboard'}
        subtitle={dashboard?.description || undefined}
        actions={
          <>
            <Button onClick={() => navigate('/dashboards')}>All dashboards</Button>
            {canShare ? (
              <Button onClick={() => setSharing(true)}>
                <Share2 size={14} aria-hidden /> Share
              </Button>
            ) : null}
            {canEdit ? (
              <Button variant="primary" onClick={() => navigate(`/dashboards/${dashboardId}/edit`)}>
                <Pencil size={14} aria-hidden /> Edit
              </Button>
            ) : null}
          </>
        }
      />

      <ContextBar busy={figures.loading} />

      {dashboard?.notice ? (
        <div style={{ marginBottom: 14 }}>
          <Badge tone="warn">{dashboard.notice}</Badge>
        </div>
      ) : null}

      {figures.data ? <SourceStatusBar sources={figures.data.sources} /> : null}

      {layout.loading && !dashboard ? (
        <Panel>
          <LoadingState label="Opening the dashboard…" />
        </Panel>
      ) : dashboard && dashboard.widgets.length === 0 ? (
        <Panel>
          <EmptyState
            title="This dashboard is empty"
            detail="Nothing has been added to it yet."
            action={
              canEdit ? (
                <Button variant="primary" onClick={() => navigate(`/dashboards/${dashboardId}/edit`)}>
                  Add some widgets
                </Button>
              ) : null
            }
          />
        </Panel>
      ) : dashboard ? (
        <DashboardCanvas
          widgets={dashboard.widgets}
          rendered={figures.data?.widgets ?? {}}
          sources={figures.data?.sources ?? []}
          loading={figures.loading}
          onOpenEvidence={setEvidence}
        />
      ) : null}

      {figures.error ? (
        <Panel style={{ marginTop: 14 }}>
          <ErrorState title="The figures could not be loaded" detail={figures.error} onRetry={figures.reload} />
        </Panel>
      ) : null}

      {evidence ? <EvidenceDrawer metric={evidence} onClose={() => setEvidence(null)} /> : null}

      {sharing && dashboard ? (
        <ShareDialog
          dashboard={dashboard}
          onClose={() => setSharing(false)}
          onChanged={(shares, visibility) =>
            setDashboard((current) =>
              current ? { ...current, shares, visibility: visibility ?? current.visibility } : current,
            )
          }
        />
      ) : null}
    </div>
  )
}
