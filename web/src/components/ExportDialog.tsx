import { useState } from 'react'
import { Download } from 'lucide-react'
import { Badge, Button, Dialog } from '../ui'
import { reports } from '../services/insights'
import type { PeriodQuery } from '../services/insights'
import type { Coverage } from '../services/types'

/**
 * Downloading a report.
 *
 * THE FILE IS THE SAME QUERY THE SCREEN RAN, made again, under the same
 * session. There is no path here that renders somebody else's copy, and there
 * is no path that ignores the filters on screen — which is what stops a
 * download saying something different from the page it came from.
 *
 * The dialog states what will be inside the file, because an exported figure
 * outlives the screen it came from and somebody will quote it in a meeting six
 * weeks later.
 */

const FORMATS: { value: 'pdf' | 'csv' | 'xlsx'; label: string; detail: string }[] = [
  { value: 'pdf', label: 'PDF', detail: 'For circulating and printing. Carries the definitions and the notes.' },
  { value: 'csv', label: 'CSV', detail: 'Plain decimals that will add up in any tool.' },
  { value: 'xlsx', label: 'Excel', detail: 'Numbers as numbers, formatted as rupees, so a SUM works.' },
]

export function ExportDialog({
  title,
  config,
  reportId,
  period,
  coverage,
  onClose,
}: {
  title: string
  config?: Record<string, unknown>
  reportId?: string
  period: PeriodQuery
  coverage?: Coverage
  onClose: () => void
}) {
  const [format, setFormat] = useState<'pdf' | 'csv' | 'xlsx'>('pdf')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function download() {
    setBusy(true)
    setError(null)
    try {
      const file = await reports.download({ config, report_id: reportId, title }, format, period)

      // An object URL, revoked immediately after the click: leaving them alive
      // pins the whole file in memory for as long as the tab is open.
      const href = URL.createObjectURL(file.blob)
      const anchor = document.createElement('a')
      anchor.href = href
      anchor.download = file.filename
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      URL.revokeObjectURL(href)

      onClose()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That export failed.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog
      title="Export this report"
      description="The file is produced by running this same query again, with your own access. It cannot contain anything you cannot see on screen."
      onClose={onClose}
      width={520}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" onClick={() => void download()} busy={busy}>
            <Download size={14} aria-hidden />
            Download {format.toUpperCase()}
          </Button>
        </>
      }
    >
      {error ? (
        <p role="alert" style={{ marginTop: 0, color: 'var(--danger)', fontSize: 13 }}>
          {error}
        </p>
      ) : null}

      <fieldset style={{ border: 0, padding: 0, margin: 0, display: 'grid', gap: 8 }}>
        <legend className="insights-sr-only">Format</legend>
        {FORMATS.map((entry) => (
          <label
            key={entry.value}
            style={{
              display: 'grid',
              gridTemplateColumns: 'auto 1fr',
              gap: 10,
              padding: '10px 12px',
              border: '1px solid var(--ix-border)',
              borderRadius: 9,
              cursor: 'pointer',
              background: format === entry.value ? 'var(--ix-green-soft)' : 'var(--ix-surface)',
            }}
          >
            <input
              type="radio"
              name="export-format"
              value={entry.value}
              checked={format === entry.value}
              onChange={() => setFormat(entry.value)}
              data-autofocus={entry.value === 'pdf' ? '' : undefined}
            />
            <span style={{ display: 'grid', gap: 2 }}>
              <strong style={{ fontSize: 13 }}>{entry.label}</strong>
              <span className="insights-muted" style={{ fontSize: 12, lineHeight: 1.45 }}>
                {entry.detail}
              </span>
            </span>
          </label>
        ))}
      </fieldset>

      <div style={{ marginTop: 16, display: 'grid', gap: 8 }}>
        <p className="insights-muted" style={{ margin: 0, fontSize: 12.5, lineHeight: 1.55 }}>
          Every file carries the company, branch, financial year, period, filters, when it was produced, and how fresh
          each source was.
        </p>
        {coverage === 'partial' ? (
          <Badge tone="warn">This report covers part of the data — the file says so on its first page</Badge>
        ) : null}
      </div>
    </Dialog>
  )
}
