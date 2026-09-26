/**
 * Grid arithmetic behind the builder.
 *
 * These are the functions the keyboard handlers call — arrows move, shift+arrows
 * resize — so testing them is testing the keyboard-accessible alternative to
 * dragging rather than a private helper.
 */

import { describe, expect, it } from 'vitest'
import { COLUMNS, moveWidget, resizeWidget } from './DashboardCanvas'
import type { Widget } from '../services/types'

function widget(id: string, position: number, overrides: Partial<Widget> = {}): Widget {
  return {
    id,
    widget_type: 'kpi',
    title: id,
    description: '',
    config: { metric_id: 'finance.net_revenue' } as Widget['config'],
    layout: {
      desktop: { x: 0, y: 0, w: 3, h: 2 },
      tablet: { x: 0, y: 0, w: 3, h: 2 },
      mobile: { x: 0, y: 0, w: 1, h: 2 },
    },
    position,
    ...overrides,
  }
}

const board = () => [widget('a', 0), widget('b', 1), widget('c', 2)]

describe('moveWidget', () => {
  it('moves a widget one place and renumbers the whole board', () => {
    const moved = moveWidget(board(), 'a', 1)

    expect(moved.map((w) => w.id)).toEqual(['b', 'a', 'c'])
    // Positions are what gets saved; leaving them stale would reorder the board
    // again on the next load.
    expect(moved.map((w) => w.position)).toEqual([0, 1, 2])
  })

  it('moves backwards too', () => {
    expect(moveWidget(board(), 'c', -1).map((w) => w.id)).toEqual(['a', 'c', 'b'])
  })

  it('stops at the ends rather than wrapping', () => {
    const start = board()
    expect(moveWidget(start, 'a', -1)).toBe(start)
    expect(moveWidget(start, 'c', 1)).toBe(start)
  })

  it('leaves the board alone for an unknown widget', () => {
    const start = board()
    expect(moveWidget(start, 'nope', 1)).toBe(start)
  })

  it('does not mutate the array it was given', () => {
    const start = board()
    moveWidget(start, 'a', 1)
    expect(start.map((w) => w.id)).toEqual(['a', 'b', 'c'])
  })
})

describe('resizeWidget', () => {
  it('widens one widget at one breakpoint', () => {
    const [a] = resizeWidget(board(), 'a', 'desktop', { w: 3 })
    expect(a.layout.desktop).toMatchObject({ w: 6, h: 2 })
  })

  it('leaves the other breakpoints untouched', () => {
    // Somebody tidying the phone layout must not find they have narrowed every
    // card on the desktop one.
    const [a] = resizeWidget(board(), 'a', 'mobile', { h: 2 })
    expect(a.layout.mobile).toMatchObject({ w: 1, h: 4 })
    expect(a.layout.desktop).toMatchObject({ w: 3, h: 2 })
    expect(a.layout.tablet).toMatchObject({ w: 3, h: 2 })
  })

  it('clamps to the breakpoint’s own column count', () => {
    const [desktop] = resizeWidget(board(), 'a', 'desktop', { w: 99 })
    expect(desktop.layout.desktop.w).toBe(COLUMNS.desktop)

    const [tablet] = resizeWidget(board(), 'a', 'tablet', { w: 99 })
    expect(tablet.layout.tablet.w).toBe(COLUMNS.tablet)

    // One column on a phone: a half-width card on a 360px screen is unreadable.
    const [mobile] = resizeWidget(board(), 'a', 'mobile', { w: 99 })
    expect(mobile.layout.mobile.w).toBe(COLUMNS.mobile)
  })

  it('never shrinks a widget out of existence', () => {
    const [a] = resizeWidget(board(), 'a', 'desktop', { w: -99, h: -99 })
    expect(a.layout.desktop).toMatchObject({ w: 1, h: 1 })
  })

  it('pulls a widget back inside the grid when it is widened at the right edge', () => {
    const wide = [widget('a', 0, {
      layout: {
        desktop: { x: 9, y: 0, w: 3, h: 2 },
        tablet: { x: 0, y: 0, w: 3, h: 2 },
        mobile: { x: 0, y: 0, w: 1, h: 2 },
      },
    })]

    const [a] = resizeWidget(wide, 'a', 'desktop', { w: 3 })
    expect(a.layout.desktop.w).toBe(6)
    expect(a.layout.desktop.x).toBe(6) // 12 - 6, not 9, which would overflow
  })

  it('touches only the widget asked for', () => {
    const start = board()
    const next = resizeWidget(start, 'b', 'desktop', { w: 1 })

    expect(next[0]).toBe(start[0])
    expect(next[2]).toBe(start[2])
    expect(next[1]).not.toBe(start[1])
  })
})
