import { useCallback, useEffect, useRef, useState } from 'react'
import { Copy, GripVertical, Maximize2, Minimize2, MoveHorizontal, Trash2 } from 'lucide-react'
import { WidgetBody } from './WidgetFrame'
import type { Breakpoint, MetricValue, RenderedWidget, SourceRow, Widget, WidgetLayout } from '../services/types'

/**
 * The dashboard grid.
 *
 * TWELVE COLUMNS ON DESKTOP, SIX ON TABLET, ONE ON MOBILE, and all three are
 * STORED. A dashboard that remembered only the desktop layout would rearrange
 * itself the first time somebody opened it on a phone and then saved — which is
 * how a colleague's carefully built board comes back as a single column.
 *
 * DRAGGING IS NOT THE ONLY WAY TO MOVE A WIDGET. Every widget can be moved and
 * resized from the keyboard: focus it, then the arrow keys move it and
 * shift+arrows resize it. A builder that can only be driven by a mouse is a
 * builder a good number of people cannot use at all, and the pointer handlers
 * below are a convenience layered on top of that rather than the mechanism.
 *
 * The layout is a simple ordered flow rather than free positioning: widgets
 * carry a width and a height and fill the grid in order. That is deliberate —
 * free positioning means overlapping widgets, gaps that reflow differently at
 * every breakpoint, and a dashboard nobody can print.
 */

export const COLUMNS: Record<Breakpoint, number> = { desktop: 12, tablet: 6, mobile: 1 }

function useBreakpoint(): Breakpoint {
  const [breakpoint, setBreakpoint] = useState<Breakpoint>(() => resolve())

  useEffect(() => {
    function onResize() {
      setBreakpoint(resolve())
    }
    window.addEventListener('resize', onResize)
    return () => window.removeEventListener('resize', onResize)
  }, [])

  return breakpoint
}

function resolve(): Breakpoint {
  if (typeof window === 'undefined') return 'desktop'
  if (window.innerWidth <= 640) return 'mobile'
  if (window.innerWidth <= 1024) return 'tablet'
  return 'desktop'
}

function layoutFor(widget: Widget, breakpoint: Breakpoint): WidgetLayout {
  return widget.layout?.[breakpoint] ?? widget.layout?.desktop ?? { x: 0, y: 0, w: 3, h: 2 }
}

export interface CanvasHandlers {
  onSelect?: (widgetId: string) => void
  onMove?: (widgetId: string, direction: -1 | 1) => void
  onResize?: (widgetId: string, breakpoint: Breakpoint, delta: { w?: number; h?: number }) => void
  onDuplicate?: (widgetId: string) => void
  onRemove?: (widgetId: string) => void
}

export function DashboardCanvas({
  widgets,
  rendered,
  sources,
  loading,
  editable = false,
  selectedId,
  onOpenEvidence,
  handlers = {},
}: {
  widgets: Widget[]
  rendered: Record<string, RenderedWidget>
  sources: SourceRow[]
  loading: boolean
  editable?: boolean
  selectedId?: string | null
  onOpenEvidence?: (metric: MetricValue) => void
  handlers?: CanvasHandlers
}) {
  const breakpoint = useBreakpoint()
  const [dragId, setDragId] = useState<string | null>(null)
  const liveRegion = useRef<HTMLDivElement | null>(null)

  const announce = useCallback((message: string) => {
    if (liveRegion.current) liveRegion.current.textContent = message
  }, [])

  const onKeyDown = useCallback(
    (event: React.KeyboardEvent, widget: Widget, index: number) => {
      if (!editable) return

      const layout = layoutFor(widget, breakpoint)
      const columns = COLUMNS[breakpoint]

      // Shift + arrows resize; plain arrows reorder. Both announced, because a
      // keyboard user cannot see the widget move under the pointer.
      if (event.shiftKey && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
        event.preventDefault()
        const delta = event.key === 'ArrowRight' ? 1 : -1
        handlers.onResize?.(widget.id, breakpoint, { w: delta })
        announce(`${widget.title || 'Widget'} is now ${Math.max(1, Math.min(columns, layout.w + delta))} of ${columns} columns wide.`)
        return
      }

      if (event.shiftKey && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
        event.preventDefault()
        const delta = event.key === 'ArrowDown' ? 1 : -1
        handlers.onResize?.(widget.id, breakpoint, { h: delta })
        announce(`${widget.title || 'Widget'} is now ${Math.max(1, layout.h + delta)} rows tall.`)
        return
      }

      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        event.preventDefault()
        handlers.onMove?.(widget.id, 1)
        announce(`${widget.title || 'Widget'} moved to position ${Math.min(widgets.length, index + 2)} of ${widgets.length}.`)
        return
      }

      if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        event.preventDefault()
        handlers.onMove?.(widget.id, -1)
        announce(`${widget.title || 'Widget'} moved to position ${Math.max(1, index)} of ${widgets.length}.`)
        return
      }

      if (event.key === 'Delete' || event.key === 'Backspace') {
        event.preventDefault()
        handlers.onRemove?.(widget.id)
        announce(`${widget.title || 'Widget'} removed.`)
      }
    },
    [announce, breakpoint, editable, handlers, widgets.length],
  )

  return (
    <>
      {/* Every keyboard move and resize is announced here. Without it the
          builder is silent to a screen-reader user doing exactly what the help
          text told them to do. */}
      <div ref={liveRegion} aria-live="polite" className="insights-sr-only" />

      <div className="insights-grid">
        {widgets.map((widget, index) => {
          const layout = layoutFor(widget, breakpoint)
          const span = Math.max(1, Math.min(COLUMNS[breakpoint], layout.w))
          const selected = selectedId === widget.id

          return (
            <div
              key={widget.id}
              className="insights-grid-item"
              style={{
                gridColumn: `span ${span}`,
                minHeight: layout.h * 60,
                opacity: dragId === widget.id ? 0.5 : 1,
              }}
              draggable={editable}
              onDragStart={(event) => {
                if (!editable) return
                setDragId(widget.id)
                event.dataTransfer.effectAllowed = 'move'
                event.dataTransfer.setData('text/plain', widget.id)
              }}
              onDragEnd={() => setDragId(null)}
              onDragOver={(event) => {
                if (!editable || !dragId || dragId === widget.id) return
                event.preventDefault()
                event.dataTransfer.dropEffect = 'move'
              }}
              onDrop={(event) => {
                if (!editable || !dragId || dragId === widget.id) return
                event.preventDefault()
                const from = widgets.findIndex((candidate) => candidate.id === dragId)
                if (from === -1) return
                // Move one step at a time towards the drop target, so the
                // reorder uses the same primitive the keyboard does and there
                // is only one code path to get right.
                const direction: -1 | 1 = from < index ? 1 : -1
                for (let step = 0; step < Math.abs(index - from); step += 1) {
                  handlers.onMove?.(dragId, direction)
                }
                setDragId(null)
              }}
            >
              <article
                className={`insights-widget${selected ? ' insights-widget--selected' : ''}`}
                tabIndex={editable ? 0 : -1}
                role={editable ? 'group' : undefined}
                aria-label={
                  editable
                    ? `${widget.title || widget.widget_type}. Arrow keys move it, shift and arrow keys resize it, Delete removes it.`
                    : undefined
                }
                onFocus={editable ? () => handlers.onSelect?.(widget.id) : undefined}
                onClick={editable ? () => handlers.onSelect?.(widget.id) : undefined}
                onKeyDown={(event) => onKeyDown(event, widget, index)}
              >
                <div className="insights-widget__head">
                  <h3 className="insights-widget__title">
                    {editable ? (
                      <GripVertical size={13} aria-hidden style={{ color: 'var(--ix-muted)', marginRight: 4, verticalAlign: -2 }} />
                    ) : null}
                    {widget.title || rendered[widget.id]?.title || 'Untitled widget'}
                  </h3>

                  {editable ? (
                    <div className="insights-widget__tools">
                      <button
                        type="button"
                        className="insights-button insights-button--quiet"
                        aria-label="Narrower"
                        onClick={() => handlers.onResize?.(widget.id, breakpoint, { w: -1 })}
                      >
                        <Minimize2 size={13} aria-hidden />
                      </button>
                      <button
                        type="button"
                        className="insights-button insights-button--quiet"
                        aria-label="Wider"
                        onClick={() => handlers.onResize?.(widget.id, breakpoint, { w: 1 })}
                      >
                        <Maximize2 size={13} aria-hidden />
                      </button>
                      <button
                        type="button"
                        className="insights-button insights-button--quiet"
                        aria-label="Move later"
                        onClick={() => handlers.onMove?.(widget.id, 1)}
                      >
                        <MoveHorizontal size={13} aria-hidden />
                      </button>
                      <button
                        type="button"
                        className="insights-button insights-button--quiet"
                        aria-label="Duplicate"
                        onClick={() => handlers.onDuplicate?.(widget.id)}
                      >
                        <Copy size={13} aria-hidden />
                      </button>
                      <button
                        type="button"
                        className="insights-button insights-button--quiet"
                        aria-label="Remove"
                        onClick={() => handlers.onRemove?.(widget.id)}
                      >
                        <Trash2 size={13} aria-hidden />
                      </button>
                    </div>
                  ) : null}
                </div>

                {widget.description ? (
                  <p className="insights-muted" style={{ margin: 0, fontSize: 12, lineHeight: 1.5 }}>
                    {widget.description}
                  </p>
                ) : null}

                {rendered[widget.id]?.period_overridden ? (
                  // A card quietly showing a different window from the rest of
                  // the board is how somebody compares two things that are not
                  // comparable. It says so.
                  <p className="insights-muted" style={{ margin: 0, fontSize: 11.5 }}>
                    Own period: {rendered[widget.id]?.period.label}
                  </p>
                ) : null}

                <div style={{ flex: 1, minWidth: 0 }}>
                  <WidgetBody
                    rendered={rendered[widget.id]}
                    loading={loading}
                    onOpenEvidence={onOpenEvidence}
                    dashboardSources={sources}
                  />
                </div>
              </article>
            </div>
          )
        })}
      </div>
    </>
  )
}

/** Move a widget one place in the order, returning a new array. */
export function moveWidget(widgets: Widget[], widgetId: string, direction: -1 | 1): Widget[] {
  const index = widgets.findIndex((widget) => widget.id === widgetId)
  if (index === -1) return widgets

  const target = index + direction
  if (target < 0 || target >= widgets.length) return widgets

  const next = [...widgets]
  const [moved] = next.splice(index, 1)
  next.splice(target, 0, moved)

  return next.map((widget, position) => ({ ...widget, position }))
}

/**
 * Resize one widget at one breakpoint, clamped to that breakpoint's grid.
 *
 * Only the breakpoint being edited changes. Somebody tidying the phone layout
 * must not find they have narrowed every card on the desktop one.
 */
export function resizeWidget(
  widgets: Widget[],
  widgetId: string,
  breakpoint: Breakpoint,
  delta: { w?: number; h?: number },
): Widget[] {
  return widgets.map((widget) => {
    if (widget.id !== widgetId) return widget

    const current = layoutFor(widget, breakpoint)
    const columns = COLUMNS[breakpoint]
    const w = Math.max(1, Math.min(columns, current.w + (delta.w ?? 0)))
    const h = Math.max(1, Math.min(24, current.h + (delta.h ?? 0)))

    return {
      ...widget,
      layout: {
        ...widget.layout,
        [breakpoint]: { ...current, w, h, x: Math.min(current.x, Math.max(0, columns - w)) },
      },
    }
  })
}
