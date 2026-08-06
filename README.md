# Insights-aicountly

Insights front end for Aicountly — a React single-page app built with Vite and
TypeScript. The API backend is **server-php**, running on the live cPanel portal.

| Environment | URL |
| --- | --- |
| Production | https://insights.aicountly.com |
| Sandbox | https://insights.gh.aicountly.com |

## Getting started

Requires Node.js 22 or newer.

```bash
npm install
cp .env.example .env    # then set VITE_API_BASE_URL to your server-php endpoint
npm run dev
```

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `dist/` |
| `npm run typecheck` | Type-check only |
| `npm run preview` | Serve the production build locally |

## Environment variables

`.env` is git-ignored and is never deployed — `.env.example` is the tracked
template. Copy it and fill in real values locally.

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token, or password in a `VITE_` variable.

| Variable | Description |
| --- | --- |
| `VITE_API_BASE_URL` | Base URL of the server-php API |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local`, `sandbox`, or `production` |

## Deployment

Deployment is **manual only**. Nothing deploys on push or merge — the two
workflows trigger exclusively via `workflow_dispatch`.

To deploy: **Actions** → *Deploy to cPanel Sandbox* or *Deploy to cPanel
Production* → **Run workflow** → pick a branch → **Run**.

Each run installs dependencies, builds, and `rsync`s only the built `dist/`
to the cPanel document root over SSH. Source, `node_modules`, and `.env` never
reach the server.

Tick **Dry run** to preview exactly which files rsync would add, change, or
delete without writing anything. Worth doing before the first production run.

### Configuration

These repository **secrets** must be set (Settings → Secrets and variables →
Actions → Secrets):

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

These repository **variables** set the API endpoint per environment (Settings →
Secrets and variables → Actions → Variables):

| Variable | Used by |
| --- | --- |
| `PROD_API_BASE_URL` | Production deploy |
| `SANDBOX_API_BASE_URL` | Sandbox deploy |

If a variable is missing the build still succeeds but logs a warning and uses a
placeholder URL, so the app will visibly fail to reach the API rather than
quietly calling the wrong backend.

### Notes on the rsync step

`--delete` keeps the document root in sync with the build, but these paths are
excluded so a deploy cannot destroy them:

- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

`public/.htaccess` ships with the build and provides the SPA history fallback
so client-side routes survive a refresh, plus cache headers (`index.html`
uncached, hashed assets cached for a year).
