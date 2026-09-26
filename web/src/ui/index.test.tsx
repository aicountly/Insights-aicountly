/**
 * The shared primitives.
 *
 * Two things are worth pinning here. A modal that does not manage focus is a
 * visual effect rather than a dialog — a keyboard user ends up tabbing around
 * behind it. And the five states every panel needs have to read differently:
 * "unavailable" must never be mistakable for "zero", which is the single most
 * expensive confusion a finance dashboard can cause.
 */

import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { Badge, DeniedState, Dialog, EmptyState, ErrorState, LoadingState, UnavailableState } from './index'

describe('Dialog', () => {
  function open(onClose = vi.fn()) {
    const opener = document.createElement('button')
    opener.textContent = 'Open'
    document.body.append(opener)
    opener.focus()

    const view = render(
      <Dialog title="Share this dashboard" description="Who can open it" onClose={onClose}>
        <input aria-label="Person" />
      </Dialog>,
    )

    return { view, opener, onClose }
  }

  it('announces itself as a modal, labelled and described', () => {
    open()
    const dialog = screen.getByRole('dialog')

    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(document.getElementById(dialog.getAttribute('aria-labelledby') ?? '')?.textContent).toBe(
      'Share this dashboard',
    )
    expect(document.getElementById(dialog.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Who can open it')
  })

  it('moves focus inside on open and back to the opener on close', () => {
    const { view, opener } = open()

    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(true)

    view.unmount()
    expect(document.activeElement).toBe(opener)
    opener.remove()
  })

  it('closes on Escape', () => {
    const onClose = vi.fn()
    open(onClose)

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('keeps Tab inside the dialog', () => {
    open()
    const dialog = screen.getByRole('dialog')
    const focusable = [...dialog.querySelectorAll<HTMLElement>('button, input')]
    const last = focusable[focusable.length - 1]

    last.focus()
    fireEvent.keyDown(document, { key: 'Tab' })
    expect(dialog.contains(document.activeElement)).toBe(true)
    expect(document.activeElement).toBe(focusable[0])

    // And backwards from the first.
    focusable[0].focus()
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(last)
  })

  it('closes on the scrim but not on a click inside', () => {
    const onClose = vi.fn()
    open(onClose)

    fireEvent.mouseDown(screen.getByRole('dialog'))
    expect(onClose).not.toHaveBeenCalled()

    const scrim = document.querySelector('.insights-dialog-scrim')
    fireEvent.mouseDown(scrim as Element)
    expect(onClose).toHaveBeenCalledTimes(1)
  })
})

describe('the five states', () => {
  it('tells a screen reader it is loading rather than leaving silence', () => {
    render(<LoadingState label="Loading net revenue" />)
    const state = screen.getByText('Loading net revenue').closest('.insights-state')
    expect(state?.getAttribute('aria-busy')).toBe('true')
    expect(state?.getAttribute('aria-live')).toBe('polite')
  })

  it('reads an error out as an alert', () => {
    render(<ErrorState detail="Books did not answer." />)
    expect(screen.getByRole('alert').textContent).toContain('Books did not answer.')
  })

  it('offers a retry only when retrying could help', () => {
    const { unmount } = render(<ErrorState detail="Books did not answer." />)
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull()
    unmount()

    render(<ErrorState detail="Books did not answer." onRetry={() => {}} />)
    expect(screen.getByRole('button', { name: 'Try again' })).toBeTruthy()
  })

  it('never lets "unavailable" read as zero', () => {
    render(<UnavailableState />)
    // The words matter more than the styling here: this is the sentence that
    // stops somebody reporting a blank figure as a bad month.
    expect(screen.getByText(/unavailable, not zero/i)).toBeTruthy()
  })

  it('says denial is about the source product, not about Insights', () => {
    render(<DeniedState />)
    expect(screen.getByRole('heading').textContent).toBe('You do not have access to this')
    expect(screen.getByText(/what you can see there/i)).toBeTruthy()
  })

  it('distinguishes "nothing here" from "could not answer"', () => {
    render(<EmptyState title="No dashboards yet" detail="Create one, or start from a template." />)
    expect(screen.getByRole('heading').textContent).toBe('No dashboards yet')
    expect(screen.queryByText(/unavailable/i)).toBeNull()
  })
})

describe('Badge', () => {
  it('carries an icon and a word, so colour is not the only signal', () => {
    const { container } = render(<Badge tone="warn">Partial</Badge>)

    expect(screen.getByText('Partial')).toBeTruthy()
    expect(container.querySelector('svg')).not.toBeNull()
    expect(container.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true')
  })

  it('has an icon for every tone it offers', () => {
    // A tone with no icon renders nothing and React fails at runtime rather
    // than at build, so the whole set is exercised here.
    for (const tone of ['ready', 'warn', 'danger', 'info', 'neutral'] as const) {
      const { container, unmount } = render(<Badge tone={tone}>{tone}</Badge>)
      expect(container.querySelector('svg')).not.toBeNull()
      unmount()
    }
  })
})
