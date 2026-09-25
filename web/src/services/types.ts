/**
 * The shapes the Insights API answers with.
 *
 * Mirrors the PHP contracts in server-php/src — MetricResult::jsonSerialize()
 * above all. Kept in one file so a change upstream shows up as a type error
 * here rather than as a blank card in production.
 *
 * NOTE ON NUMBERS. Every figure arrives twice: `value` is the exact decimal as
 * a STRING, and `formatted` is the text to show. The string is never parsed for
 * display — a JavaScript number is a float, and parsing is where paise go
 * missing. It is parsed only to decide where a chart puts a pixel.
 */

export type MetricStatus = 'available' | 'partial' | 'unavailable' | 'denied' | 'not_applicable'

export type Coverage = 'complete' | 'partial' | 'unknown'

export interface Provenance {
  product: string
  contract: string
  endpoint: string
}

export interface MetricComparison {
  value: string | null
  formatted: string
  change: string | null
  change_kind: 'percent' | 'percentage_points'
  change_formatted: string
  direction: 'up' | 'down' | 'flat' | 'unknown'
  better_when: 'up' | 'down' | 'neutral'
}

export interface SeriesPoint {
  key: string
  label: string
  value: string | null
  formatted: string
  partial: boolean
}

export interface BreakdownRow {
  key: string
  label: string
  value: string | null
  formatted: string
  link: DrilldownTarget | null
}

export interface DrilldownTarget {
  product: string
  route: string
  params: Record<string, string | number>
  label: string
}

export interface MetricScope {
  company_id: number
  branch_id: number
  financial_year_id: number
}

export interface PeriodInfo {
  from: string
  to: string
  preset: string
  grain: string
  label: string
  days: number
  timezone: string
  comparison_mode: string
  compare_from: string | null
  compare_to: string | null
  comparison_label: string
}

export interface MetricValue {
  metric_id: string
  label: string
  status: MetricStatus
  value: string | null
  formatted: string
  unit: string
  currency: string | null
  precision: number
  scope: MetricScope
  period: PeriodInfo
  definition: {
    text: string
    owner: string
    accounting_basis: string
    formula_version: string
  }
  provenance: Provenance[]
  fetched_at: string
  source_as_of: string | null
  coverage: Coverage
  warnings: string[]
  message: string | null
  comparison: MetricComparison | null
  series: SeriesPoint[]
  breakdown: BreakdownRow[]
  drilldown: Record<string, unknown> | null
  target?: string | null
  target_formatted?: string | null
}

export type SourceStatus = 'ready' | 'degraded' | 'unavailable' | 'not_configured'

export interface SourceRow {
  id: string
  label: string
  status: SourceStatus
  status_label: string
  fetched_at: string | null
  source_as_of: string | null
  message: string | null
  http_status?: number | null
}

export interface SourceCapability {
  product: string
  label: string
  configured: boolean
  reachable: boolean
  permitted: boolean
  status: SourceStatus
  message: string | null
  metrics: string[]
  dimensions: string[]
  checked_at: string
  base: string | null
}

export interface MetricDefinitionRow {
  id: string
  label: string
  definition: string
  owning_product: string
  binding: string
  measure: string
  aggregation: string
  unit: string
  precision: number
  dimensions: string[]
  grains: string[]
  accounting_basis: string
  source_permissions: string[]
  better_when: 'up' | 'down' | 'neutral'
  drilldown: Record<string, unknown> | null
  formula_version: string
  is_balance: boolean
  depends_on: string[]
  comparison_kind: string
  is_custom?: boolean
}

export interface WidgetTypeSpec {
  label: string
  needs_metric: boolean
  needs_dimension: boolean
  series: boolean
  description: string
}

export interface WidgetLayout {
  x: number
  y: number
  w: number
  h: number
}

export type Breakpoint = 'desktop' | 'tablet' | 'mobile'

export interface WidgetConfig {
  metric_id?: string
  dimension?: string
  grain?: string
  comparison?: string
  precision?: number
  chart_type?: string
  show_legend?: boolean
  tone?: string
  limit?: number
  sort?: string
  order?: string
  text?: string
  horizon?: number
  method?: string
  scenario_adjustment_percent?: string | null
  extra_metrics?: string[]
  filters?: Record<string, string | number>
  period_override?: { enabled: boolean; preset?: string; from?: string | null; to?: string | null } | null
  show_target?: boolean
  target?: string | null
}

export interface Widget {
  id: string
  widget_type: string
  title: string
  description: string
  config: WidgetConfig
  layout: Record<Breakpoint, WidgetLayout>
  position: number
}

export interface DashboardSettings {
  preset: string
  from: string | null
  to: string | null
  grain: string
  compare: string
  branch_id: number
  filters: Record<string, string | number>
  density: string
}

export type DashboardAccess = 'none' | 'view' | 'edit' | 'manage'

export interface DashboardShare {
  id: string
  subject_type: 'user' | 'company'
  subject_id: string
  permission: 'view' | 'edit' | 'manage'
  granted_by: string
  created_at: string
}

export interface DashboardSummary {
  id: string
  title: string
  description: string
  visibility: 'private' | 'team' | 'organisation'
  tags: string[]
  settings: DashboardSettings
  revision: number
  owner_uuid: string
  is_owner: boolean
  is_favourite: boolean
  widget_count: number | null
  share_count: number | null
  template_key: string | null
  published_at: string | null
  created_at: string
  updated_at: string
  updated_by: string | null
}

export interface Dashboard extends DashboardSummary {
  access: DashboardAccess
  widgets: Widget[]
  shares: DashboardShare[]
  viewing: 'draft' | 'published'
  notice?: string
}

export interface DashboardVersion {
  id: string
  revision: number
  label: string
  is_published: boolean
  created_by: string
  created_at: string
}

export interface TemplateRequirement {
  ready: boolean
  products: { product: string; label: string; status: string; message: string | null; widgets: number }[]
  unavailable_widgets: { title: string; metric: string; reason: string }[]
}

export interface TemplateSummary {
  key: string
  title: string
  description: string
  audience: string
  widget_count: number
  settings: Partial<DashboardSettings>
  requirements: TemplateRequirement
}

/** A rendered widget: the figures behind one card. */
export interface RenderedWidget {
  widget_id: string | null
  widget_type: string
  title: string
  description: string
  period: PeriodInfo
  period_overridden: boolean
  status: 'ok' | 'unavailable' | 'error'
  message?: string
  metric?: MetricValue
  metrics?: MetricValue[]
  missing?: string[]
  dimension?: string
  chart_type?: string
  text?: string
  sources?: SourceRow[]
  /** Forecast payload, when the widget is one. */
  history?: ForecastPoint[]
  projection?: ForecastPoint[]
  method?: { id: string; label: string; assumption: string }
  accuracy?: ForecastAccuracy | null
  warnings?: string[]
  scenario?: { adjustment_percent: string; label: string; note: string } | null
  note?: string
}

export interface DashboardData {
  dashboard_id: string
  access: DashboardAccess
  viewing: 'draft' | 'published'
  widgets: Record<string, RenderedWidget>
  sources: SourceRow[]
  period: PeriodInfo
  rejected?: { index: number; id: string | null; field: string; message: string }[]
}

export interface ForecastPoint {
  key: string
  label: string
  value: string | null
  formatted: string
  scenario?: string | null
  scenario_formatted?: string | null
}

export interface ForecastAccuracy {
  method: string
  holdout: number
  mape: string | null
  mape_formatted: string
  note: string
}

export interface ForecastPayload {
  status: 'ok' | 'unavailable'
  message?: string
  metric?: { id: string; label: string; unit: string; definition: string }
  grain?: string
  horizon?: number
  method?: { id: string; label: string; assumption: string }
  history: ForecastPoint[]
  projection: ForecastPoint[]
  scenario?: { adjustment_percent: string; label: string; note: string } | null
  accuracy?: ForecastAccuracy | null
  warnings: string[]
  note?: string
  sources?: SourceRow[]
  methods?: string[]
}

export interface CashFlowRow {
  key: string
  label: string
  opening: string | null
  opening_formatted: string
  receipts: string | null
  receipts_formatted: string
  payments: string | null
  payments_formatted: string
  closing: string | null
  closing_formatted: string
}

export interface CashFlowPayload {
  status: 'ok' | 'unavailable'
  opening: MetricValue | null
  rows: CashFlowRow[]
  method: { id: string; label: string; assumption: string } | null
  grain: string
  receipts_forecast: ForecastPayload
  payments_forecast: ForecastPayload
  warnings: string[]
  note: string
  sources?: SourceRow[]
}

export interface Evidence {
  label: string
  value: string | null
  formatted: string
}

export interface AnomalyException {
  fingerprint: string
  rule_id: string
  rule: string
  description: string
  subject: string
  severity: 'low' | 'medium' | 'high'
  severity_reason: string
  deviation: string
  evidence: Evidence[]
  metric: { id: string; label: string; definition: string }
  period: PeriodInfo
  drilldown: DrilldownTarget | null
  review: {
    status: 'open' | 'acknowledged' | 'dismissed'
    reason: string
    reviewed_by: string | null
    reviewed_at: string | null
    id: string | null
  }
}

export interface AnomalyPayload {
  exceptions: AnomalyException[]
  sources: SourceRow[]
  evaluated: string[]
  period: PeriodInfo
  rules: Record<string, { label: string; metric: string; baseline: string; description: string }>
  can_review: boolean
  note: string
}

export interface Observation {
  tone: 'positive' | 'attention' | 'unavailable'
  title: string
  detail: string
  evidence: {
    metric_id: string
    label: string
    value?: string | null
    formatted: string
    comparison?: MetricComparison | null
    definition?: string
    provenance?: Provenance[]
    drilldown?: Record<string, unknown> | null
  }[]
  next_step: string | null
  basis: string
}

export interface OverviewPayload {
  period: PeriodInfo
  headline: MetricValue[]
  secondary: MetricValue[]
  trends: { revenue: MetricValue | null; collections: MetricValue | null }
  ageing: { receivables: MetricValue | null; payables: MetricValue | null; stock: MetricValue | null }
  observations: Observation[]
  sources: SourceRow[]
  unknown: string[]
}

export interface AiStatus {
  available: boolean
  model: string | null
  provider: string | null
  reason: string | null
  admin_hint: string | null
  domain: string
  module: string
  can_use?: boolean
  usage?: { used: { user_hour: number; company_day: number }; limits: { user_hour: number; company_day: number } }
  within_budget?: boolean
  policy?: string[]
}

export interface AiAnswer {
  kind: 'answer'
  question: string
  intent: string
  scope: MetricScope
  period: PeriodInfo
  findings: string[]
  narrative: string
  generated_by: 'rules' | 'model'
  supporting_metrics: MetricValue[]
  breakdown: MetricValue | null
  series: MetricValue | null
  evidence_links: (DrilldownTarget & { metric_id: string })[]
  limitations: string[]
  next_steps: string[]
  sources: SourceRow[]
  ai: { available: boolean; reason: string | null; model?: string | null; provider?: string | null; error?: string }
  code?: string
}

export interface AiProposal {
  kind: 'proposal'
  question: string
  intent: string
  title: string
  description: string
  settings: Partial<DashboardSettings>
  widgets: Omit<Widget, 'id' | 'position'>[]
  dashboard_id: string | null
  generated_by: 'rules' | 'model'
  rejected: { title: string; reason: string }[]
  sources: SourceRow[]
  apply_note: string
  proposal_id: string | null
  ai: { available: boolean; reason: string | null }
}

export type AiResponse = AiAnswer | AiProposal

export interface ReportDefinition {
  id: string
  title: string
  description: string
  config: {
    title?: string | null
    metrics: string[]
    dimension: string | null
    grain: string
    period: Record<string, unknown>
    filters: Record<string, string | number>
    formats: string[]
  }
  visibility: 'private' | 'team' | 'organisation'
  is_owner: boolean
  created_at: string
  updated_at: string
}

export interface ReportColumn {
  key: string
  label: string
  type: 'text' | 'currency' | 'percent' | 'integer' | 'decimal'
  unit?: string
}

export interface ReportResult {
  title: string
  period: PeriodInfo
  scope: { company: string; branch: string; financial_year: string }
  filters: Record<string, string | number>
  dimension: string | null
  columns: ReportColumn[]
  rows: Record<string, string | null>[]
  metrics: (MetricDefinitionRow | null)[]
  coverage: Coverage
  warnings: string[]
  sources: SourceRow[]
  generated_at: string
  generated_by: string
}

export interface InsightsSession {
  uuid: string
  display_name: string
  kind: 'user' | 'service'
  is_owner: boolean
  context: { cmp_id: number; fy_id: number; bo_id: number }
  permissions: string[]
  preferences: Preferences
  ai: AiStatus
}

export interface Preferences {
  timezone: string
  number_style: 'indian' | 'western'
  default_preset: string
  default_grain: string
  default_compare: string
  density: 'comfortable' | 'compact'
  default_dashboard: string | null
}
