/**
 * The rules the charts exist to keep.
 *
 * A chart is the easiest place in a finance product to lie by accident: a null
 * drawn as zero, a bar chart that starts above the axis, a figure rounded by a
 * float on its way to a label, a colour that carries meaning nobody who cannot
 * see it can read. Each test below is one of those.
 */

import { describe, expect, it } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { ColumnChart, ForecastChart, LineChart, TONES, toneColour, type Point } from './charts'

function point(label: string, value: string | null, formatted: string, partial = false): Point {
  return { key: label, label, value, formatted, partial }
}

const months: Point[] = [
  point('Apr', '1250000.55', '₹12,50,000.55'),
  point('May', '980000.10', '₹9,80,000.10'),
  point('Jun', '1410000.00', '₹14,10,000.00'),
]

describe('every chart ships the same figures as a table', () => {
  it('renders one table row per point, with the server’s own formatting', () => {
    render(<LineChart title="Net revenue" unitLabel="INR" points={months} />)

    const table = screen.getByRole('table', { hidden: true })
    // Exactly as the server formatted it. Re-formatting a decimal string in
    // JavaScript is how paise go missing.
    expect(within(table).getByText('₹12,50,000.55')).toBeTruthy()
    expect(within(table).getByText('₹9,80,000.10')).toBeTruthy()
    expect(within(table).getByText('₹14,10,000.00')).toBeTruthy()
  })

  it('keeps the table behind a control that says whether it is open', () => {
    render(<LineChart title="Net revenue" unitLabel="INR" points={months} />)

    const toggle = screen.getByRole('button', { name: 'Show the figures' })
    expect(toggle.getAttribute('aria-expanded')).toBe('false')

    const controlled = document.getElementById(toggle.getAttribute('aria-controls') ?? '')
    expect(controlled).not.toBeNull()
    expect(controlled?.hasAttribute('hidden')).toBe(true)
  })

  it('states the figures in prose for a screen reader', () => {
    const { container } = render(<LineChart title="Net revenue" unitLabel="INR" points={months} />)

    const caption = container.querySelector('figcaption')
    expect(caption?.textContent).toContain('Apr: ₹12,50,000.55')
    expect(caption?.textContent).toContain('Jun: ₹14,10,000.00')
  })

  it('hides the drawing from assistive technology rather than narrating pixels', () => {
    const { container } = render(<LineChart title="Net revenue" unitLabel="INR" points={months} />)
    const svgWrapper = container.querySelector('[aria-hidden="true"]')
    expect(svgWrapper?.querySelector('svg')).not.toBeNull()
  })
})

describe('a missing value is a gap, not a zero', () => {
  const withGap: Point[] = [
    point('Apr', '1250000.55', '₹12,50,000.55'),
    point('May', null, 'Unavailable'),
    point('Jun', '1410000.00', '₹14,10,000.00'),
  ]

  it('breaks the line into segments instead of dragging it to the axis', () => {
    const { container } = render(<LineChart title="Net revenue" unitLabel="INR" points={withGap} />)

    // Two path segments, one either side of the month nobody could read — not
    // one path through a fabricated zero.
    const paths = [...container.querySelectorAll('path')].filter((p) => p.getAttribute('fill') === 'none')
    expect(paths).toHaveLength(2)
    for (const path of paths) {
      expect(path.getAttribute('d')?.split('L').length).toBeLessThanOrEqual(2)
    }
  })

  it('plots no marker for the missing month', () => {
    const { container } = render(<LineChart title="Net revenue" unitLabel="INR" points={withGap} />)
    expect(container.querySelectorAll('circle')).toHaveLength(2)
  })

  it('says so in the table rather than printing 0', () => {
    render(<LineChart title="Net revenue" unitLabel="INR" points={withGap} />)
    const table = screen.getByRole('table', { hidden: true })
    expect(within(table).getByText('Unavailable')).toBeTruthy()
    expect(within(table).queryByText('0')).toBeNull()
    expect(within(table).queryByText('₹0.00')).toBeNull()
  })
})

describe('colour is never the only signal', () => {
  it('marks a part period in the table as well as drawing it differently', () => {
    const partial = [...months.slice(0, 2), point('Jul', '400000.00', '₹4,00,000.00', true)]
    render(<ColumnChart title="Net revenue" unitLabel="INR" points={partial} />)

    // Month-to-date is not a small month; the note says which.
    expect(screen.getByText(/part period/)).toBeTruthy()
  })

  it('labels the projection and draws it dashed', () => {
    const { container } = render(
      <ForecastChart
        title="Cash"
        unitLabel="INR"
        history={months}
        projection={[point('Jul', '1500000.00', '₹15,00,000.00')]}
      />,
    )

    expect(screen.getByText('Actual')).toBeTruthy()
    expect(screen.getByText('Projected')).toBeTruthy()

    const dashed = [...container.querySelectorAll('path')].filter((p) => p.hasAttribute('stroke-dasharray'))
    expect(dashed.length).toBeGreaterThan(0)
  })

  it('names a scenario as a scenario, never as a prediction', () => {
    render(
      <ForecastChart
        title="Cash"
        unitLabel="INR"
        history={months}
        projection={[{ ...point('Jul', '1500000.00', '₹15,00,000.00'), scenario: '1800000.00', scenarioFormatted: '₹18,00,000.00' }]}
        scenarioLabel="If collections improve 10%"
      />,
    )

    expect(screen.getByText('If collections improve 10%')).toBeTruthy()
    // And the table separates the two columns, so nobody reads the scenario as
    // the forecast.
    const table = screen.getByRole('table', { hidden: true })
    expect(within(table).getByText(/Scenario \(INR\)/)).toBeTruthy()

    const rows = within(table).getAllByRole('row', { hidden: true }).slice(1)
    expect(rows.filter((row) => /\bprojected\b/.test(row.textContent ?? ''))).toHaveLength(1)
    expect(rows.filter((row) => /\bactual\b/.test(row.textContent ?? ''))).toHaveLength(3)
  })

  it('uses inks that stay distinguishable without colour vision', () => {
    // Not a visual assertion — just that the palette is a named, shared set
    // rather than whatever each chart felt like.
    expect(toneColour('brand')).toBe(TONES.brand)
    expect(toneColour('nonsense')).toBe(TONES.brand)
    expect(new Set(Object.values(TONES)).size).toBe(Object.keys(TONES).length)
  })
})

describe('the axis does not exaggerate', () => {
  it('starts a column chart at zero', () => {
    const { container } = render(
      <ColumnChart
        title="Net revenue"
        unitLabel="INR"
        points={[point('Apr', '1000000', '₹10,00,000'), point('May', '1010000', '₹10,10,000')]}
      />,
    )

    // A 1% difference must not fill the plot. With a zero baseline the two bars
    // are within a hair of each other.
    const heights = [...container.querySelectorAll('rect')]
      .map((rect) => Number.parseFloat(rect.getAttribute('height') ?? '0'))
      .filter((h) => h > 0)

    expect(heights.length).toBeGreaterThanOrEqual(2)
    const [a, b] = heights.slice(0, 2).sort((x, y) => x - y)
    expect(b / a).toBeLessThan(1.05)
  })
})
