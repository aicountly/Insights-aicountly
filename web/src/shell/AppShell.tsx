import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import {
  AlertTriangle,
  BarChart3,
  Database,
  FileText,
  LayoutDashboard,
  LineChart,
  LogOut,
  Menu,
  MessageSquareText,
  Settings as SettingsIcon,
  ShieldAlert,
  Sigma,
} from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'
import { useInsights } from '../context/InsightsContext'
import { CompanyPicker } from './CompanyPicker'
import { ProductMark } from '../components/ProductMark'
import { ErrorState, LoadingState } from '../ui'

/**
 * The application frame: brand, navigation, company context, and the product
 * area everything else renders into.
 *
 * THE MARK IS THE SHIPPED ONE, wherever it exists. ProductMark resolves the
 * central Console icon exactly as the launcher does, so when the Insights tile
 * is uploaded there it appears here with no change. Nothing is redrawn or
 * recoloured locally: a trademark is not something to improvise.
 *
 * Navigation is permission-aware, but that is a courtesy: the backend asserts
 * every permission before the query. Hiding a link stops somebody wasting a
 * click; it does not stop anybody doing anything.
 */

const NAV = [
  { to: '/', label: 'Overview', icon: BarChart3, exact: true },
  { to: '/dashboards', label: 'Dashboards', icon: LayoutDashboard },
  { to: '/metrics', label: 'Metrics', icon: Sigma, permission: 'metric.view' },
  { to: '/ask', label: 'Ask Insights', icon: MessageSquareText, permission: 'ai.use' },
  { to: '/forecasts', label: 'Forecasts', icon: LineChart, permission: 'forecast.view' },
  { to: '/anomalies', label: 'Exceptions', icon: AlertTriangle, permission: 'anomaly.view' },
  { to: '/reports', label: 'Reports', icon: FileText, permission: 'report.view' },
  { to: '/sources', label: 'Data sources', icon: Database, permission: 'source.view' },
  { to: '/settings', label: 'Settings', icon: SettingsIcon, exact: true },
] as const

function initials(name: string | null | undefined): string {
  if (!name) return '—'

  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

/**
 * The screen for somebody who is signed in but has not been given anything.
 *
 * Named, because the person seeing it cannot fix it themselves.
 */
function NoAccess() {
  return (
    <div className="insights-workspace">
      <div className="insights-panel" style={{ maxWidth: 560, margin: '3rem auto', textAlign: 'center' }}>
        <ShieldAlert size={26} aria-hidden style={{ color: 'var(--warning)' }} />
        <h1 style={{ fontSize: '1.1rem', margin: '0.8rem 0 0.4rem' }}>You have no Insights access yet</h1>
        <p className="insights-muted" style={{ lineHeight: 1.6 }}>
          Your sign-in worked and you can open this company, but nobody has given you an Insights permission
          profile. Ask the company owner to add you under <strong>Settings → Access</strong> in this app.
        </p>
      </div>
    </div>
  )
}

export function AppShell() {
  const { signOut } = useAuth()
  const { session, can, scope, loading, error, reload } = useInsights()
  const location = useLocation()
  const [navOpen, setNavOpen] = useState(false)

  // Signed in, company confirmed, and holding nothing. Distinct from "still
  // loading" (session is null) and from "owner" (holds everything), so it
  // cannot flash up while the session request is in flight.
  const lockedOut = session !== null && !session.is_owner && session.permissions.length === 0

  // Navigating closes the drawer. Leaving it open over the page somebody just
  // asked for is the classic mobile-nav bug.
  useEffect(() => setNavOpen(false), [location.pathname])

  return (
    <div style={{ display: 'flex', minHeight: '100vh', background: 'var(--bg)' }}>
      {navOpen ? (
        <button type="button" className="shell-scrim" aria-label="Close navigation" onClick={() => setNavOpen(false)} />
      ) : null}

      <aside
        className="shell-sidebar"
        data-open={navOpen}
        style={{
          width: 'var(--sidebar-w)',
          flexShrink: 0,
          borderRight: '1px solid var(--border)',
          background: 'var(--surface)',
          display: 'flex',
          flexDirection: 'column',
          position: 'sticky',
          top: 0,
          height: '100vh',
        }}
      >
        <div style={{ padding: '1.1rem 1rem', display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
          <ProductMark size={34} />
          <div style={{ minWidth: 0 }}>
            <div style={{ fontWeight: 700, fontSize: '1.05rem', letterSpacing: '-0.02em', lineHeight: 1.1 }}>Aicountly</div>
            <div style={{ color: 'var(--accent)', fontSize: '0.82rem', fontWeight: 600 }}>Insights</div>
          </div>
        </div>

        <nav style={{ padding: '0.25rem 0.5rem', flex: 1, overflowY: 'auto' }} aria-label="Insights sections">
          {NAV.filter((entry) => !('permission' in entry) || can(entry.permission as string)).map((entry) => {
            const Icon = entry.icon

            return (
              <NavLink
                key={entry.to}
                to={entry.to}
                end={'exact' in entry ? entry.exact : false}
                style={({ isActive }) => ({
                  display: 'flex',
                  alignItems: 'center',
                  gap: '0.65rem',
                  padding: '0.55rem 0.7rem',
                  marginBottom: '0.15rem',
                  borderRadius: 'var(--radius-sm)',
                  color: isActive ? 'var(--accent)' : 'var(--muted)',
                  background: isActive ? 'var(--brand-soft)' : 'transparent',
                  fontWeight: isActive ? 650 : 500,
                  textDecoration: 'none',
                })}
              >
                <Icon size={17} aria-hidden />
                {entry.label}
              </NavLink>
            )
          })}
        </nav>

        <div style={{ padding: '0.75rem', borderTop: '1px solid var(--border)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.55rem', marginBottom: '0.6rem', minWidth: 0 }}>
            <span
              aria-hidden
              style={{
                display: 'grid',
                placeItems: 'center',
                width: 32,
                height: 32,
                flex: 'none',
                borderRadius: 999,
                background: 'var(--brand-soft)',
                color: 'var(--accent)',
                fontWeight: 700,
                fontSize: '0.78rem',
              }}
            >
              {initials(session?.display_name)}
            </span>
            <div style={{ minWidth: 0 }}>
              <div
                style={{
                  fontSize: '0.84rem',
                  fontWeight: 600,
                  overflow: 'hidden',
                  textOverflow: 'ellipsis',
                  whiteSpace: 'nowrap',
                }}
              >
                {session?.display_name ?? '—'}
              </div>
              {session?.is_owner ? <div style={{ color: 'var(--muted)', fontSize: '0.72rem' }}>Company owner</div> : null}
            </div>
          </div>
          <button
            type="button"
            onClick={signOut}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '0.4rem',
              width: '100%',
              padding: '0.45rem 0.55rem',
              background: 'transparent',
              border: '1px solid var(--border-strong)',
              borderRadius: 'var(--radius-sm)',
              cursor: 'pointer',
              color: 'var(--muted)',
            }}
          >
            <LogOut size={14} aria-hidden /> Log out
          </button>
        </div>
      </aside>

      <div style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <header
          style={{
            minHeight: 'var(--header-h)',
            borderBottom: '1px solid var(--border)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '0.5rem 1.25rem',
            gap: '1rem',
            background: 'var(--surface)',
            position: 'sticky',
            top: 0,
            zIndex: 20,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', minWidth: 0 }}>
            <button
              type="button"
              className="shell-sidebar-toggle"
              aria-label={navOpen ? 'Close navigation' : 'Open navigation'}
              aria-expanded={navOpen}
              onClick={() => setNavOpen((open) => !open)}
            >
              <Menu size={18} aria-hidden />
            </button>
            <CompanyPicker />
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <AppLauncher />
          </div>
        </header>

        {/* `insights-ui` is the design-system scope: every page below inherits
            the tokens and components from ui/insights-ui.css, so a dashboard
            and the list it links to cannot drift apart. `key` restarts scroll
            position on navigation, which a sticky header otherwise keeps
            halfway down. */}
        <main className="insights-ui" style={{ flex: 1, minWidth: 0 }} key={location.pathname}>
          {!scope ? (
            <div className="insights-workspace">
              <div className="insights-panel">
                <p className="insights-muted" style={{ margin: 0 }}>
                  Choose a company to begin. Insights answers for one company, branch and financial year at a
                  time, and reads every figure from the products that own it.
                </p>
              </div>
            </div>
          ) : loading && session === null ? (
            <div className="insights-workspace">
              <div className="insights-panel">
                <LoadingState label="Loading your Insights session…" />
              </div>
            </div>
          ) : error ? (
            <div className="insights-workspace">
              <div className="insights-panel">
                <ErrorState title="Insights could not start" detail={error} onRetry={reload} />
              </div>
            </div>
          ) : lockedOut ? (
            <NoAccess />
          ) : (
            <Outlet />
          )}
        </main>
      </div>
    </div>
  )
}
