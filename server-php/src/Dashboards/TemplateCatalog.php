<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Adapters\AdapterRegistry;
use Aicountly\Api\Context;
use Aicountly\Api\Metrics\MetricCatalog;

/**
 * Starting points, as configuration only.
 *
 * NOT ONE FIGURE IN THIS FILE. A template names metrics, dimensions, grains and
 * layouts; it carries no sample revenue, no illustrative customer and no demo
 * numbers. Instantiating one produces a dashboard that is empty until it is
 * pointed at a company and asks the live products — which is the honest
 * behaviour, and also the only one that cannot ship a fictitious ₹12,00,000
 * into somebody's board room.
 *
 * EACH TEMPLATE DECLARES WHAT IT NEEDS. `requirements()` resolves the metrics a
 * template uses against the connected products and says, before anybody clicks
 * Create, which panels will work and which will not. A template that quietly
 * instantiates into six "unavailable" cards teaches people the product is
 * broken; one that says "four of these six panels need Inventory, which is not
 * connected" teaches them what to do next.
 */
final class TemplateCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::ownerExecutive(),
            self::financeCfo(),
            self::salesCollections(),
            self::procurementSuppliers(),
            self::inventoryHealth(),
            self::retailPos(),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        foreach (self::all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * What a template needs, checked against what is actually connected.
     *
     * @return array{
     *     ready: bool,
     *     products: list<array{product:string, label:string, status:string, message:?string, widgets:int}>,
     *     unavailable_widgets: list<array{title:string, metric:string, reason:string}>
     * }
     */
    public static function requirements(array $template, AdapterRegistry $registry, Context $ctx): array
    {
        $statuses = [];
        foreach ($registry->statuses($ctx) as $status) {
            $statuses[$status['product']] = $status;
        }

        $byProduct = [];
        $unavailable = [];

        foreach ($template['widgets'] as $widget) {
            $metricId = $widget['config']['metric_id'] ?? null;
            if (!is_string($metricId)) {
                continue;
            }
            $definition = MetricCatalog::get($metricId);
            if ($definition === null) {
                $unavailable[] = [
                    'title'  => (string) $widget['title'],
                    'metric' => $metricId,
                    'reason' => 'That metric is not in the catalogue in this version.',
                ];
                continue;
            }

            $product = $definition->owningProduct;
            if ($product === 'insights') {
                // A derived metric needs whatever its inputs need.
                foreach ($definition->dependsOn as $dependency) {
                    $owner = MetricCatalog::get($dependency)?->owningProduct;
                    if ($owner !== null && $owner !== 'insights') {
                        $byProduct[$owner] = ($byProduct[$owner] ?? 0) + 1;
                    }
                }
                if ($definition->dependsOn !== [] && in_array('finance.cogs', $definition->dependsOn, true)) {
                    $unavailable[] = [
                        'title'  => (string) $widget['title'],
                        'metric' => $metricId,
                        'reason' => 'Cost of goods sold is not exposed by any connected product, so this panel will report unavailable rather than a wrong figure.',
                    ];
                }
                continue;
            }

            $byProduct[$product] = ($byProduct[$product] ?? 0) + 1;

            $status = $statuses[$product] ?? null;
            if ($status !== null && ($status['status'] ?? '') !== 'ready') {
                $unavailable[] = [
                    'title'  => (string) $widget['title'],
                    'metric' => $metricId,
                    'reason' => (string) ($status['message'] ?? ucfirst($product) . ' is not answering.'),
                ];
            }
        }

        $products = [];
        foreach ($byProduct as $product => $count) {
            $status = $statuses[$product] ?? null;
            $products[] = [
                'product' => $product,
                'label'   => (string) ($status['label'] ?? ucfirst($product)),
                'status'  => (string) ($status['status'] ?? 'unknown'),
                'message' => $status['message'] ?? null,
                'widgets' => $count,
            ];
        }

        return [
            'ready'               => $unavailable === [],
            'products'            => $products,
            'unavailable_widgets' => $unavailable,
        ];
    }

    // -----------------------------------------------------------------------
    // The templates. Layouts are written at the desktop breakpoint; the tablet
    // and mobile coordinates are derived by WidgetSchema on save, so a template
    // author does not maintain three grids by hand.
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function ownerExecutive(): array
    {
        return [
            'key'         => 'owner_executive',
            'title'       => 'Owner overview',
            'description' => 'The figures a business owner checks first: what was sold, what came in, what is owed and what is tied up in stock.',
            'audience'    => 'Owner / Executive',
            'settings'    => ['preset' => 'this_month', 'grain' => 'month', 'compare' => 'previous_period'],
            'widgets'     => [
                self::kpi('Net sales', 'finance.net_revenue', 0, 0),
                self::kpi('Collections', 'finance.collections', 3, 0),
                self::kpi('Receivables', 'finance.receivables', 6, 0),
                self::kpi('Cash and bank', 'finance.cash_and_bank', 9, 0),
                self::chart('Sales over time', 'line', 'finance.net_revenue', 0, 2, 8, 4, 'month'),
                self::widget('ai_summary', 'What changed', 'finance.net_revenue', 8, 2, 4, 4, [
                    'extra_metrics' => ['finance.collections', 'finance.receivables'],
                ]),
                self::widget('ageing', 'Receivables by age', 'finance.receivables', 0, 6, 6, 4, []),
                self::widget('ranking', 'Top customers', 'finance.net_revenue', 6, 6, 6, 4, ['dimension' => 'customer', 'limit' => 8]),
                self::widget('source_status', 'Where these figures come from', null, 0, 10, 12, 3, []),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function financeCfo(): array
    {
        return [
            'key'         => 'finance_cfo',
            'title'       => 'Finance review',
            'description' => 'Income, expenses, profit and the tax position, with the working capital behind them.',
            'audience'    => 'Finance / CFO',
            'settings'    => ['preset' => 'this_quarter', 'grain' => 'month', 'compare' => 'previous_period'],
            'widgets'     => [
                self::kpi('Income', 'finance.income', 0, 0),
                self::kpi('Expenses', 'finance.expense', 3, 0),
                self::kpi('Net profit', 'finance.net_profit', 6, 0),
                self::kpi('GST payable', 'finance.gst_payable', 9, 0),
                self::chart('Income over time', 'bar', 'finance.net_revenue', 0, 2, 6, 4, 'month'),
                self::widget('ranking', 'Largest expense heads', 'finance.expense', 6, 2, 6, 4, ['dimension' => 'expense_head', 'limit' => 8]),
                self::kpi('Working capital in stock and receivables', 'finance.working_capital_tied', 0, 6, 6, 2),
                self::kpi('Overdue share of receivables', 'finance.overdue_share', 6, 6, 6, 2),
                self::widget('comparison', 'This period against the last', 'finance.net_profit', 0, 8, 12, 4, []),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function salesCollections(): array
    {
        return [
            'key'         => 'sales_collections',
            'title'       => 'Sales and collections',
            'description' => 'What was invoiced against what was collected, and who still owes.',
            'audience'    => 'Sales and credit control',
            'settings'    => ['preset' => 'this_month', 'grain' => 'week', 'compare' => 'previous_period'],
            'widgets'     => [
                self::kpi('Net sales', 'finance.net_revenue', 0, 0),
                self::kpi('Collections', 'finance.collections', 3, 0),
                self::kpi('Collection efficiency', 'finance.collection_efficiency', 6, 0),
                self::kpi('Overdue receivables', 'finance.overdue_receivables', 9, 0),
                self::chart('Sales and collections', 'line', 'finance.collections', 0, 2, 8, 4, 'week'),
                self::widget('ageing', 'Receivables by age', 'finance.receivables', 8, 2, 4, 4, []),
                self::widget('ranking', 'Customers by sales', 'finance.net_revenue', 0, 6, 6, 4, ['dimension' => 'customer', 'limit' => 10]),
                self::widget('table', 'Collections by customer', 'finance.collections', 6, 6, 6, 4, ['dimension' => 'customer', 'limit' => 10]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function procurementSuppliers(): array
    {
        return [
            'key'         => 'procurement_suppliers',
            'title'       => 'Procurement and suppliers',
            'description' => 'What was bought, from whom, and what is owed to them.',
            'audience'    => 'Procurement',
            'settings'    => ['preset' => 'this_quarter', 'grain' => 'month', 'compare' => 'previous_period'],
            'widgets'     => [
                self::kpi('Purchase spend', 'finance.purchase_spend', 0, 0),
                self::kpi('Input GST', 'finance.input_gst', 3, 0),
                self::kpi('Payables', 'finance.payables', 6, 0),
                self::kpi('Overdue payables', 'finance.overdue_payables', 9, 0),
                self::chart('Spend over time', 'bar', 'finance.purchase_spend', 0, 2, 8, 4, 'month'),
                self::widget('ageing', 'Payables by age', 'finance.payables', 8, 2, 4, 4, []),
                self::widget('ranking', 'Suppliers by spend', 'finance.purchase_spend', 0, 6, 6, 4, ['dimension' => 'supplier', 'limit' => 10]),
                self::widget('ranking', 'Items by spend', 'finance.purchase_spend', 6, 6, 6, 4, ['dimension' => 'item', 'limit' => 10]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function inventoryHealth(): array
    {
        return [
            'key'         => 'inventory_health',
            'title'       => 'Inventory health',
            'description' => 'What the stock is worth, how old it is, and where it sits.',
            'audience'    => 'Inventory and operations',
            'settings'    => ['preset' => 'this_month', 'grain' => 'month', 'compare' => 'previous_period'],
            'widgets'     => [
                self::kpi('Inventory value', 'inventory.stock_value', 0, 0),
                self::kpi('Items in stock', 'inventory.item_count', 3, 0),
                self::kpi('Stock value in the accounts', 'finance.stock_value', 6, 0),
                self::kpi('Working capital in stock and receivables', 'finance.working_capital_tied', 9, 0),
                self::widget('ageing', 'Stock by age', 'inventory.ageing_value', 0, 2, 6, 4, []),
                self::widget('ranking', 'Items by value', 'inventory.stock_value', 6, 2, 6, 4, ['dimension' => 'item', 'limit' => 10]),
                self::widget('ranking', 'Value by warehouse', 'inventory.stock_value', 0, 6, 6, 4, ['dimension' => 'warehouse', 'limit' => 10]),
                self::widget('text', 'A note on the two stock figures', null, 6, 6, 6, 4, [
                    'text' => 'Inventory value is what the stock system says the stock is worth. Stock value in the accounts is the figure Smart Books carries on the balance sheet. They can differ while a valuation run is pending, and a persistent gap is worth investigating.',
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function retailPos(): array
    {
        return [
            'key'         => 'retail_pos',
            'title'       => 'Retail performance',
            'description' => 'Counter sales and the stock behind them. Money figures come from Smart Books so a till sale is not counted twice.',
            'audience'    => 'Retail / POS',
            'settings'    => ['preset' => 'last_30_days', 'grain' => 'day', 'compare' => 'previous_period'],
            'widgets'     => [
                self::kpi('Net sales', 'finance.net_revenue', 0, 0),
                self::kpi('Invoices', 'finance.invoice_count', 3, 0),
                self::kpi('Average invoice value', 'finance.avg_invoice_value', 6, 0),
                self::kpi('Sales returns', 'finance.sales_returns', 9, 0),
                self::chart('Daily sales', 'bar', 'finance.net_revenue', 0, 2, 8, 4, 'day'),
                self::widget('source_status', 'Connected products', null, 8, 2, 4, 4, []),
                self::widget('ranking', 'Items by sales', 'finance.net_revenue', 0, 6, 6, 4, ['dimension' => 'item', 'limit' => 10]),
                self::kpi('Inventory value', 'inventory.stock_value', 6, 6, 6, 2),
            ],
        ];
    }

    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function kpi(string $title, string $metricId, int $x, int $y, int $w = 3, int $h = 2): array
    {
        return self::widget('kpi', $title, $metricId, $x, $y, $w, $h, []);
    }

    /** @return array<string, mixed> */
    private static function chart(string $title, string $type, string $metricId, int $x, int $y, int $w, int $h, string $grain): array
    {
        return self::widget($type, $title, $metricId, $x, $y, $w, $h, ['grain' => $grain, 'chart_type' => $type]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function widget(string $type, string $title, ?string $metricId, int $x, int $y, int $w, int $h, array $config): array
    {
        if ($metricId !== null) {
            $config['metric_id'] = $metricId;
        }

        return [
            'widget_type' => $type,
            'title'       => $title,
            'description' => '',
            'config'      => $config,
            'layout'      => ['desktop' => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h]],
        ];
    }
}
