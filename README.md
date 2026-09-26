# insights-aicountly

Business intelligence and analytics for Aicountly — a React single-page app
built with Vite and TypeScript, with a PHP API alongside it. Both halves deploy
to cPanel.

| Environment | App | API |
| --- | --- | --- |
| Production | https://insights.aicountly.com | https://insights.aicountly.com/api |
| Sandbox | https://insights.gh.aicountly.com | https://insights.gh.aicountly.com/api |

## What this app does

Insights answers questions about a company using figures read **live** from the
other AICOUNTLY products, as the signed-in user, on the request that draws them.

| Module | What it is |
| --- | --- |
| **Overview** | The headline figures for the chosen company, branch and period, with what changed and what needs attention. |
| **Dashboards** | Dashboards people build, share, publish and revisit. |
| **Dashboard builder** | A 12-column canvas with fourteen widget types, drag **and keyboard** move and resize, undo/redo, preview, revision history and explicit Save and Publish. |
| **Metrics** | The governed metric catalogue — every definition, its owner, its accounting basis and the endpoint it is bound to — plus custom KPIs written as validated formulas. |
| **Ask Insights** | A question in English, answered from figures that were actually fetched. Ask for a dashboard and it proposes one; nothing is created until you apply it. |
| **Forecasts** | Baseline projections with the method and its assumptions stated, and a backtest. No invented confidence. |
| **Exceptions** | Rule-based anomalies with their baseline, evidence and severity reasoning, and an acknowledge / dismiss / reopen trail. |
| **Reports** | Saved questions, answered live, exported as real PDF, CSV and XLSX. |
| **Data sources** | Which connected products are configured, reachable and permitted — separately, because they are different problems. |
| **Settings** | Preferences, access, and what this deployment can and cannot answer. |

Three rules run through all of it:

1. **Live reads only.** No replication, no shadow ledger, no warehouse, no cron
   sync. Insights' own database holds dashboards, saved definitions and
   preferences — never a source transaction.
2. **A missing figure is never zero.** Unavailable, denied, partial and not
   applicable are four different answers and are shown as four different
   answers.
3. **Nothing is counted twice.** Books owns the accounting effect of a sale, so
   a sale raised in Sales, Billing or POS is never added to revenue again. See
   [docs/INTEGRATION_MAP.md](docs/INTEGRATION_MAP.md).

Signing in is the AICOUNTLY portal's job, the same as every other AICOUNTLY
SaaS: the app redirects to the portal, the portal returns an `auth_token`, and
the app exchanges it for a short-lived session key. A user who is already signed
in to another AICOUNTLY product lands straight on the overview. Insights has no
password of its own and issues no token.

See [docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md),
[docs/INTEGRATION_MAP.md](docs/INTEGRATION_MAP.md) — what each figure is bound to
and what is deliberately not bound — and
[docs/COMPLETION_REPORT.md](docs/COMPLETION_REPORT.md), which says what has been
verified against fixtures and what has not been verified live.

## Layout

```
web/          React app (Vite). Builds to web/dist, deployed to the document root.
server-php/   PHP API. Deployed to the api/ folder inside the document root.
docs/         deployment, auth and integration notes
```

## Getting started

Requires Node.js 22 or newer.

```bash
cd web
npm install
cp ../.env.example ../.env
npm run dev
```

The dev server runs on http://localhost:5173 and signs in through the **sandbox**
portal. Point `VITE_API_BASE_URL` at the deployed sandbox API
(`https://insights.gh.aicountly.com/api`) so the token exchange has somewhere to
go — and add `http://localhost:5173` to `CORS_ALLOWED_ORIGINS` in that server's
`api/.env`, since localhost is the one case where the app and API are not
same-origin.

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm run preview` | Serve the production build locally |
| `npm run lint` | ESLint. Nothing is switched off to make the code pass |
| `npm run test` | Vitest |

The PHP API has no build step and no dependencies. To run it locally:

```bash
cd server-php
cp .env.example .env      # set APP_ENV=local, and DB_*
php bin/migrate.php
php -S localhost:8000
```

## Tests and verification

```bash
# Web
cd web
npm run lint && npm run typecheck && npm run test && npm run build

# API — against a throwaway PostgreSQL and a stub standing in for the fleet
server-php/tests/run.sh
```

`server-php/tests/stub/router.php` answers the **exact routes and payload
shapes** read out of Manage, Books and Inventory, and models the things that go
wrong — a company Manage refuses, a source that denies this caller, a source
that is unreachable, a list that reports more rows than it returned. A test that
passes against it is a test against the contracts the adapters really bind to.

### Browser verification

These run the built app and the API on one origin, exactly as they deploy, with
the stub underneath. They verify the **application**; they say nothing about
production data, and live-service verification is a separate exercise.

```bash
(cd web && npm run build)
server-php/tests/serve.sh                              # http://127.0.0.1:8793

node web/scripts/page-sweep-playwright.mjs --out /tmp/sweep   # every route at 3 widths
node web/scripts/flow-playwright.mjs --out /tmp/flow          # the acceptance walk-through
```

The sweep screenshots every route at desktop, tablet and mobile and fails on a
console error, a thrown error, horizontal overflow, or `NaN` in an SVG geometry
attribute. The flow signs in, opens a company, builds a dashboard from a
template, moves and resizes a widget **from the keyboard**, saves, reloads and
compares, shares, exports PDF/CSV/XLSX, and checks that a reply arriving after
the company changed is never painted.

Playwright is deliberately **not** a dependency of this app — it is a
verification tool, not something the product ships. Install it globally
(`npm i -g playwright`) or in `web/`. In the Aicountly container Chromium is
already present; do not run `playwright install`.

## Environment variables

`.env` is git-ignored and is never deployed — `.env.example` is the tracked
template. There are two of them, and they work in opposite ways:

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

| Variable | Description |
| --- | --- |
| `VITE_API_BASE_URL` | API base URL. Empty = this app's own origin + `/api` |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local`, `sandbox`, or `production` |
| `VITE_PRODUCT_KEY` | Portal product key. Derived from the hostname when unset |
| `VITE_PORTAL_LOGIN_URL` | Login portal override. Local development only |

The server's variables — the database, the source products' base URLs, the
Console AI binding and the inbound service keys — are documented inline in
[server-php/.env.example](server-php/.env.example). Every source base URL is
optional: unset, it is derived from this server's own hostname, so sandbox talks
to sandbox. A value naming a host outside the AICOUNTLY allowlist is ignored and
logged.

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token, or password in a `VITE_` variable.

### These are build-time values, not runtime values

This matters for how you change an endpoint in production.

Vite substitutes each `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files — **the app never reads a
`.env` from disk at runtime**, so placing a `.env` next to it in the cPanel
document root has no effect. Changing an endpoint means rebuilding and
redeploying.

This is the opposite of `server-php`, which is PHP and does read its own `.env`
on every request.

## Deployment

Deployment is **manual only**. Nothing deploys on push or merge — both
workflows trigger exclusively via `workflow_dispatch`.

To deploy: **Actions** → pick a workflow → **Run workflow** → pick a branch →
**Run**.

| Workflow | Does | To |
| --- | --- | --- |
| Deploy to cPanel Production | builds `web/dist/`, then rsyncs it and `server-php/` | document root, then `api/` inside it |
| Deploy to cPanel Sandbox | the same | document root, then `api/` inside it |
| Run database migrations | runs `bin/migrate.php` over the deploy SSH key | the chosen environment's `api/` |

### Running migrations

The database is only reachable from the server, so migrations run there. The
**Run database migrations** workflow does it over the same SSH key the deploys
use, and defaults to changing nothing:

| Mode | Effect |
| --- | --- |
| `status` (default) | lists what would run, writes nothing |
| `dry-run` | parses and applies each file, then rolls back |
| `apply` | applies and records them |

Each file runs in its own transaction and is recorded with a checksum, so a
half-applied migration cannot exist and an already-applied file is never run
twice. Editing a migration that has already been applied is reported rather
than silently reapplied.

**It cannot create `api/.env`, and it will stop if that file is missing.** The
`.env` holds the database password, is deliberately never deployed, and is
created once by hand on the server — `server-php/.env.example` documents every
value. Until it exists there is no database to migrate into, and the workflow
says so rather than failing with a connection error.

Production and sandbox deploy separately, so releasing to one cannot disturb
the other. Within one environment, web and API deploy together in the same
run — they always change in step, so there is no separate "API only" workflow
to remember to run. Source, `node_modules`, and `.env` never reach the server.

Before deploying, each workflow checks that every required SSH secret is set and
that the remote root is a safe path, so a misconfigured repository fails in
seconds instead of part-way through a deploy.

### Configuration

These repository **secrets** must be set (Settings → Secrets and variables →
Actions → Secrets):

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

`*_SSH_REMOTE_ROOT` is the document root to deploy into. It may be relative,
which is the usual cPanel form — `public_html` resolves against the SSH user's
home directory, giving `/home/<user>/public_html`. An absolute path works too.
Because the deploy runs with `--delete`, the workflow refuses a value that would
resolve to the home directory itself (`.`, `~`, empty), a system directory, or
anything containing `..`.

The repository **variables** `PROD_API_BASE_URL` and `SANDBOX_API_BASE_URL` are
optional. Unset, the app calls its own origin + `/api` — which is where the same
workflow puts the API. Set one only to point the app at a different API domain.

### Notes on the rsync steps

Each workflow runs two `rsync --delete` steps, one after the other, and the
excludes are what make that safe.

The **web** step syncs the document root and excludes:

- `api/` — the PHP backend lives inside the document root and is deployed by the
  next step in the same run. **Without this exclude the web step would delete
  the entire API.**
- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

The **API** step syncs `api/` and excludes `.env`, `.env.*` and `.git*`: the
API's `.env` is created once on the server and read at runtime, so it must
survive every deploy. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

`web/public/.htaccess` ships with the build and provides the SPA history
fallback — which is also what serves the portal's `/auth/callback` landing — plus
cache headers (`index.html` uncached, hashed assets cached for a year).
