/**
 * The Insights component vocabulary.
 *
 * Small, unstyled-by-props, and all of it bound to the tokens in
 * insights-ui.css — so a page composes rather than restyles, and the product
 * cannot drift into looking like two products.
 *
 * Two rules run through everything here:
 *
 *   STATUS IS NEVER COLOUR ALONE. Every badge carries a word and a glyph.
 *   A green dot and a red dot are the same dot to a reader who cannot tell
 *   them apart, and to anybody printing the page.
 *
 *   AN EMPTY THING SAYS WHY IT IS EMPTY. There is no bare "No data" here:
 *   the states below take a reason and, where there is one, something to do
 *   about it.
 */

import { useEffect, useId, useRef, type ReactNode } from 'react'
import {
  AlertTriangle,
  CheckCircle2,
  CircleSlash,
  Info,
  Loader2,
  ShieldAlert,
  X,
} from 'lucide-react'

// ---------------------------------------------------------------------------
// Page furniture
// ---------------------------------------------------------------------------

export function PageHeader({
  eyebrow = 'AICOUNTLY Insights',
  title,
  subtitle,
  actions,
}: {
  eyebrow?: string
  title: string
  subtitle?: ReactNode
  actions?: ReactNode
}) {
  return (
    <header className="insights-heading">
      <div style={{ minWidth: 0 }}>
        <p className="insights-eyebrow">{eyebrow}</p>
        <h1>{title}</h1>
        {subtitle ? <p className="insights-muted">{subtitle}</p> : null}
      </div>
      {actions ? <div className="insights-actions">{actions}</div> : null}
    </header>
  )
}

export function PanelHeader({
  title,
  subtitle,
  actions,
}: {
  title: string
  subtitle?: ReactNode
  actions?: ReactNode
}) {
  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'flex-start',
        justifyContent: 'space-between',
        gap: 12,
        marginBottom: 14,
      }}
    >
      <div style={{ minWidth: 0 }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 650, lineHeight: 1.3 }}>{title}</h2>
        {subtitle ? (
          <p className="insights-muted" style={{ fontSize: 12.5, marginTop: 3 }}>
            {subtitle}
          </p>
        ) : null}
      </div>
      {actions ? <div style={{ display: 'flex', gap: 6, flex: 'none' }}>{actions}</div> : null}
    </div>
  )
}

export function Panel({
  children,
  className = '',
  ...rest
}: { children: ReactNode; className?: string } & React.HTMLAttributes<HTMLElement>) {
  return (
    <section className={`insights-panel ${className}`} {...rest}>
      {children}
    </section>
  )
}

// ---------------------------------------------------------------------------
// Buttons and fields
// ---------------------------------------------------------------------------

type ButtonVariant = 'default' | 'primary' | 'quiet' | 'danger'

export function Button({
  variant = 'default',
  busy = false,
  children,
  className = '',
  disabled,
  ...rest
}: {
  variant?: ButtonVariant
  busy?: boolean
} & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  const suffix = variant === 'default' ? '' : ` insights-button--${variant}`

  return (
    <button
      type="button"
      className={`insights-button${suffix} ${className}`}
      disabled={disabled || busy}
      aria-busy={busy || undefined}
      {...rest}
    >
      {busy ? <Loader2 size={14} aria-hidden style={{ flex: 'none' }} /> : null}
      {children}
    </button>
  )
}

export function Field({
  label,
  hint,
  error,
  children,
}: {
  label: string
  hint?: ReactNode
  error?: string | null
  children: (id: string) => ReactNode
}) {
  const id = useId()
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined

  return (
    <div className="insights-field">
      <label htmlFor={id}>{label}</label>
      {children(id)}
      {error ? (
        <span id={`${id}-error`} className="insights-hint" style={{ color: 'var(--danger)' }} role="alert">
          {error}
        </span>
      ) : hint ? (
        <span id={`${id}-hint`} className="insights-hint">
          {hint}
        </span>
      ) : null}
      {/* Keeps the association even when neither is rendered. */}
      <span hidden data-described-by={describedBy} />
    </div>
  )
}

export function Select({
  options,
  ...rest
}: {
  options: { value: string; label: string; disabled?: boolean }[]
} & React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select className="insights-select" {...rest}>
      {options.map((option) => (
        <option key={option.value} value={option.value} disabled={option.disabled}>
          {option.label}
        </option>
      ))}
    </select>
  )
}

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

export type Tone = 'ready' | 'warn' | 'danger' | 'info' | 'neutral'

const TONE_ICON = {
  ready: CheckCircle2,
  warn: AlertTriangle,
  danger: ShieldAlert,
  info: Info,
  neutral: CircleSlash,
} as const

/**
 * A status badge.
 *
 * The icon and the label are both required: colour is the third signal here,
 * never the first.
 */
export function Badge({ tone = 'neutral', children }: { tone?: Tone; children: ReactNode }) {
  const Icon = TONE_ICON[tone]

  return (
    <span className={`insights-badge insights-badge--${tone}`}>
      <Icon size={12} aria-hidden style={{ flex: 'none' }} />
      {children}
    </span>
  )
}

export function Chip({
  children,
  active = false,
  onRemove,
}: {
  children: ReactNode
  active?: boolean
  onRemove?: () => void
}) {
  return (
    <span className={`insights-chip${active ? ' insights-chip--active' : ''}`}>
      {children}
      {onRemove ? (
        <button type="button" onClick={onRemove} aria-label="Remove this filter">
          <X size={12} aria-hidden />
        </button>
      ) : null}
    </span>
  )
}

// ---------------------------------------------------------------------------
// The five states every panel needs
// ---------------------------------------------------------------------------

export function LoadingState({ label = 'Loading…', rows = 3 }: { label?: string; rows?: number }) {
  return (
    <div className="insights-state" aria-live="polite" aria-busy="true">
      <span className="insights-sr-only">{label}</span>
      <div style={{ display: 'grid', gap: 10, width: '100%' }} aria-hidden>
        {Array.from({ length: rows }).map((_, index) => (
          <div key={index} className="insights-skeleton" style={{ height: index === 0 ? 24 : 16 }} />
        ))}
      </div>
    </div>
  )
}

export function EmptyState({ title, detail, action }: { title: string; detail?: ReactNode; action?: ReactNode }) {
  return (
    <div className="insights-state">
      <CircleSlash size={22} aria-hidden style={{ color: 'var(--ix-muted)' }} />
      <h3>{title}</h3>
      {detail ? <p>{detail}</p> : null}
      {action}
    </div>
  )
}

export function ErrorState({
  title = 'That did not load',
  detail,
  onRetry,
}: {
  title?: string
  detail?: ReactNode
  onRetry?: () => void
}) {
  return (
    <div className="insights-state" role="alert">
      <AlertTriangle size={22} aria-hidden style={{ color: 'var(--danger)' }} />
      <h3>{title}</h3>
      {detail ? <p>{detail}</p> : null}
      {onRetry ? <Button onClick={onRetry}>Try again</Button> : null}
    </div>
  )
}

export function DeniedState({ detail }: { detail?: ReactNode }) {
  return (
    <div className="insights-state">
      <ShieldAlert size={22} aria-hidden style={{ color: 'var(--warning)' }} />
      <h3>You do not have access to this</h3>
      <p>
        {detail ??
          'Insights reads every figure from the product that owns it, as you. What you can see here is what you can see there.'}
      </p>
    </div>
  )
}

export function UnavailableState({ detail, onRetry }: { detail?: ReactNode; onRetry?: () => void }) {
  return (
    <div className="insights-state">
      <CircleSlash size={22} aria-hidden style={{ color: 'var(--ix-muted)' }} />
      <h3>Unavailable</h3>
      <p>{detail ?? 'The product that owns this figure could not answer. It is unavailable, not zero.'}</p>
      {onRetry ? <Button onClick={onRetry}>Try again</Button> : null}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Dialog and drawer
// ---------------------------------------------------------------------------

/**
 * A modal dialog.
 *
 * Focus moves into it on open and returns to whatever opened it on close;
 * Escape closes; Tab cycles inside. Without those a dialog is a visual effect
 * rather than a dialog, and a keyboard user ends up somewhere behind it.
 */
export function Dialog({
  title,
  description,
  onClose,
  children,
  footer,
  width,
}: {
  title: string
  description?: ReactNode
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  width?: number
}) {
  const panelRef = useRef<HTMLDivElement | null>(null)
  const returnFocusRef = useRef<HTMLElement | null>(null)
  const titleId = useId()
  const descriptionId = useId()

  useEffect(() => {
    returnFocusRef.current = document.activeElement as HTMLElement | null
    const panel = panelRef.current
    panel?.querySelector<HTMLElement>('[data-autofocus], button, input, select, textarea, a[href]')?.focus()

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
        return
      }
      if (event.key !== 'Tab' || !panel) return

      const focusable = Array.from(
        panel.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
      ).filter((element) => !element.hasAttribute('disabled'))
      if (focusable.length === 0) return

      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      returnFocusRef.current?.focus?.()
    }
  }, [onClose])

  return (
    <div
      className="insights-dialog-scrim"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={panelRef}
        className="insights-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        style={width ? { width: `min(${width}px, 100%)` } : undefined}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, marginBottom: 14 }}>
          <div style={{ minWidth: 0 }}>
            <h2 id={titleId} style={{ margin: 0, fontSize: 17, fontWeight: 650 }}>
              {title}
            </h2>
            {description ? (
              <p id={descriptionId} className="insights-muted" style={{ fontSize: 13, marginTop: 5 }}>
                {description}
              </p>
            ) : null}
          </div>
          <Button variant="quiet" onClick={onClose} aria-label="Close">
            <X size={16} aria-hidden />
          </Button>
        </div>
        {children}
        {footer ? (
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 18, flexWrap: 'wrap' }}>
            {footer}
          </div>
        ) : null}
      </div>
    </div>
  )
}

export function Drawer({
  title,
  onClose,
  children,
}: {
  title: string
  onClose: () => void
  children: ReactNode
}) {
  const titleId = useId()
  const panelRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    panelRef.current?.querySelector<HTMLElement>('button, input, select, textarea, a[href]')?.focus()

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      previous?.focus?.()
    }
  }, [onClose])

  return (
    <>
      <div className="insights-drawer-scrim" onClick={onClose} aria-hidden />
      <aside ref={panelRef} className="insights-drawer" role="dialog" aria-modal="true" aria-labelledby={titleId}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 id={titleId} style={{ margin: 0, fontSize: 16, fontWeight: 650 }}>
            {title}
          </h2>
          <Button variant="quiet" onClick={onClose} aria-label="Close">
            <X size={16} aria-hidden />
          </Button>
        </div>
        {children}
      </aside>
    </>
  )
}

// ---------------------------------------------------------------------------
// Misc
// ---------------------------------------------------------------------------

/** A definition list, for the scope and provenance blocks that appear everywhere. */
export function Facts({ rows }: { rows: { label: string; value: ReactNode }[] }) {
  return (
    <dl style={{ margin: 0, display: 'grid', gap: 8 }}>
      {rows.map((row) => (
        <div key={row.label} style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 9rem) 1fr', gap: 10 }}>
          <dt style={{ color: 'var(--ix-muted)', fontSize: 12.5 }}>{row.label}</dt>
          <dd style={{ margin: 0, fontSize: 13, minWidth: 0, wordBreak: 'break-word' }}>{row.value}</dd>
        </div>
      ))}
    </dl>
  )
}

export function WarningList({ warnings }: { warnings: string[] }) {
  if (warnings.length === 0) return null

  return (
    <ul
      style={{
        margin: '10px 0 0',
        padding: '10px 12px 10px 28px',
        background: 'var(--warning-bg)',
        border: '1px solid color-mix(in srgb, var(--warning) 22%, transparent)',
        borderRadius: 9,
        fontSize: 12.5,
        color: 'var(--warning)',
        lineHeight: 1.55,
      }}
    >
      {warnings.map((warning, index) => (
        <li key={index}>{warning}</li>
      ))}
    </ul>
  )
}
