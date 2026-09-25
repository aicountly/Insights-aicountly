# Integration map

Every figure Insights shows is read **live** from the product that owns it, on
the request that draws it, as the signed-in user. There is no replication, no
shadow ledger, no warehouse and no scheduled sync: this repository contains no
job that copies a source transaction, and the database schema has nowhere to put
one.

This file is the contract register. It says which endpoint each metric is bound
to, which product owns which fact, what is deliberately **not** bound, and what
Insights would need from a sibling product to bind it.

- Metric definitions: `server-php/src/Metrics/MetricCatalog.php`
- Adapters: `server-php/src/Adapters/`
- HTTP clients and host allowlist: `server-php/src/Clients/`

---

## Who owns what

| Fact | Owner | Insights' position |
| --- | --- | --- |
| Authentication, the user's identity | **my.aicountly.com** | Relays the portal's own session bootstrap; validates every `ses_key` with the portal. Insights has no password of its own and issues no token. |
| Company, branch, financial year | **manage.aicountly.com** | Stores the three ids and nothing else. Every scoped request asks Manage whether this caller may open this company. Names come from Manage, live, when they are shown. |
| Accounting effect of any transaction — revenue, purchases, tax, receivables, payables, cash | **Smart Books** | Reads it. Never recomputes it, and never counts a sale a second time from the product that raised it. |
| Quantity, cost, valuation, COGS | **Inventory** | Reads it. |
| Provider API keys for AI | **console.aicountly.org** | Asks Console to resolve the key for this domain and module at call time. The key is never stored here, never logged, never sent to the browser and never placed in a prompt. The customer-facing app has no provider-key field. |
| Orders, bills, tickets — operational volume | Sales, Purchases, Billing, POS | Reads **coverage and operational counts only**. See "Why the operational products bind no money metric". |
| Dashboards, widgets, saved metric definitions, report definitions, anomaly reviews, preferences, shares | **Insights** | Its own storage, and the only thing in its database. |

---

## Bound contracts

`binding` in the table is the exact string the metric carries at runtime and
reports as provenance; it is what a user sees in the evidence drawer.

| Metric | Label | Owner | Unit | Basis | Binding |
| --- | --- | --- | --- | --- | --- |
| `finance.net_revenue` | Net sales | books | currency | accrual | books GET /api/dashboard/sales -> data.kpis.total_sales |
| `finance.taxable_revenue` | Taxable sales | books | currency | accrual | books GET /api/dashboard/sales -> data.kpis.taxable_sales |
| `finance.sales_returns` | Sales returns | books | currency | accrual | books GET /api/dashboard/sales -> data.register_summary.sales_returns_amount |
| `finance.gst_collected` | GST collected | books | currency | accrual | books GET /api/dashboard/sales -> data.kpis.gst_collected |
| `finance.invoice_count` | Sales invoices | books | count | accrual | books GET /api/dashboard/sales -> data.kpis.total_invoices |
| `finance.avg_invoice_value` | Average invoice value | books | currency | accrual | books GET /api/dashboard/sales -> data.kpis.avg_invoice_value |
| `finance.collections` | Collections | books | currency | cash | books GET /api/dashboard/collections -> data.kpis.collections |
| `finance.payments_made` | Payments made | books | currency | cash | books GET /api/dashboard/collections -> data.kpis.payments_made |
| `finance.receivables` | Receivables | books | currency | balance | books GET /api/dashboard/sales -> data.kpis.receivables |
| `finance.overdue_receivables` | Overdue receivables | books | currency | balance | books GET /api/dashboard/sales -> data.kpis.overdue_receivables |
| `finance.payables` | Payables | books | currency | balance | books GET /api/dashboard/purchase -> data.kpis.payables |
| `finance.overdue_payables` | Overdue payables | books | currency | balance | books GET /api/dashboard/purchase -> data.kpis.overdue_payables |
| `finance.cash_and_bank` | Cash and bank | books | currency | balance | books GET /api/dashboard/default -> data.kpis.cash_and_bank |
| `finance.purchase_spend` | Purchase spend | books | currency | accrual | books GET /api/dashboard/purchase -> data.kpis.total_purchases |
| `finance.taxable_purchases` | Taxable purchases | books | currency | accrual | books GET /api/dashboard/purchase -> data.kpis.taxable_purchases |
| `finance.input_gst` | Input GST | books | currency | accrual | books GET /api/dashboard/purchase -> data.kpis.input_gst |
| `finance.gst_payable` | GST payable | books | currency | balance | books GET /api/dashboard/default -> data.kpis.gst_payable |
| `finance.income` | Income | books | currency | accrual | books GET /api/dashboard/default -> data.kpis.income |
| `finance.expense` | Expenses | books | currency | accrual | books GET /api/dashboard/default -> data.kpis.expense |
| `finance.net_profit` | Net profit | books | currency | accrual | books GET /api/dashboard/default -> data.kpis.net_profit |
| `finance.stock_value` | Stock value (Books) | books | currency | balance | books GET /api/dashboard/default -> data.kpis.stock_value |
| `inventory.stock_value` | Inventory value | inventory | currency | balance | inventory GET /api/v1/valuation -> meta.summary.total_value |
| `inventory.stock_quantity` | Stock quantity | inventory | quantity | balance | inventory GET /api/v1/valuation -> meta.summary.total_qty |
| `inventory.item_count` | Items in stock | inventory | count | balance | inventory GET /api/v1/valuation -> meta.summary.item_count |
| `inventory.ageing_value` | Stock ageing | inventory | currency | balance | inventory GET /api/v1/reports/stock-ageing -> data[].value by age bucket |
| `finance.gross_profit` | Gross profit | insights | currency | derived | derived: finance.net_revenue - finance.cogs (finance.cogs has no verified binding) |
| `finance.gross_margin` | Gross margin | insights | percent | derived | derived: finance.gross_profit / finance.net_revenue * 100 |
| `finance.collection_efficiency` | Collection efficiency | insights | percent | derived | derived: finance.collections / finance.net_revenue * 100 |
| `finance.overdue_share` | Overdue share of receivables | insights | percent | derived | derived: finance.overdue_receivables / finance.receivables * 100 |
| `finance.working_capital_tied` | Working capital in stock and receivables | insights | currency | derived | derived: inventory.stock_value + finance.receivables |
| `finance.cogs` | Cost of goods sold | inventory | currency | accrual | NOT BOUND — see docs/INTEGRATION_MAP.md, "Missing contracts" |

### Other bound reads

These are not metrics, so they carry no `binding` string, but they are contracts
all the same.

| What | Product | Endpoint | Used for |
| --- | --- | --- | --- |
| Company access | Manage | `GET /api/companyinfo?comp_id=` | Whether the caller may open this company; branches and financial years for the switcher. A refusal is a 403; an answer we could not get is a 503. |
| Company list | Manage | `GET /api/companies` | The company switcher. Manage decides which companies come back, because the call carries the caller's own `ses_key`. |
| Receivables and payables ageing | Books | `GET /api/dashboard/sales` → `data.receivables_ageing`, `GET /api/dashboard/purchase` → `data.payables_ageing` | The ageing widget. The source's own `total` bucket is deliberately dropped, or the chart would count the whole balance twice. |
| Top customers, suppliers, items, expenses | Books | `data.top_customers`, `data.top_suppliers`, `data.top_items`, `data.top_expenses` | Ranking widgets. Marked **partial**: a leading-entries list is not the population. |
| Collections and payments trend | Books | `GET /api/dashboard/collections` → `data.trend.points[]` | The collections chart. |
| Stock ageing | Inventory | `GET /api/v1/reports/stock-ageing` | The stock ageing widget. `meta.total` above the row count is reported as partial. |
| The caller's permissions in the source | Books, Inventory | `GET /api/access/me`, `GET /api/v1/access/me` | Turning "the source refused this" into a sentence naming the permission needed there. |
| Coverage | Sales, Purchases, Billing, POS | `GET /api/v1/permissions` (Sales, POS), `GET /api/v1/session` (Purchases, Billing), `GET /api/health` | Whether each product is configured, reachable and permitted — reported on Data sources, never converted into a figure. |
| AI credential | Console | `POST /ai/credentials/resolve` | Resolving a provider key for this domain and module, server-side, per call. |

### How a read is made

`server-php/src/Clients/ApiClient.php` is the only way out of this application:

- **Host allowlist.** A configured base URL naming a host outside
  `*.aicountly.com` / `*.aicountly.org` is ignored and logged. A user-supplied
  destination never reaches a request — there is no field anywhere in the
  product that accepts one.
- **The caller's own session key** goes on every source call. There is no broad
  service token that could see more than the person looking at the screen.
- **Bounded parallelism** (`MAX_CONCURRENCY = 6`) via `curl_multi`, with connect
  and total timeouts, redirects disabled, and a correlation id on every request
  and every log line.
- **Retries only for a safe read that failed in a retryable way** — transport
  failure, 429, 503. A 403 is never retried; it is an answer.
- **Credentials are redacted** from every log line.

---

## Missing contracts

These are the things Insights would show if the source exposed them. Each one is
declared in the catalogue and reports itself as **unavailable with a reason**,
which is not the same as zero and is never rendered as zero.

### `finance.cogs` — cost of goods sold

**Wanted:** COGS for a company, branch and date range.

**Why it is not bound:** Inventory owns cost and valuation, and its read
endpoints expose a valuation *as at* a date (`GET /api/v1/valuation`) and a
stock ageing breakdown. Neither is a cost-of-sales figure *for a period*.
Deriving it as opening + purchases − closing would be Insights inventing an
accounting figure from three other products' numbers, under assumptions
(valuation method, whether purchases include freight, what happened to returns)
that belong to Books and Inventory and not here.

**What would bind it:** a read-only endpoint on Inventory along the lines of

```
GET /api/v1/reports/cogs?cmp_id=&bo_id=&from=&to=
    -> { data: { cogs, method, opening_value, purchases, closing_value }, meta: { as_of } }
```

using Inventory's existing valuation service and its existing permission
(`reports.valuation.read`), so that the figure is the one Inventory itself would
print.

**What this costs today:** `finance.gross_profit` and `finance.gross_margin` are
derived from it, so both report unavailable, and both name **Cost of goods
sold** as the missing input rather than the nearest failed step. Nothing
substitutes zero, and no gross margin is ever shown as 100%.

### Multi-company consolidation

**Wanted:** one figure across several companies.

**Why it is not bound:** consolidation is an accounting operation — eliminating
intercompany balances, aligning financial years and currencies — and no product
in the fleet exposes a consolidated read. Summing what several companies each
report would produce a number that looks official and is wrong.

**Status:** the product reports it as unavailable and says why. It is not a
feature flag waiting to be switched on.

---

## Why the operational products bind no money metric

`server-php/src/Adapters/OperationalAdapter.php` returns an empty metric list,
deliberately, and there is a test that fails if any metric with an accounting
basis is given an owner other than Books or Inventory.

A sale raised in Sales, Billing or POS is posted into Books. Books is what
Insights reads for revenue. If Insights also read "total sales" from POS and
added it, one sale would be counted twice — and the total would drift by however
much of the business runs through each channel, which is exactly the kind of
error nobody notices until an audit.

So the operational products contribute **coverage**, not money: whether they are
configured, whether they answered, whether this caller may read them. That
appears on **Data sources** and in the source badges, and never in a figure.

The same rule is enforced structurally in three places:

1. `MetricDefinition::isCompatibleWith()` refuses to combine an accounting-basis
   metric with an operational one in a custom KPI.
2. `OperationalAdapter::metrics()` is empty, with the reason in the file.
3. `tests/integration.php` — *"only Books and Inventory own accounting
   metrics"*.

---

## What happens when a source cannot answer

Four different answers, deliberately distinguishable, because they need
different things from the person reading:

| Situation | Status | What the user sees |
| --- | --- | --- |
| The product answered | `available` | The figure, its definition, and where it came from. |
| It answered, but for part of the population | `partial` | The figure, marked partial, with what was and was not covered. |
| The product refused this caller | `denied` | *"Smart Books does not let this account see net sales. The permission needed there is `dashboard.read`."* |
| The product could not be reached, or is not configured | `unavailable` | *"…is unavailable, not zero."* with a retry where retrying could help. |
| A divisor was zero | `not_applicable` | *"Not applicable"* — never `0`, never `∞`, never `100%`. |

A missing figure never becomes `0`, and an unknown freshness is never labelled
"real time".
