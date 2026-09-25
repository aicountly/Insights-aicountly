/**
 * Every call this app makes to its own API, in one place.
 *
 * Pages import from here rather than composing URLs, so a route change is one
 * edit and a page cannot accidentally ask for something unscoped.
 */

import { api } from './api'
import type {
  AiResponse,
  AiStatus,
  AnomalyPayload,
  CashFlowPayload,
  Dashboard,
  DashboardData,
  DashboardShare,
  DashboardSummary,
  DashboardVersion,
  ForecastPayload,
  InsightsSession,
  MetricDefinitionRow,
  MetricValue,
  OverviewPayload,
  Preferences,
  ReportDefinition,
  ReportResult,
  SourceCapability,
  SourceRow,
  Widget,
  WidgetTypeSpec,
} from './types'

export interface PeriodQuery {
  preset?: string
  from?: string | null
  to?: string | null
  grain?: string
  compare?: string
}

/** Drop the keys the API treats as "not supplied". */
function periodParams(period: PeriodQuery | undefined): Record<string, string> {
  const out: Record<string, string> = {}
  if (!period) return out
  if (period.preset) out.preset = period.preset
  if (period.from) out.from = period.from
  if (period.to) out.to = period.to
  if (period.grain) out.grain = period.grain
  if (period.compare) out.compare = period.compare
  return out
}

// ---------------------------------------------------------------------------
// Session and settings
// ---------------------------------------------------------------------------

export const session = {
  load: (signal?: AbortSignal) => api.one<InsightsSession>('v1/session', undefined, signal).then((r) => r.data),
  preferences: (body: Partial<Preferences>) => api.put<Preferences>('v1/preferences', body).then((r) => r.data),
  companySettings: (signal?: AbortSignal) =>
    api
      .one<{ settings: Record<string, unknown>; can_edit: boolean; ai: AiStatus }>('v1/settings', undefined, signal)
      .then((r) => r.data),
  saveCompanySettings: (body: Record<string, unknown>) =>
    api.put<{ settings: Record<string, unknown> }>('v1/settings', body).then((r) => r.data),
}

// ---------------------------------------------------------------------------
// Overview
// ---------------------------------------------------------------------------

export const overview = {
  load: (period: PeriodQuery, signal?: AbortSignal) =>
    api.one<OverviewPayload>('v1/overview', periodParams(period), signal).then((r) => r.data),
}

// ---------------------------------------------------------------------------
// Metrics
// ---------------------------------------------------------------------------

export interface MetricCatalogue {
  metrics: MetricDefinitionRow[]
  custom: MetricDefinitionRow[]
  dimensions: Record<string, string>
  grains: string[]
  units: string[]
  functions: string[]
  widget_types: Record<string, WidgetTypeSpec>
  can_manage: boolean
}

export interface FormulaCheck {
  valid: boolean
  code?: string
  message?: string
  details?: Record<string, unknown>
  references?: string[]
  unit?: string
  precision?: number
  basis?: string
  grains?: string[]
  is_balance?: boolean
}

export const metrics = {
  catalogue: (signal?: AbortSignal) => api.one<MetricCatalogue>('v1/metrics', undefined, signal).then((r) => r.data),

  validate: (formula: string, signal?: AbortSignal) =>
    api.post<FormulaCheck>('v1/metrics/validate', { formula }, undefined, signal).then((r) => r.data),

  saveCustom: (body: { label: string; definition?: string; formula: string; unit?: string; better_when?: string }) =>
    api.post<MetricDefinitionRow>('v1/metrics/custom', body).then((r) => r.data),

  updateCustom: (metricKey: string, body: Record<string, unknown>) =>
    api.put<MetricDefinitionRow>(`v1/metrics/custom/${encodeURIComponent(metricKey.replace('.', '_'))}`, body).then((r) => r.data),

  retireCustom: (metricKey: string) =>
    api.del<{ retired: boolean }>(`v1/metrics/custom/${encodeURIComponent(metricKey.replace('.', '_'))}`).then((r) => r.data),
}

// ---------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------

export interface MetricsAnswer {
  period: import('./types').PeriodInfo
  scope: { cmp_id: number; fy_id: number; bo_id: number }
  metrics: Record<string, MetricValue>
  sources: SourceRow[]
  unknown: string[]
}

export const query = {
  metrics: (ids: string[], period: PeriodQuery, signal?: AbortSignal) =>
    api.one<MetricsAnswer>('v1/query/metrics', { metrics: ids.join(','), ...periodParams(period) }, signal).then((r) => r.data),

  series: (metric: string, period: PeriodQuery, signal?: AbortSignal) =>
    api
      .one<{ period: import('./types').PeriodInfo; metric: MetricValue | null; sources: SourceRow[]; message?: string }>(
        'v1/query/series',
        { metric, ...periodParams(period) },
        signal,
      )
      .then((r) => r.data),

  breakdown: (metric: string, dimension: string, period: PeriodQuery, signal?: AbortSignal) =>
    api
      .one<{ period: import('./types').PeriodInfo; dimension: string; metric: MetricValue | null; sources: SourceRow[]; message?: string }>(
        'v1/query/breakdown',
        { metric, dimension, ...periodParams(period) },
        signal,
      )
      .then((r) => r.data),

  drilldown: (metric: string, period: PeriodQuery, signal?: AbortSignal) =>
    api
      .one<{ target: import('./types').DrilldownTarget | null; message?: string }>(
        'v1/query/drilldown',
        { metric, ...periodParams(period) },
        signal,
      )
      .then((r) => r.data),
}

// ---------------------------------------------------------------------------
// Sources
// ---------------------------------------------------------------------------

export interface SourcesPayload {
  sources: SourceCapability[]
  unbound_metrics: { metric_id: string; label: string; owner: string; reason: string; definition: string }[]
  ai: AiStatus
  checked_at: string
  note: string
}

export const sources = {
  list: (signal?: AbortSignal) => api.one<SourcesPayload>('v1/sources', undefined, signal).then((r) => r.data),
  show: (product: string, signal?: AbortSignal) =>
    api
      .one<SourceCapability & { metric_details: MetricDefinitionRow[] }>(`v1/sources/${encodeURIComponent(product)}`, undefined, signal)
      .then((r) => r.data),
}

// ---------------------------------------------------------------------------
// Dashboards
// ---------------------------------------------------------------------------

export const dashboards = {
  list: (params: { scope?: string; q?: string; limit?: number; offset?: number; sort?: string; order?: string }, signal?: AbortSignal) =>
    api.list<DashboardSummary>('v1/dashboards', params as Record<string, string | number>, signal),

  templates: (signal?: AbortSignal) =>
    api.one<{ templates: import('./types').TemplateSummary[]; note: string }>('v1/dashboards/templates', undefined, signal).then((r) => r.data),

  show: (id: string, published = false, signal?: AbortSignal) =>
    api.one<Dashboard>(`v1/dashboards/${id}`, published ? { published: 1 } : undefined, signal).then((r) => r.data),

  data: (id: string, period?: PeriodQuery, published = false, signal?: AbortSignal) =>
    api
      .one<DashboardData>(`v1/dashboards/${id}/data`, { ...periodParams(period), ...(published ? { published: 1 } : {}) }, signal)
      .then((r) => r.data),

  preview: (body: { widgets: unknown[]; settings: unknown }, signal?: AbortSignal) =>
    api.post<DashboardData>('v1/dashboards/preview', body, undefined, signal).then((r) => r.data),

  create: (body: Record<string, unknown>) => api.post<Dashboard>('v1/dashboards', body).then((r) => r.data),

  save: (id: string, body: Record<string, unknown>) => api.put<Dashboard>(`v1/dashboards/${id}`, body).then((r) => r.data),

  publish: (id: string) => api.post<Dashboard>(`v1/dashboards/${id}/publish`).then((r) => r.data),

  duplicate: (id: string, title?: string) => api.post<Dashboard>(`v1/dashboards/${id}/duplicate`, { title }).then((r) => r.data),

  remove: (id: string) => api.del<{ deleted: boolean }>(`v1/dashboards/${id}`).then((r) => r.data),

  favourite: (id: string, on: boolean) => api.post<{ ok: boolean }>(`v1/dashboards/${id}/favourite`, { favourite: on }).then((r) => r.data),

  versions: (id: string, signal?: AbortSignal) =>
    api.one<{ versions: DashboardVersion[] }>(`v1/dashboards/${id}/versions`, undefined, signal).then((r) => r.data.versions),

  restore: (id: string, revision: number) => api.post<Dashboard>(`v1/dashboards/${id}/versions/${revision}`).then((r) => r.data),

  share: (id: string, body: { subject_type: string; subject_id?: string; permission: string }) =>
    api.post<{ shares: DashboardShare[] }>(`v1/dashboards/${id}/shares`, body).then((r) => r.data.shares),

  unshare: (id: string, shareId: string) =>
    api.del<{ shares: DashboardShare[] }>(`v1/dashboards/${id}/shares/${shareId}`).then((r) => r.data.shares),
}

// ---------------------------------------------------------------------------
// Forecasts, anomalies, reports and AI
// ---------------------------------------------------------------------------

export const forecasts = {
  metric: (
    params: { metric: string; method?: string; horizon?: number; scenario_percent?: string | null },
    period: PeriodQuery,
    signal?: AbortSignal,
  ) =>
    api
      .one<ForecastPayload>(
        'v1/forecast',
        {
          metric: params.metric,
          method: params.method,
          horizon: params.horizon,
          scenario_percent: params.scenario_percent ?? undefined,
          ...periodParams(period),
        },
        signal,
      )
      .then((r) => r.data),

  cashFlow: (
    params: { method?: string; horizon?: number; scenario_percent?: string | null },
    period: PeriodQuery,
    signal?: AbortSignal,
  ) =>
    api
      .one<CashFlowPayload>(
        'v1/forecast/cash-flow',
        {
          method: params.method,
          horizon: params.horizon,
          scenario_percent: params.scenario_percent ?? undefined,
          ...periodParams(period),
        },
        signal,
      )
      .then((r) => r.data),
}

export const anomalies = {
  list: (period: PeriodQuery, includeReviewed: boolean, signal?: AbortSignal) =>
    api
      .one<AnomalyPayload>('v1/anomalies', { include_reviewed: includeReviewed ? 1 : 0, ...periodParams(period) }, signal)
      .then((r) => r.data),

  review: (fingerprint: string, body: { status: string; reason?: string; rule_id?: string; subject?: string }) =>
    api.post<Record<string, unknown>>(`v1/anomalies/${fingerprint}/review`, body).then((r) => r.data),

  note: (fingerprint: string, note: string) =>
    api.post<{ notes: unknown[] }>(`v1/anomalies/${fingerprint}/notes`, { note }).then((r) => r.data),
}

export const reports = {
  list: (signal?: AbortSignal) => api.list<ReportDefinition>('v1/reports', undefined, signal),
  show: (id: string, signal?: AbortSignal) => api.one<ReportDefinition>(`v1/reports/${id}`, undefined, signal).then((r) => r.data),
  create: (body: Record<string, unknown>) => api.post<ReportDefinition>('v1/reports', body).then((r) => r.data),
  update: (id: string, body: Record<string, unknown>) => api.put<ReportDefinition>(`v1/reports/${id}`, body).then((r) => r.data),
  remove: (id: string) => api.del<{ deleted: boolean }>(`v1/reports/${id}`).then((r) => r.data),

  preview: (body: { config?: Record<string, unknown>; report_id?: string; title?: string }, period: PeriodQuery, signal?: AbortSignal) =>
    api.post<ReportResult>('v1/reports/preview', body, periodParams(period), signal).then((r) => r.data),

  download: (body: { config?: Record<string, unknown>; report_id?: string; title?: string }, format: string, period: PeriodQuery) =>
    api.download('v1/reports/export', body, { format, ...periodParams(period) }),
}

export const ai = {
  status: (signal?: AbortSignal) => api.one<AiStatus>('v1/ai/status', undefined, signal).then((r) => r.data),

  ask: (question: string, period: PeriodQuery, dashboardId?: string, signal?: AbortSignal) =>
    api.post<AiResponse>('v1/ai/ask', { question, dashboard_id: dashboardId }, periodParams(period), signal).then((r) => r.data),

  apply: (proposalId: string, title?: string) =>
    api.post<Dashboard>(`v1/ai/proposals/${proposalId}/apply`, { title }).then((r) => r.data),

  discard: (proposalId: string) => api.del<{ discarded: boolean }>(`v1/ai/proposals/${proposalId}`).then((r) => r.data),
}

export type { Widget }
