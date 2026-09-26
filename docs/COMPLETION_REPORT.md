# AICOUNTLY Insights — completion report

Branch `claude/inspiring-lamport-v4k15r` in `aicountly/Insights-aicountly` and
`aicountly/books-react-app`. Nothing is deployed; no production migration has
been run.

---

## 1. What was built

**API** — `server-php/`, 71 PHP files on the fleet micro-framework already used
by Sales, Purchases, Billing and POS (PHP 8.4, no Composer, PSR-4 autoloader,
one router, PDO on PostgreSQL). Three raw-SQL migrations.

| Area | Files |
| --- | --- |
| Exact decimal arithmetic, periods, Indian formatting | `src/Support/` |
| HTTP clients with a host allowlist, bounded parallelism, correlation ids | `src/Clients/` |
| Governed metric catalogue (33 definitions) and query service | `src/Metrics/` |
| Source adapters behind one interface | `src/Adapters/` |
| Dashboards, widget schema, templates, revisions, shares | `src/Dashboards/` |
| Forecasts and anomalies | `src/Analytics/` |
| The copilot: intents, grounding, schema-valid proposals | `src/Ai/` |
| PDF, CSV and XLSX writers | `src/Reports/` |

**Web** — `web/`, 56 TypeScript files: React 19, react-router 7, Vite 8,
TypeScript 5.9 strict, lucide-react. No chart library, no UI kit, no state
library. Production bundle 347 kB (110 kB gzipped).

**Modules** — all ten, each with loading, empty, error, denied and unavailable
states: Overview, Dashboards, Dashboard builder, Metrics, Ask Insights,
Forecasts, Exceptions, Reports, Data sources, Settings.

**Dashboards** — 14 widget types, 6 templates, 12/6/1-column layouts stored per
breakpoint, drag **and keyboard** move/resize/delete with an `aria-live`
announcement, undo/redo, preview, explicit Save and Publish, revision history
with restore, optimistic concurrency (409 with both revisions and who changed
it), sharing by user or company, and duplication.

**One endpoint was added to a source product.** Books reported what was
*invoiced* but nothing reported what was *collected* as a period figure.
`GET /api/dashboard/collections` was added to `books-react-app` using its
existing services, tables and `dashboard.read` permission — read-only, no new
tables, no master data. That is the only change to any repository other than
this one.

---

## 2. What is verified, and how

Two kinds of evidence, reported separately because they prove different things.

### Fixture-based (automated, reproducible)

| Suite | Result |
| --- | --- |
| `server-php/tests/run.sh` — API integration, real PostgreSQL + fleet stub | **59 passed, 0 failed** |
| `web` — `npx vitest run` | **76 passed** |
| `web` — `npm run lint`, `npm run typecheck`, `npm run build` | clean |
| `books-react-app` — `phpunit --testsuite unit` | **4231 passed** |
| `books-react-app` — `phpunit -c phpunit-integration.xml` | **281 run, 1 pre-existing failure** (see §5) |

The stub (`server-php/tests/stub/router.php`) answers the exact routes and
payload shapes read out of Manage, Books and Inventory, and models a company
Manage refuses, a source that denies the caller, a source that is unreachable,
and a list that reports more rows than it returned.

### Browser (automated, against the built app)

The built SPA and the API served on one origin, as they deploy
(`server-php/tests/serve.sh`), with the stub underneath.

| Harness | Result |
| --- | --- |
| `web/scripts/page-sweep-playwright.mjs` — every route at 1440, 900 and 390px | **27/27 clean** — no console error, no thrown error, no horizontal overflow, no `NaN` in an SVG attribute |
| `web/scripts/flow-playwright.mjs` — the acceptance walk-through | **41 passed, 0 failed, 1 not exercised** |

The walk-through: sign in through the portal callback → open a company → read
the overview and its evidence drawer → hold a request, switch company, release
it → create from a template → add and configure a widget → move and resize it
**from the keyboard only** → save → reload and compare the layout → share with a
colleague → publish → export PDF, CSV and XLSX and check the file signatures →
open it as the colleague → ask a question → ask for a dashboard and apply the
proposal.

### NOT verified live

**No figure in this report came from a production or sandbox AICOUNTLY
service.** This container cannot mint a live staff session — `my.aicountly.com`
is the only issuer and the portal is not reachable from here — so every run
above used the local stub. A compiled adapter and a green stub run are not proof
of a working live integration.

What remains to be checked against real services, in order:

1. `GET /api/dashboard/collections` on a deployed Books, against a company with
   history, to confirm the `unattributed` count is small and `collections`
   matches the receipt register.
2. Each binding in `docs/INTEGRATION_MAP.md` against a real payload — field
   names drift.
3. The Manage company-access call under a user who is a member rather than an
   owner.
4. The AI path end to end. No provider is configured here, so the model branch
   ran only in its rules-based fallback; the prompt assembly, schema validation
   and quoted-figure check are covered by fixtures, not by a real model.

---

## 3. What each product contributes

Full register in [INTEGRATION_MAP.md](INTEGRATION_MAP.md).

| Product | Contributes |
| --- | --- |
| **my.aicountly.com** | Authentication. Insights relays the portal's own session bootstrap and validates every `ses_key` with it. No password, no token of its own. |
| **manage.aicountly.com** | Company, branch, financial year — and whether this caller may open this company, asked on every scoped request. |
| **Smart Books** | 23 metrics: revenue, tax, purchases, receivables, payables, collections, cash, profit. |
| **Inventory** | 4 bound metrics — valuation, quantity, item count, stock ageing — and `finance.cogs`, declared and unbound (§4). |
| **Sales, Purchases, Billing, POS** | Coverage and links **only**. Their money is already recorded by Books; counting it again would double count a sale. Enforced by `OperationalAdapter::metrics() === []`, by `MetricDefinition::isCompatibleWith()`, and by a test. |
| **console.aicountly.org** | Provider AI keys, resolved server-side per call. Never stored here, never logged, never sent to the browser, never in a prompt. The customer-facing app has no key field. |
| **Insights** | 5 derived metrics, and its own configuration — dashboards, widgets, saved definitions, reviews, preferences. No source transaction. |

---

## 4. Missing contracts

Declared, visible in the UI on **Data sources**, and reported as unavailable —
never as zero.

**`finance.cogs` — cost of goods sold.** Inventory owns cost but exposes a
valuation *as at* a date, not a cost of sales *for a period*. Deriving it from
opening + purchases − closing would be Insights inventing an accounting figure
under assumptions that belong to Books and Inventory. `finance.gross_profit` and
`finance.gross_margin` depend on it and both report unavailable, naming **Cost
of goods sold** as the missing input rather than the nearest failed step. The
endpoint that would bind it is specified in INTEGRATION_MAP.md.

**Multi-company consolidation.** No product in the fleet exposes a consolidated
read, and summing what several companies each report is not consolidation. It is
reported unavailable with that reason.

---

## 5. Known limitations and things left undone

- **The one failing test in Books**,
  `VendorReconciliationImportIntegrationTest::testSavedMappingChangesPreview…`,
  fails identically on the parent commit. It is unrelated to this work and was
  not fixed.
- **The AI model path is untested against a real provider** (see §2).
- **Forecasts are baseline methods only** — moving average, linear trend,
  seasonal naive — each stating its method, its assumption and a backtest error.
  No prediction intervals: they need a statistical method that carries them, and
  a made-up interval is worse than none. A user's scenario adjustment is
  labelled a scenario, never a forecast.
- **Anomalies are rule-based**, with a baseline, evidence and a severity reason.
  Nothing is labelled fraud.
- **Exports** carry title, scope, period, filters, units, timestamp, freshness
  and warnings; CSV and XLSX neutralise a leading `= + - @` in a text cell while
  keeping numbers as numbers, so a negative figure stays negative and a SUM
  works.
- **`finance.cash_received` and `finance.cash_paid` have no trend.** Books'
  collections trend is the party side only; a cash-side series would be
  manufactured.
- **The dev seed is permissions and fixtures only.** No business value is seeded
  anywhere, and `tests/serve.sh` uses a throwaway database.

---

## 6. Problems found and fixed while verifying

Each was found by a harness, not by reading:

| Found by | Problem | Fix |
| --- | --- | --- |
| Route sweep | `/dashboards` answered **503** for anyone who owns the company. The visibility predicate collapses to `TRUE` for an owner, removing the only mention of `:uuid` from the count query, and PDO refuses a bound parameter a statement does not use. | Bind only what the statement names; regression test over all four scopes for an owner and a member. |
| Flow | Switching company painted **"That did not load — signal is aborted without reason"** and never re-asked. Ten of eleven pages listed the period in their dependencies and not the company. | The scope is now part of every question inside `useApi`, via a subscription to the API module's scope epoch, so a page cannot forget. Stale rejections are swallowed. Five regression tests. |
| Route sweep | A suggestion chip overflowed a 390px viewport by 31px. | Chips cap at the container; sentence-length chips wrap. |
| Flow | A rupee total was **printed over the widget beside it** in a three-column card. | `minmax(0, 1fr)` bodies, a container-query figure size, and a wrapping summary row. The check is now part of the flow harness. |
| Binding it from Insights | `finance.collections` claimed to exclude discount and TDS, but the customer-side credit it measures **includes** them. | Books now reports `collections` **and** `cash_received` (and the payment-side pair); each definition says which it is. |
| Binding it from Insights | The collections endpoint matched `l.acc_id = h.party_acc_id`, which is unset on older posts — a mature ledger would have read as almost entirely unattributed. | Books' own register rule: the credit line on a receipt, the debit line on a payment, outside the cash and bank scope. |

---

## 7. Running it

```bash
# API
cd server-php && cp .env.example .env     # set DB_* and APP_ENV=local
php bin/migrate.php
tests/run.sh                              # 59 integration tests

# Web
cd web && npm install
npm run lint && npm run typecheck && npm run test && npm run build

# The whole product, locally, on one origin
server-php/tests/serve.sh                 # http://127.0.0.1:8793
node web/scripts/page-sweep-playwright.mjs --out /tmp/sweep
node web/scripts/flow-playwright.mjs --out /tmp/flow
```

Deployment is unchanged and still manual: **Actions → Deploy to cPanel
Sandbox/Production → Run workflow**. Neither was run.
