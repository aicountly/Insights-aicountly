import { useCallback, useState } from 'react'
import { useInsights } from '../context/InsightsContext'
import { useApi } from '../hooks/useApi'
import { session as sessionApi } from '../services/insights'
import { Badge, Button, ErrorState, Facts, LoadingState, PageHeader, Panel, PanelHeader } from '../ui'
import type { Preferences } from '../services/types'

/**
 * Settings.
 *
 * TWO LEVELS, and they are kept apart on screen because they are kept apart in
 * the database: what YOU see (locale, default period, density) and what THIS
 * COMPANY allows (sharing, AI, currency). The second needs `settings.manage`.
 *
 * THERE IS NO PLACE TO PASTE AN API KEY. AI credentials live in Console, and a
 * business owner reading a dashboard is not the person who buys model capacity
 * — offering them a box for a key is how a key ends up in a support ticket.
 * What this page shows instead is the STATUS, the usage against the budget, and
 * the policy in plain words.
 */
export default function Settings() {
  const { session, can, reload } = useInsights()

  const [preferences, setPreferences] = useState<Preferences | null>(session?.preferences ?? null)
  const [savingPreferences, setSavingPreferences] = useState(false)
  const [preferenceError, setPreferenceError] = useState<string | null>(null)

  const loadCompany = useCallback((signal: AbortSignal) => sessionApi.companySettings(signal), [])
  const company = useApi(loadCompany, [])

  const [companySettings, setCompanySettings] = useState<Record<string, unknown> | null>(null)
  const [savingCompany, setSavingCompany] = useState(false)

  const effectiveCompany = companySettings ?? company.data?.settings ?? null

  async function savePreferences(next: Partial<Preferences>) {
    setSavingPreferences(true)
    setPreferenceError(null)
    try {
      setPreferences(await sessionApi.preferences({ ...(preferences ?? {}), ...next }))
      reload()
    } catch (err) {
      setPreferenceError(err instanceof Error ? err.message : 'That could not be saved.')
    } finally {
      setSavingPreferences(false)
    }
  }

  async function saveCompany(next: Record<string, unknown>) {
    setSavingCompany(true)
    try {
      const saved = await sessionApi.saveCompanySettings({ ...(effectiveCompany ?? {}), ...next })
      setCompanySettings(saved.settings)
    } finally {
      setSavingCompany(false)
    }
  }

  return (
    <div className="insights-workspace">
      <PageHeader title="Settings" subtitle="How Insights behaves for you, and what this company allows." />

      <div className="insights-main-grid">
        <div style={{ display: 'grid', gap: 18 }}>
          <Panel>
            <PanelHeader title="Your preferences" subtitle="Display only. None of this changes what anybody can see." />

            {preferenceError ? (
              <p role="alert" style={{ color: 'var(--danger)', fontSize: 13 }}>
                {preferenceError}
              </p>
            ) : null}

            <div style={{ display: 'grid', gap: 14 }}>
              <div className="insights-field">
                <label htmlFor="pref-numbers">Number style</label>
                <select
                  id="pref-numbers"
                  className="insights-select"
                  value={preferences?.number_style ?? 'indian'}
                  disabled={savingPreferences}
                  onChange={(event) => void savePreferences({ number_style: event.target.value as 'indian' | 'western' })}
                >
                  <option value="indian">Indian (12,34,567.89)</option>
                  <option value="western">Western (1,234,567.89)</option>
                </select>
              </div>

              <div className="insights-field">
                <label htmlFor="pref-timezone">Time zone</label>
                <input
                  id="pref-timezone"
                  className="insights-input"
                  defaultValue={preferences?.timezone ?? 'Asia/Kolkata'}
                  disabled={savingPreferences}
                  onBlur={(event) => void savePreferences({ timezone: event.target.value })}
                />
                <span className="insights-hint">Decides where a day starts and ends when figures are bucketed.</span>
              </div>

              <div className="insights-field">
                <label htmlFor="pref-preset">Period a screen opens on</label>
                <select
                  id="pref-preset"
                  className="insights-select"
                  value={preferences?.default_preset ?? 'this_month'}
                  disabled={savingPreferences}
                  onChange={(event) => void savePreferences({ default_preset: event.target.value })}
                >
                  <option value="this_month">This month</option>
                  <option value="last_month">Last month</option>
                  <option value="last_30_days">Last 30 days</option>
                  <option value="this_quarter">This quarter</option>
                  <option value="financial_year">This financial year</option>
                </select>
              </div>

              <div className="insights-field">
                <label htmlFor="pref-density">Density</label>
                <select
                  id="pref-density"
                  className="insights-select"
                  value={preferences?.density ?? 'comfortable'}
                  disabled={savingPreferences}
                  onChange={(event) => void savePreferences({ density: event.target.value as 'comfortable' | 'compact' })}
                >
                  <option value="comfortable">Comfortable</option>
                  <option value="compact">Compact</option>
                </select>
              </div>
            </div>
          </Panel>

          <Panel>
            <PanelHeader
              title="This company"
              subtitle={can('settings.manage') ? undefined : 'Only a company owner or an administrator can change these.'}
            />

            {company.loading ? <LoadingState rows={3} /> : null}
            {company.error ? <ErrorState detail={company.error} onRetry={company.reload} /> : null}

            {effectiveCompany ? (
              <div style={{ display: 'grid', gap: 14 }}>
                <label className="insights-checkbox">
                  <input
                    type="checkbox"
                    checked={Boolean(effectiveCompany.allow_org_sharing ?? true)}
                    disabled={!can('settings.manage') || savingCompany}
                    onChange={(event) => void saveCompany({ allow_org_sharing: event.target.checked })}
                  />
                  Allow dashboards to be shared with everyone in this company
                </label>

                <label className="insights-checkbox">
                  <input
                    type="checkbox"
                    checked={Boolean(effectiveCompany.allow_ai ?? true)}
                    disabled={!can('settings.manage') || savingCompany}
                    onChange={(event) => void saveCompany({ allow_ai: event.target.checked })}
                  />
                  Allow Ask Insights for this company
                </label>

                <div className="insights-field">
                  <label htmlFor="company-currency">Reporting currency</label>
                  <input
                    id="company-currency"
                    className="insights-input"
                    defaultValue={String(effectiveCompany.currency ?? 'INR')}
                    maxLength={3}
                    disabled={!can('settings.manage') || savingCompany}
                    onBlur={(event) => void saveCompany({ currency: event.target.value.toUpperCase() })}
                  />
                  <span className="insights-hint">
                    Figures are shown in the currency the owning product reports them in. Insights does not convert
                    between currencies — a total that mixed two would be a number nobody could defend.
                  </span>
                </div>
              </div>
            ) : null}
          </Panel>
        </div>

        <div style={{ display: 'grid', gap: 18 }}>
          <Panel>
            <PanelHeader title="Ask Insights" subtitle="Status and policy. Keys are configured in Console, never here." />

            {session?.ai ? (
              <div style={{ display: 'grid', gap: 12 }}>
                <Badge tone={session.ai.available ? 'ready' : 'neutral'}>
                  {session.ai.available ? 'Connected' : 'Not connected'}
                </Badge>

                {session.ai.available ? (
                  <Facts
                    rows={[
                      { label: 'Provider', value: session.ai.provider ?? '—' },
                      { label: 'Model', value: session.ai.model ?? '—' },
                      { label: 'Registered as', value: session.ai.domain },
                      { label: 'Module', value: session.ai.module },
                      ...(session.ai.usage
                        ? [
                            {
                              label: 'Your last hour',
                              value: `${session.ai.usage.used.user_hour} of ${session.ai.usage.limits.user_hour}`,
                            },
                            {
                              label: 'Company today',
                              value: `${session.ai.usage.used.company_day} of ${session.ai.usage.limits.company_day}`,
                            },
                          ]
                        : []),
                    ]}
                  />
                ) : (
                  <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.6 }}>
                    {session.ai.reason}
                  </p>
                )}

                {session.ai.admin_hint ? (
                  <p className="insights-muted" style={{ margin: 0, fontSize: 12, lineHeight: 1.55 }}>
                    {session.ai.admin_hint}
                  </p>
                ) : null}

                {session.ai.policy ? (
                  <div>
                    <h3 style={{ fontSize: 12.5, margin: '4px 0 6px' }}>What happens when you ask</h3>
                    <ul style={{ margin: 0, paddingLeft: 18, fontSize: 12, lineHeight: 1.7, color: 'var(--ix-muted)' }}>
                      {session.ai.policy.map((line, index) => (
                        <li key={index}>{line}</li>
                      ))}
                    </ul>
                  </div>
                ) : null}
              </div>
            ) : null}
          </Panel>

          <Panel>
            <PanelHeader title="Your access" subtitle="What you may do in Insights." />
            {session ? (
              <>
                <Facts
                  rows={[
                    { label: 'Signed in as', value: session.display_name },
                    { label: 'Company owner', value: session.is_owner ? 'Yes — you hold everything here' : 'No' },
                    { label: 'Company', value: `#${session.context.cmp_id}` },
                    { label: 'Financial year', value: `#${session.context.fy_id}` },
                    { label: 'Branch', value: session.context.bo_id === 0 ? 'All branches' : `#${session.context.bo_id}` },
                  ]}
                />

                <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap', marginTop: 12 }}>
                  {session.permissions.map((permission) => (
                    <span key={permission} className="insights-chip">
                      {permission}
                    </span>
                  ))}
                </div>

                <p className="insights-muted" style={{ margin: '14px 0 0', fontSize: 12, lineHeight: 1.6 }}>
                  These decide what you may do in Insights — build, publish, share, export. They do not grant business
                  data: every figure is fetched from Smart Books or Inventory as you, so what you can see here is what
                  you can see there.
                </p>
              </>
            ) : (
              <LoadingState rows={3} />
            )}
          </Panel>

          {can('access.manage') ? (
            <Panel>
              <PanelHeader title="Access profiles" subtitle="Who in this company may do what in Insights." />
              <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.6 }}>
                Somebody with no profile here still gets the read-only baseline and can build their own private
                dashboards. Sharing, publishing, exporting, defining company KPIs and asking the model each need a
                profile that grants them.
              </p>
              <div style={{ marginTop: 12 }}>
                <Button onClick={() => window.alert('Profiles are managed through the API in this release. See docs/README.')}>
                  Manage profiles
                </Button>
              </div>
            </Panel>
          ) : null}
        </div>
      </div>
    </div>
  )
}
