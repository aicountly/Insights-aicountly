import { useCallback, useEffect, useRef, useState } from 'react'
import { Building2, ChevronDown } from 'lucide-react'
import { Button, Dialog } from '../ui'
import { fetchAllCompanies, fetchCompanyInfo } from '../services/manage'
import type { CompanyInfo, CompanyOption } from '../services/manage'
import { useInsights } from '../context/InsightsContext'

/**
 * Which company, branch and financial year this product is working in.
 *
 * THE LIST IS READ FROM MANAGE, LIVE, every time this opens. Manage owns
 * companies, branches and financial years; this product stores their ids and
 * nothing else. Showing someone a list is a read, not a copy — and a list read
 * on the request that draws it cannot go stale the way a local `companies`
 * table would the moment somebody is granted access elsewhere.
 *
 * Manage also decides WHICH companies come back, because the call carries the
 * signed-in user's own session key. This product never filters that list and is
 * never the thing deciding what someone may open.
 */
export function CompanyPicker() {
  const { scope, setCompanyScope } = useInsights()

  const [open, setOpen] = useState(!scope)
  const [companies, setCompanies] = useState<CompanyOption[]>([])
  const [info, setInfo] = useState<CompanyInfo | null>(null)
  const [loadingList, setLoadingList] = useState(false)
  const [loadingInfo, setLoadingInfo] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const [cmpId, setCmpId] = useState<number | null>(scope?.cmp_id ?? null)
  const [fyId, setFyId] = useState<number | null>(scope?.fy_id ?? null)
  const [boId, setBoId] = useState<number>(scope?.bo_id ?? 0)

  /** The label shown when collapsed. Display only, fetched live, never stored. */
  const [currentLabel, setCurrentLabel] = useState<string | null>(null)

  const abortRef = useRef<AbortController | null>(null)

  // ---------------------------------------------------------------- list

  const loadCompanies = useCallback(async () => {
    setLoadingList(true)
    setError(null)
    try {
      const rows = await fetchAllCompanies()
      setCompanies(rows)
      // One company and nothing chosen yet: choose it. Making someone pick from
      // a list of one is a pointless click.
      if (rows.length === 1 && cmpId === null) setCmpId(rows[0].cmpId)
    } catch (e) {
      setError(
        e instanceof Error
          ? `Could not load your companies: ${e.message}`
          : 'Could not load your companies.',
      )
    } finally {
      setLoadingList(false)
    }
  }, [cmpId])

  useEffect(() => {
    if (open) void loadCompanies()
    // loadCompanies is intentionally omitted: it changes with cmpId, and
    // reloading the whole list every time someone picks a company would be a
    // request per keystroke of the dropdown.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  // ---------------------------------------------------------------- one company

  useEffect(() => {
    if (cmpId === null) {
      setInfo(null)
      return
    }

    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller

    setLoadingInfo(true)
    fetchCompanyInfo(cmpId, controller.signal)
      .then((next) => {
        if (controller.signal.aborted) return
        setInfo(next)

        // Default to the most recent financial year — the list arrives sorted
        // newest first — unless the current choice is still valid.
        setFyId((prev) =>
          prev !== null && next.fyList.some((f) => f.fyId === prev)
            ? prev
            : (next.fyList[0]?.fyId ?? null),
        )
        setBoId((prev) => (prev !== 0 && !next.branches.some((b) => b.boId === prev) ? 0 : prev))
      })
      .catch((e: unknown) => {
        if (controller.signal.aborted) return
        setInfo(null)
        setError(
          e instanceof Error
            ? `Could not load that company's years and branches: ${e.message}`
            : "Could not load that company's years and branches.",
        )
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoadingInfo(false)
      })

    return () => controller.abort()
  }, [cmpId])

  // Label for the collapsed state, so the header says which company is open
  // rather than showing a bare number.
  useEffect(() => {
    if (!scope) {
      setCurrentLabel(null)
      return
    }
    let cancelled = false
    fetchCompanyInfo(scope.cmp_id)
      .then((i) => {
        if (cancelled) return
        const fy = i.fyList.find((f) => f.fyId === scope.fy_id)
        setCurrentLabel([i.name || `Company ${scope.cmp_id}`, fy?.label].filter(Boolean).join(' · '))
      })
      .catch(() => {
        // Manage unreachable is not worth an error here; the ids still work and
        // the scoped screens will report it properly if it matters.
        if (!cancelled) setCurrentLabel(`Company ${scope.cmp_id}`)
      })
    return () => {
      cancelled = true
    }
  }, [scope])

  function apply() {
    if (cmpId === null || fyId === null) return
    setCompanyScope({ cmp_id: cmpId, fy_id: fyId, bo_id: boId })
    setOpen(false)
  }

  // ---------------------------------------------------------------- render

  if (!open) {
    // Collapsed, this is the header's context chip: which company, which year,
    // which branch. It reads like a statement rather than a control because
    // most of the time it is one — switching is rare and deliberate.
    const [company, year] = (currentLabel ?? '').split(' · ')
    const branch = scope?.bo_id ? `Branch ${scope.bo_id}` : 'All branches'

    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        title="Change company, branch or financial year"
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: '0.6rem',
          padding: '0.4rem 0.75rem',
          border: '1px solid var(--border)',
          borderRadius: 'var(--radius-sm)',
          background: 'var(--surface)',
          cursor: 'pointer',
          minWidth: 0,
          maxWidth: '100%',
        }}
      >
        <Building2 size={16} aria-hidden style={{ color: 'var(--accent)', flex: 'none' }} />
        <span style={{ display: 'grid', textAlign: 'left', minWidth: 0 }}>
          <span
            style={{
              fontWeight: 650,
              fontSize: '0.9rem',
              overflow: 'hidden',
              textOverflow: 'ellipsis',
              whiteSpace: 'nowrap',
            }}
          >
            {company || 'Choose a company'}
          </span>
          <span style={{ color: 'var(--muted)', fontSize: '0.75rem' }}>
            {[branch, year].filter(Boolean).join(' · ')}
          </span>
        </span>
        <ChevronDown size={15} aria-hidden style={{ color: 'var(--muted)', flex: 'none' }} />
      </button>
    )
  }

  // Expanded, this is a modal: choosing a company changes what every screen in
  // the product is answering for, so it deserves the focus a dialog takes
  // rather than a dropdown somebody can click past.
  return (
    <Dialog
      title="Open a company"
      description="Insights answers for one company, branch and financial year at a time. Changing this stops whatever is loading and asks again for the new scope."
      onClose={() => (scope ? setOpen(false) : undefined)}
      width={560}
      footer={
        <>
          {scope ? <Button onClick={() => setOpen(false)}>Cancel</Button> : null}
          <Button variant="primary" onClick={apply} disabled={cmpId === null || fyId === null} busy={loadingInfo}>
            Open
          </Button>
        </>
      }
    >
      {error ? (
        <p role="alert" style={{ marginTop: 0, color: 'var(--danger)', fontSize: 13, lineHeight: 1.55 }}>
          {error}
        </p>
      ) : null}

      <div style={{ display: 'grid', gap: 14 }}>
        <div className="insights-field">
          <label htmlFor="company-picker-company">Company</label>
          <select
            id="company-picker-company"
            className="insights-select"
            value={cmpId ?? ''}
            disabled={loadingList}
            onChange={(event) => setCmpId(event.target.value === '' ? null : Number(event.target.value))}
            data-autofocus
          >
            <option value="">{loadingList ? 'Loading…' : 'Choose a company'}</option>
            {companies.map((company) => (
              <option key={company.cmpId} value={company.cmpId}>
                {company.name}
                {company.ownership === 'shared' ? ' (shared)' : ''}
              </option>
            ))}
          </select>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 12 }}>
          <div className="insights-field">
            <label htmlFor="company-picker-fy">Financial year</label>
            <select
              id="company-picker-fy"
              className="insights-select"
              value={fyId ?? ''}
              disabled={cmpId === null || loadingInfo}
              onChange={(event) => setFyId(event.target.value === '' ? null : Number(event.target.value))}
            >
              <option value="">{loadingInfo ? 'Loading…' : 'Choose a year'}</option>
              {(info?.fyList ?? []).map((year) => (
                <option key={year.fyId} value={year.fyId}>
                  {year.label}
                </option>
              ))}
            </select>
          </div>

          <div className="insights-field">
            <label htmlFor="company-picker-branch">Branch</label>
            <select
              id="company-picker-branch"
              className="insights-select"
              value={boId}
              disabled={cmpId === null || loadingInfo}
              onChange={(event) => setBoId(Number(event.target.value) || 0)}
            >
              <option value={0}>All branches</option>
              {(info?.branches ?? []).map((branch) => (
                <option key={branch.boId} value={branch.boId}>
                  {branch.name}
                  {branch.isHeadOffice ? ' (head office)' : ''}
                </option>
              ))}
            </select>
            <span className="insights-hint">All branches asks each product for the consolidated figure.</span>
          </div>
        </div>

        {!loadingList && companies.length === 0 && !error ? (
          <p className="insights-muted" style={{ fontSize: 13 }}>
            Manage has no companies for this sign-in. Create one in Aicountly Manage, or ask whoever owns the
            company to give you access — Insights cannot grant it.
          </p>
        ) : null}

        {cmpId !== null && !loadingInfo && info !== null && info.fyList.length === 0 ? (
          <p className="insights-muted" style={{ fontSize: 13 }}>
            That company has no financial year set up yet. Add one in Aicountly Manage; every figure here is
            reported against a year, so there is nothing to show until one exists.
          </p>
        ) : null}
      </div>
    </Dialog>
  )
}
