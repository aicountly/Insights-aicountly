<?php

declare(strict_types=1);

namespace Aicountly\Api\Metrics;

/**
 * The governed metric registry.
 *
 * EVERY METRIC HERE IS BOUND TO AN ENDPOINT THAT EXISTS. The `binding` string
 * on each one names the product, the route and the field path, and those were
 * read out of the owning repository rather than assumed — see
 * docs/INTEGRATION_MAP.md, which lists the same set with the file and line each
 * was verified against.
 *
 * WHAT IS NOT HERE IS AS IMPORTANT AS WHAT IS. There is no "total revenue
 * across products", because the products do not each hold a share of revenue —
 * Books holds all of it, and the others hold the operational documents behind
 * it. There is no consolidated multi-company figure, because consolidation
 * needs intercompany elimination and a currency policy that this fleet does not
 * yet express. Adding either would produce a number that looks authoritative
 * and is wrong.
 *
 * Adding a metric means: find the endpoint, read what it actually returns,
 * write the definition sentence including its GST and returns treatment, and
 * declare which product's permission the viewer needs. A metric with a binding
 * nobody has checked is a liability.
 */
final class MetricCatalog
{
    /** @var array<string, MetricDefinition>|null */
    private static ?array $metrics = null;

    /** Dimensions the whole catalogue draws from. */
    public const DIMENSIONS = [
        'time'      => 'Time',
        'branch'    => 'Branch',
        'customer'  => 'Customer',
        'supplier'  => 'Supplier',
        'item'      => 'Item',
        'item_group' => 'Item group',
        'warehouse' => 'Warehouse',
        'ageing_bucket' => 'Ageing bucket',
        'expense_head'  => 'Expense head',
    ];

    /** @return array<string, MetricDefinition> */
    public static function all(): array
    {
        return self::$metrics ??= self::build();
    }

    public static function get(string $id): ?MetricDefinition
    {
        return self::all()[$id] ?? null;
    }

    public static function require(string $id): MetricDefinition
    {
        $metric = self::get($id);
        if ($metric === null) {
            throw new \InvalidArgumentException('Unknown metric: ' . $id);
        }

        return $metric;
    }

    public static function exists(string $id): bool
    {
        return isset(self::all()[$id]);
    }

    /** @return list<MetricDefinition> */
    public static function ownedBy(string $product): array
    {
        return array_values(array_filter(self::all(), static fn (MetricDefinition $m) => $m->owningProduct === $product));
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, MetricDefinition> */
    private static function build(): array
    {
        $metrics = [];
        foreach (self::definitions() as $definition) {
            $metrics[$definition->id] = $definition;
        }

        return $metrics;
    }

    /** @return list<MetricDefinition> */
    private static function definitions(): array
    {
        $timeGrains = ['day', 'week', 'month', 'quarter', 'year'];

        return [
            // ---------------------------------------------------------------
            // Books — the accounting authority. Nothing else may own these.
            // ---------------------------------------------------------------
            new MetricDefinition(
                id: 'finance.net_revenue',
                label: 'Net sales',
                definition: 'Sales invoices less credit notes for the period, GST inclusive. Cancelled vouchers are excluded; drafts are not counted because Books reports posted vouchers only.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/sales -> data.kpis.total_sales',
                measure: 'kpis.total_sales',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch', 'customer', 'item'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'up',
                drilldown: ['product' => 'books', 'route' => '/registers', 'params' => ['vch_type_id' => 18]],
            ),
            new MetricDefinition(
                id: 'finance.taxable_revenue',
                label: 'Taxable sales',
                definition: 'Sales invoices less credit notes for the period, EXCLUDING GST. This is the figure a GST return reconciles against; finance.net_revenue is the same sales with tax added.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/sales -> data.kpis.taxable_sales',
                measure: 'kpis.taxable_sales',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch', 'customer'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
                drilldown: ['product' => 'books', 'route' => '/registers', 'params' => ['vch_type_id' => 18]],
            ),
            new MetricDefinition(
                id: 'finance.sales_returns',
                label: 'Sales returns',
                definition: 'Credit notes raised in the period, GST inclusive. Already deducted from finance.net_revenue — adding the two together double counts the return.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/sales -> data.register_summary.sales_returns_amount',
                measure: 'register_summary.sales_returns_amount',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'down',
                drilldown: ['product' => 'books', 'route' => '/registers', 'params' => ['vch_type_id' => 2]],
            ),
            new MetricDefinition(
                id: 'finance.gst_collected',
                label: 'GST collected',
                definition: 'Output CGST, SGST and IGST on sales invoices, less the same on credit notes. Cess is excluded.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/sales -> data.kpis.gst_collected',
                measure: 'kpis.gst_collected',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'neutral',
            ),
            new MetricDefinition(
                id: 'finance.invoice_count',
                label: 'Sales invoices',
                definition: 'Posted sales invoices dated in the period. Cancelled invoices are excluded; credit notes are counted separately.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/sales -> data.kpis.total_invoices',
                measure: 'kpis.total_invoices',
                aggregation: 'count',
                unit: 'count',
                precision: 0,
                dimensions: ['time', 'branch', 'customer'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
            ),
            new MetricDefinition(
                id: 'finance.avg_invoice_value',
                label: 'Average invoice value',
                definition: 'Net sales divided by the number of posted sales invoices in the period. Not applicable when no invoice was raised.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/sales -> data.kpis.avg_invoice_value',
                measure: 'kpis.avg_invoice_value',
                aggregation: 'derived',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
            ),
            new MetricDefinition(
                id: 'finance.collections',
                label: 'Collections',
                definition: 'How far customer balances fell in the period, measured as the customer-side credit on posted receipt vouchers. It INCLUDES any discount allowed or TDS written off on the same voucher, because those reduce the balance too — the money that actually arrived is Cash received, which is smaller by exactly that amount. Receipts with no attributable party line are excluded and reported separately.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/collections -> data.kpis.collections',
                measure: 'kpis.collections',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch', 'customer'],
                grains: $timeGrains,
                accountingBasis: 'cash',
                sourcePermissions: ['dashboard.read'],
                drilldown: ['product' => 'books', 'route' => '/registers', 'params' => ['vch_type_id' => 13]],
            ),
            new MetricDefinition(
                id: 'finance.payments_made',
                label: 'Payments made',
                definition: 'How far supplier balances fell in the period, measured as the supplier-side debit on posted payment vouchers. It includes any discount received or TDS on the same voucher; the money that actually left is Cash paid.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/collections -> data.kpis.payments_made',
                measure: 'kpis.payments_made',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch', 'supplier'],
                grains: $timeGrains,
                accountingBasis: 'cash',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'neutral',
                drilldown: ['product' => 'books', 'route' => '/registers', 'params' => ['vch_type_id' => 9]],
            ),
            new MetricDefinition(
                id: 'finance.cash_received',
                label: 'Cash received',
                definition: 'Money that actually arrived in the period, measured as the cash- and bank-side debit on posted receipt vouchers. Smaller than Collections by any discount allowed or TDS on the same voucher, which reduce a customer balance without money moving.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/collections -> data.kpis.cash_received',
                measure: 'kpis.cash_received',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'cash',
                sourcePermissions: ['dashboard.read'],
                drilldown: ['product' => 'books', 'route' => '/registers', 'params' => ['vch_type_id' => 13]],
            ),
            new MetricDefinition(
                id: 'finance.cash_paid',
                label: 'Cash paid',
                definition: 'Money that actually left in the period, measured as the cash- and bank-side credit on posted payment vouchers.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/collections -> data.kpis.cash_paid',
                measure: 'kpis.cash_paid',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'cash',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'neutral',
                drilldown: ['product' => 'books', 'route' => '/registers', 'params' => ['vch_type_id' => 9]],
            ),
            new MetricDefinition(
                id: 'finance.receivables',
                label: 'Receivables',
                definition: 'Total owed by customers as at the end of the period. A balance, not a flow — it is read as at a date and must never be summed across dates.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/sales -> data.kpis.receivables',
                measure: 'kpis.receivables',
                aggregation: 'last',
                unit: 'currency',
                precision: 2,
                dimensions: ['customer', 'ageing_bucket', 'branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'down',
                drilldown: ['product' => 'books', 'route' => '/reports/bill-by-bill'],
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'finance.overdue_receivables',
                label: 'Overdue receivables',
                definition: 'The part of receivables whose due date has passed, as at the end of the period. Invoices with no due date recorded are treated as not yet due and are therefore excluded.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/sales -> data.kpis.overdue_receivables',
                measure: 'kpis.overdue_receivables',
                aggregation: 'last',
                unit: 'currency',
                precision: 2,
                dimensions: ['customer', 'ageing_bucket', 'branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'down',
                drilldown: ['product' => 'books', 'route' => '/reports/bill-by-bill'],
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'finance.payables',
                label: 'Payables',
                definition: 'Total owed to suppliers as at the end of the period. A balance, not a flow.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/purchase -> data.kpis.payables',
                measure: 'kpis.payables',
                aggregation: 'last',
                unit: 'currency',
                precision: 2,
                dimensions: ['supplier', 'ageing_bucket', 'branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'down',
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'finance.overdue_payables',
                label: 'Overdue payables',
                definition: 'The part of payables whose due date has passed, as at the end of the period.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/purchase -> data.kpis.overdue_payables',
                measure: 'kpis.overdue_payables',
                aggregation: 'last',
                unit: 'currency',
                precision: 2,
                dimensions: ['supplier', 'ageing_bucket', 'branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'down',
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'finance.cash_and_bank',
                label: 'Cash and bank',
                definition: 'Closing balance of cash and bank ledgers as at the end of the period, per the trial balance.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/default -> data.kpis.cash_and_bank',
                measure: 'kpis.cash_and_bank',
                aggregation: 'last',
                unit: 'currency',
                precision: 2,
                dimensions: ['branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['dashboard.read'],
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'finance.purchase_spend',
                label: 'Purchase spend',
                definition: 'Purchase invoices less debit notes for the period, GST inclusive.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/purchase -> data.kpis.total_purchases',
                measure: 'kpis.total_purchases',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch', 'supplier', 'item'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'neutral',
                drilldown: ['product' => 'books', 'route' => '/registers', 'params' => ['vch_type_id' => 11]],
            ),
            new MetricDefinition(
                id: 'finance.taxable_purchases',
                label: 'Taxable purchases',
                definition: 'Purchase invoices less debit notes for the period, EXCLUDING GST.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/purchase -> data.kpis.taxable_purchases',
                measure: 'kpis.taxable_purchases',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch', 'supplier'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'neutral',
            ),
            new MetricDefinition(
                id: 'finance.input_gst',
                label: 'Input GST',
                definition: 'Input CGST, SGST and IGST on purchase invoices, less the same on debit notes. Eligibility for credit is not assessed here — this is the tax charged, not the tax claimable.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/purchase -> data.kpis.input_gst',
                measure: 'kpis.input_gst',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'neutral',
            ),
            new MetricDefinition(
                id: 'finance.gst_payable',
                label: 'GST payable',
                definition: 'Closing balance of duties and taxes on the liability side less the asset side, floored at zero, as at the end of the period.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/default -> data.kpis.gst_payable',
                measure: 'kpis.gst_payable',
                aggregation: 'last',
                unit: 'currency',
                precision: 2,
                dimensions: ['branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'neutral',
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'finance.income',
                label: 'Income',
                definition: 'Total of income ledgers in the profit and loss account for the period, per the trial balance.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/default -> data.kpis.income',
                measure: 'kpis.income',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
            ),
            new MetricDefinition(
                id: 'finance.expense',
                label: 'Expenses',
                definition: 'Total of expense ledgers in the profit and loss account for the period, per the trial balance.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/default -> data.kpis.expense',
                measure: 'kpis.expense',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch', 'expense_head'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'down',
            ),
            new MetricDefinition(
                id: 'finance.net_profit',
                label: 'Net profit',
                definition: 'Income less expenses for the period, per the profit and loss account. Includes every income and expense ledger, not only trading.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/default -> data.kpis.net_profit',
                measure: 'kpis.net_profit',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['dashboard.read'],
            ),
            new MetricDefinition(
                id: 'finance.stock_value',
                label: 'Stock value (Books)',
                definition: 'The closing stock figure carried in the accounts as at the end of the period. Inventory.stock_value is the same quantity measured by the stock system; the two can differ while a valuation run is pending, and that difference is meaningful.',
                owningProduct: 'books',
                binding: 'books GET /api/dashboard/default -> data.kpis.stock_value',
                measure: 'kpis.stock_value',
                aggregation: 'last',
                unit: 'currency',
                precision: 2,
                dimensions: ['branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'neutral',
                isBalance: true,
            ),

            // ---------------------------------------------------------------
            // Inventory — the authority for quantity, cost and ageing.
            // ---------------------------------------------------------------
            new MetricDefinition(
                id: 'inventory.stock_value',
                label: 'Inventory value',
                definition: 'Valuation of stock on hand as at the end of the period, at the company\'s configured costing method. This is the stock system\'s own figure, not the balance-sheet one.',
                owningProduct: 'inventory',
                binding: 'inventory GET /api/v1/valuation -> meta.summary.total_value',
                measure: 'meta.summary.total_value',
                aggregation: 'last',
                unit: 'currency',
                precision: 2,
                dimensions: ['item', 'item_group', 'warehouse'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['reports.valuation.read'],
                betterWhen: 'neutral',
                drilldown: ['product' => 'inventory', 'route' => '/reports/valuation'],
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'inventory.stock_quantity',
                label: 'Stock quantity',
                definition: 'Total units on hand as at the end of the period, across every warehouse in scope. Units are not comparable across items; this is meaningful filtered to one item or one unit of measure.',
                owningProduct: 'inventory',
                binding: 'inventory GET /api/v1/valuation -> meta.summary.total_qty',
                measure: 'meta.summary.total_qty',
                aggregation: 'last',
                unit: 'quantity',
                precision: 3,
                dimensions: ['item', 'item_group', 'warehouse'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['reports.valuation.read'],
                betterWhen: 'neutral',
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'inventory.item_count',
                label: 'Items in stock',
                definition: 'Number of distinct items with a non-zero balance as at the end of the period.',
                owningProduct: 'inventory',
                binding: 'inventory GET /api/v1/valuation -> meta.summary.item_count',
                measure: 'meta.summary.item_count',
                aggregation: 'last',
                unit: 'count',
                precision: 0,
                dimensions: ['item_group', 'warehouse'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['reports.valuation.read'],
                betterWhen: 'neutral',
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'inventory.ageing_value',
                label: 'Stock ageing',
                definition: 'Value of stock on hand split by how long it has been held, as at the end of the period.',
                owningProduct: 'inventory',
                binding: 'inventory GET /api/v1/reports/stock-ageing -> data[].value by age bucket',
                measure: 'rows.value',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['ageing_bucket', 'item', 'item_group', 'warehouse'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'balance',
                sourcePermissions: ['reports.stock_ageing.read'],
                betterWhen: 'neutral',
                drilldown: ['product' => 'inventory', 'route' => '/reports/stock-ageing'],
                isBalance: true,
            ),

            // ---------------------------------------------------------------
            // Derived — computed here from the metrics above, exactly.
            // ---------------------------------------------------------------
            new MetricDefinition(
                id: 'finance.gross_profit',
                label: 'Gross profit',
                definition: 'Net sales less cost of goods sold. COGS is not exposed as a period figure by any verified endpoint in this fleet, so this metric is UNAVAILABLE rather than approximated from purchases — purchases are what was bought, not what was sold.',
                owningProduct: 'insights',
                binding: 'derived: finance.net_revenue - finance.cogs (finance.cogs has no verified binding)',
                measure: 'net_revenue - cogs',
                aggregation: 'derived',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'derived',
                sourcePermissions: ['dashboard.read'],
                dependsOn: ['finance.net_revenue', 'finance.cogs'],
            ),
            new MetricDefinition(
                id: 'finance.gross_margin',
                label: 'Gross margin',
                definition: 'Gross profit as a percentage of net sales. Not applicable when net sales is zero. Depends on COGS, which has no verified binding, so it reports unavailable rather than substituting zero.',
                owningProduct: 'insights',
                binding: 'derived: finance.gross_profit / finance.net_revenue * 100',
                measure: 'gross_profit / net_revenue * 100',
                aggregation: 'derived',
                unit: 'percent',
                precision: 2,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'derived',
                sourcePermissions: ['dashboard.read'],
                dependsOn: ['finance.gross_profit', 'finance.net_revenue'],
            ),
            new MetricDefinition(
                id: 'finance.collection_efficiency',
                label: 'Collection efficiency',
                definition: 'Collections as a percentage of net sales for the same period. Above 100% means older invoices were also collected; it is not a measure of how much of THIS period\'s sales was collected.',
                owningProduct: 'insights',
                binding: 'derived: finance.collections / finance.net_revenue * 100',
                measure: 'collections / net_revenue * 100',
                aggregation: 'derived',
                unit: 'percent',
                precision: 1,
                dimensions: ['time', 'branch'],
                grains: $timeGrains,
                accountingBasis: 'derived',
                sourcePermissions: ['dashboard.read'],
                dependsOn: ['finance.collections', 'finance.net_revenue'],
            ),
            new MetricDefinition(
                id: 'finance.overdue_share',
                label: 'Overdue share of receivables',
                definition: 'Overdue receivables as a percentage of total receivables, as at the end of the period. Not applicable when nothing is owed.',
                owningProduct: 'insights',
                binding: 'derived: finance.overdue_receivables / finance.receivables * 100',
                measure: 'overdue_receivables / receivables * 100',
                aggregation: 'derived',
                unit: 'percent',
                precision: 1,
                dimensions: ['branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'derived',
                sourcePermissions: ['dashboard.read'],
                betterWhen: 'down',
                dependsOn: ['finance.overdue_receivables', 'finance.receivables'],
                isBalance: true,
            ),
            new MetricDefinition(
                id: 'finance.working_capital_tied',
                label: 'Working capital in stock and receivables',
                definition: 'Inventory value plus receivables, as at the end of the period. Both are balances at the same date, which is what makes them addable.',
                owningProduct: 'insights',
                binding: 'derived: inventory.stock_value + finance.receivables',
                measure: 'stock_value + receivables',
                aggregation: 'derived',
                unit: 'currency',
                precision: 2,
                dimensions: ['branch'],
                grains: ['month', 'quarter', 'year'],
                accountingBasis: 'derived',
                sourcePermissions: ['dashboard.read', 'reports.valuation.read'],
                betterWhen: 'down',
                dependsOn: ['inventory.stock_value', 'finance.receivables'],
                isBalance: true,
            ),

            // ---------------------------------------------------------------
            // Unbound, and deliberately declared.
            //
            // Declaring a metric the product needs and cannot yet answer is how
            // a gap stays visible. The alternative — leaving it out — makes a
            // missing integration look like a design decision, and makes
            // "gross margin" quietly unavailable with no explanation on screen.
            // ---------------------------------------------------------------
            new MetricDefinition(
                id: 'finance.cogs',
                label: 'Cost of goods sold',
                definition: 'The inventory cost of what was sold in the period. Inventory owns this figure but exposes no period COGS endpoint, and Books carries it inside the trading account rather than as a field. Reported as unavailable until one of them exposes it — never approximated from purchases, which measures buying rather than selling.',
                owningProduct: 'inventory',
                binding: 'NOT BOUND — see docs/INTEGRATION_MAP.md, "Missing contracts"',
                measure: '',
                aggregation: 'sum',
                unit: 'currency',
                precision: 2,
                dimensions: ['time', 'branch', 'item', 'item_group'],
                grains: $timeGrains,
                accountingBasis: 'accrual',
                sourcePermissions: ['reports.valuation.read'],
                betterWhen: 'down',
            ),
        ];
    }
}
