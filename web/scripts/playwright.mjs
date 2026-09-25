/**
 * Resolve Playwright wherever it happens to be installed.
 *
 * It is deliberately NOT a dependency of this app: it is a verification tool,
 * not something the product ships, and adding a browser automation package to
 * the app's own lockfile would put it in every developer's install and in the
 * dependency audit for no runtime benefit. So this looks for it in the project
 * first and falls back to a global install.
 */

import { createRequire } from 'node:module'
import { execSync } from 'node:child_process'

const require = createRequire(import.meta.url)

function load() {
  try {
    return require('playwright')
  } catch {
    /* not installed locally — try the global root */
  }

  try {
    const globalRoot = execSync('npm root -g', { encoding: 'utf8' }).trim()
    return require(`${globalRoot}/playwright`)
  } catch {
    throw new Error(
      'Playwright is not installed. Install it globally (npm i -g playwright) or in web/, then re-run.\n' +
        'Do not run `playwright install` in this container: Chromium is already at /opt/pw-browsers.',
    )
  }
}

export const { chromium } = load()
