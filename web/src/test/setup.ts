/**
 * Shared test setup.
 *
 * Testing Library only auto-cleans when Vitest is run with `globals: true`.
 * This suite imports `describe`/`it`/`expect` explicitly instead, so the
 * unmount has to be registered here — without it every render stacks up in the
 * same document and the second `getByRole('table')` in a file fails with
 * "found multiple elements", which looks like a component bug and is not one.
 */

import { afterEach } from 'vitest'
import { cleanup } from '@testing-library/react'

afterEach(() => {
  cleanup()
})
