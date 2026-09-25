import { lazy, Suspense, useEffect } from 'react'
import { createBrowserRouter, Navigate, RouterProvider, useRouteError } from 'react-router-dom'
import { useAuth } from './auth/AuthProvider'
import { InsightsProvider } from './context/InsightsContext'
import { AppShell } from './shell/AppShell'
import SignIn from './pages/SignIn'
import { ErrorState, LoadingState, Panel } from './ui'
import { initAnalytics, trackPageView } from './utils/analytics'
import './ui/insights-ui.css'
import './App.css'

initAnalytics()

/**
 * Routing.
 *
 * EVERY PAGE BUT THE OVERVIEW IS LAZY. Insights is a large app and most visits
 * open one screen; shipping the builder, the report renderer and the copilot in
 * the first bundle would make the overview slower for everybody who never opens
 * them.
 *
 * The portal callback lands on /auth/callback, which the SPA history fallback
 * serves with this same document. AuthProvider consumes the token at boot and
 * clears it from the address bar, so the router never sees it.
 */

const Overview = lazy(() => import('./pages/Overview'))
const DashboardLibrary = lazy(() => import('./pages/DashboardLibrary'))
const DashboardView = lazy(() => import('./pages/DashboardView'))
const DashboardBuilder = lazy(() => import('./pages/DashboardBuilder'))
const Metrics = lazy(() => import('./pages/Metrics'))
const Ask = lazy(() => import('./pages/Ask'))
const Forecasts = lazy(() => import('./pages/Forecasts'))
const Anomalies = lazy(() => import('./pages/Anomalies'))
const Reports = lazy(() => import('./pages/Reports'))
const DataSources = lazy(() => import('./pages/DataSources'))
const Settings = lazy(() => import('./pages/Settings'))

function PageFallback() {
  return (
    <div className="insights-workspace">
      <Panel>
        <LoadingState label="Loading…" rows={4} />
      </Panel>
    </div>
  )
}

/**
 * The router's error boundary.
 *
 * A crash in one page must not take the shell with it — the sidebar, the
 * company picker and the way back stay usable, which is the difference between
 * "that screen is broken" and "the app is broken".
 */
function RouteError() {
  const error = useRouteError()
  const message = error instanceof Error ? error.message : 'Something went wrong drawing this page.'

  return (
    <div className="insights-workspace">
      <Panel>
        <ErrorState
          title="That page could not be drawn"
          detail={message}
          onRetry={() => window.location.reload()}
        />
      </Panel>
    </div>
  )
}

function page(element: React.ReactNode) {
  return <Suspense fallback={<PageFallback />}>{element}</Suspense>
}

const router = createBrowserRouter([
  {
    path: '/',
    element: <AppShell />,
    errorElement: <RouteError />,
    children: [
      { index: true, element: page(<Overview />), errorElement: <RouteError /> },
      { path: 'dashboards', element: page(<DashboardLibrary />), errorElement: <RouteError /> },
      { path: 'dashboards/:dashboardId', element: page(<DashboardView />), errorElement: <RouteError /> },
      { path: 'dashboards/:dashboardId/edit', element: page(<DashboardBuilder />), errorElement: <RouteError /> },
      { path: 'metrics', element: page(<Metrics />), errorElement: <RouteError /> },
      { path: 'ask', element: page(<Ask />), errorElement: <RouteError /> },
      { path: 'forecasts', element: page(<Forecasts />), errorElement: <RouteError /> },
      { path: 'anomalies', element: page(<Anomalies />), errorElement: <RouteError /> },
      { path: 'reports', element: page(<Reports />), errorElement: <RouteError /> },
      { path: 'sources', element: page(<DataSources />), errorElement: <RouteError /> },
      { path: 'settings', element: page(<Settings />), errorElement: <RouteError /> },
      // The portal callback path, reached when the history fallback serves this
      // document there. The token is already consumed by the time the router
      // mounts, so this only has to get the user off it.
      { path: 'auth/callback', element: <Navigate to="/" replace /> },
      { path: '*', element: <Navigate to="/" replace /> },
    ],
  },
])

export default function App() {
  const { status } = useAuth()

  useEffect(() => {
    if (status === 'authenticated') trackPageView(window.location.pathname, document.title)
    else if (status === 'signed-out') trackPageView('/sign-in', 'Sign in')
  }, [status])

  if (status === 'signed-out') return <SignIn />

  if (status !== 'authenticated') {
    return (
      <main className="screen">
        <div className="panel">
          <p className="message">Signing you in…</p>
        </div>
      </main>
    )
  }

  return (
    <InsightsProvider>
      <RouterProvider router={router} />
    </InsightsProvider>
  )
}
