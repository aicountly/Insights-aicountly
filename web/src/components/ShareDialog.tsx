import { useState } from 'react'
import { Trash2 } from 'lucide-react'
import { Badge, Button, Dialog } from '../ui'
import { dashboards } from '../services/insights'
import type { Dashboard, DashboardShare } from '../services/types'

/**
 * Who else can open this dashboard.
 *
 * THE SENTENCE AT THE TOP IS THE WHOLE POINT OF THE SCREEN. Sharing a dashboard
 * hands over a CONFIGURATION, not the data it asks for: the recipient's own
 * permissions in Smart Books and Inventory decide what it renders for them. A
 * colleague who cannot see receivables in Books gets the layout with that panel
 * saying so, which is the correct outcome and the one people need told.
 *
 * THERE IS NO PUBLIC LINK. Every share names a person in this company or the
 * company itself. An anonymous link would hand a dashboard to somebody whose
 * source permissions nobody can check — the one thing sharing here must not do.
 */
export function ShareDialog({
  dashboard,
  onClose,
  onChanged,
}: {
  dashboard: Dashboard
  onClose: () => void
  onChanged: (shares: DashboardShare[], visibility?: Dashboard['visibility']) => void
}) {
  const [shares, setShares] = useState<DashboardShare[]>(dashboard.shares)
  const [visibility, setVisibility] = useState(dashboard.visibility)
  const [subjectId, setSubjectId] = useState('')
  const [permission, setPermission] = useState<'view' | 'edit' | 'manage'>('view')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function addShare() {
    const trimmed = subjectId.trim()
    if (!trimmed) {
      setError('Enter the portal id of the person to share with.')
      return
    }

    setBusy(true)
    setError(null)
    try {
      const next = await dashboards.share(dashboard.id, { subject_type: 'user', subject_id: trimmed, permission })
      setShares(next)
      onChanged(next)
      setSubjectId('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That share could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  async function shareWithCompany() {
    setBusy(true)
    setError(null)
    try {
      const next = await dashboards.share(dashboard.id, { subject_type: 'company', permission: 'view' })
      setShares(next)
      onChanged(next)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That share could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  async function removeShare(shareId: string) {
    setBusy(true)
    setError(null)
    try {
      const next = await dashboards.unshare(dashboard.id, shareId)
      setShares(next)
      onChanged(next)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That share could not be removed.')
    } finally {
      setBusy(false)
    }
  }

  async function saveVisibility(next: Dashboard['visibility']) {
    setBusy(true)
    setError(null)
    try {
      await dashboards.save(dashboard.id, { revision: dashboard.revision, visibility: next })
      setVisibility(next)
      onChanged(shares, next)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That could not be saved.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog
      title={`Share “${dashboard.title}”`}
      description="Sharing gives somebody this dashboard's layout. What it shows them is decided by their own access in Smart Books and Inventory — a colleague who cannot see receivables there will see this board with that panel saying so."
      onClose={onClose}
      footer={<Button onClick={onClose}>Done</Button>}
    >
      {error ? (
        <p role="alert" style={{ marginTop: 0, color: 'var(--danger)', fontSize: 13 }}>
          {error}
        </p>
      ) : null}

      <div style={{ display: 'grid', gap: 18 }}>
        <section>
          <h3 style={{ fontSize: 13, margin: '0 0 8px' }}>Who can find it</h3>
          <div className="insights-field">
            <label htmlFor="share-visibility" className="insights-sr-only">
              Visibility
            </label>
            <select
              id="share-visibility"
              className="insights-select"
              value={visibility}
              disabled={busy}
              onChange={(event) => void saveVisibility(event.target.value as Dashboard['visibility'])}
            >
              <option value="private">Private — only me and the people I name below</option>
              <option value="team">Team — the people I name below</option>
              <option value="organisation">Everyone in this company</option>
            </select>
            <span className="insights-hint">
              There is no public link. Every reader is somebody with an AICOUNTLY sign-in in this company.
            </span>
          </div>
        </section>

        <section>
          <h3 style={{ fontSize: 13, margin: '0 0 8px' }}>Share with a colleague</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
            <div className="insights-field" style={{ flex: '1 1 220px' }}>
              <label htmlFor="share-subject">Portal id</label>
              <input
                id="share-subject"
                className="insights-input"
                value={subjectId}
                onChange={(event) => setSubjectId(event.target.value)}
                placeholder="uuid_aictly"
                data-autofocus
              />
            </div>
            <div className="insights-field" style={{ flex: '0 0 150px' }}>
              <label htmlFor="share-permission">They can</label>
              <select
                id="share-permission"
                className="insights-select"
                value={permission}
                onChange={(event) => setPermission(event.target.value as typeof permission)}
              >
                <option value="view">View</option>
                <option value="edit">Edit</option>
                <option value="manage">Manage sharing</option>
              </select>
            </div>
            <Button variant="primary" onClick={() => void addShare()} busy={busy}>
              Share
            </Button>
          </div>
          <p className="insights-muted" style={{ fontSize: 12, marginTop: 8, lineHeight: 1.5 }}>
            Or{' '}
            <button
              type="button"
              className="insights-button insights-button--quiet"
              style={{ padding: 0, minHeight: 0, fontSize: 12 }}
              onClick={() => void shareWithCompany()}
              disabled={busy}
            >
              share read-only with everyone in this company
            </button>
            .
          </p>
        </section>

        <section>
          <h3 style={{ fontSize: 13, margin: '0 0 8px' }}>Shared with</h3>
          {shares.length === 0 ? (
            <p className="insights-muted" style={{ margin: 0, fontSize: 13 }}>
              Nobody yet. Only you can open this.
            </p>
          ) : (
            <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 8 }}>
              {shares.map((share) => (
                <li
                  key={share.id}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: 10,
                    padding: '8px 10px',
                    border: '1px solid var(--ix-border)',
                    borderRadius: 9,
                  }}
                >
                  <span style={{ minWidth: 0, display: 'grid' }}>
                    <span style={{ fontSize: 13, fontWeight: 600, wordBreak: 'break-all' }}>
                      {share.subject_type === 'company' ? 'Everyone in this company' : share.subject_id}
                    </span>
                    <span className="insights-muted" style={{ fontSize: 11.5 }}>
                      Added by {share.granted_by}
                    </span>
                  </span>
                  <span style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 'none' }}>
                    <Badge tone="neutral">{share.permission}</Badge>
                    <Button variant="quiet" onClick={() => void removeShare(share.id)} aria-label="Remove this share" disabled={busy}>
                      <Trash2 size={13} aria-hidden />
                    </Button>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </Dialog>
  )
}
