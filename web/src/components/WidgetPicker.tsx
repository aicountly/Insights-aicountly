import { Plus } from 'lucide-react'
import type { WidgetTypeSpec } from '../services/types'

/**
 * The widget library.
 *
 * BUILT FROM THE BACKEND'S OWN LIST. `WidgetSchema::TYPES` is what the server
 * will accept, and this picker is rendered from it — so the palette cannot
 * offer something the save will refuse, which is the most annoying way for a
 * builder to waste somebody's afternoon.
 *
 * Each entry says what the widget DOES rather than what it looks like, because
 * a person choosing between "Ranking" and "Donut" is choosing between "the
 * biggest few" and "the share of a whole".
 */
export function WidgetPicker({
  types,
  onAdd,
  disabled = false,
}: {
  types: Record<string, WidgetTypeSpec>
  onAdd: (widgetType: string) => void
  disabled?: boolean
}) {
  const entries = Object.entries(types)

  return (
    <div style={{ display: 'grid', gap: 6 }}>
      <p className="insights-muted" style={{ margin: '0 0 4px', fontSize: 12, lineHeight: 1.5 }}>
        Add a widget, then choose what it shows on the right.
      </p>

      {entries.map(([type, spec]) => (
        <button
          key={type}
          type="button"
          disabled={disabled}
          onClick={() => onAdd(type)}
          title={spec.description}
          style={{
            display: 'grid',
            gap: 2,
            textAlign: 'left',
            padding: '9px 11px',
            border: '1px solid var(--ix-border)',
            borderRadius: 9,
            background: 'var(--ix-surface)',
            cursor: disabled ? 'not-allowed' : 'pointer',
            opacity: disabled ? 0.55 : 1,
            font: 'inherit',
          }}
        >
          <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 600 }}>
            <Plus size={13} aria-hidden style={{ color: 'var(--ix-green-strong)' }} />
            {spec.label}
          </span>
          <span className="insights-muted" style={{ fontSize: 11.5, lineHeight: 1.45 }}>
            {spec.description}
          </span>
        </button>
      ))}
    </div>
  )
}
