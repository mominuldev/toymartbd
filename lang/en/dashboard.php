<?php

declare(strict_types=1);

// Dashboard export (Phase 8, GET /admin/dashboard/export). Labels say which basis each figure
// is on: "placed" is demand, "recognised" is the ledger's revenue on delivery, "cash" is COD
// remitted (rule 10). The admin screen carries its own strings in resources/js/admin/i18n.
return [
    'sheet' => [
        'summary' => 'Summary',
        'trend' => 'Trend',
        'status' => 'Orders by status (now)',
        'categories' => 'Revenue by category',
        'best_sellers' => 'Best sellers (placed)',
        'low_stock' => 'Low stock (now)',
    ],
    'metric' => [
        'range' => 'Period (Dhaka dates)',
        'previous_range' => 'Compared with (Dhaka dates)',
        'granularity' => 'Trend buckets',
        'exported_at' => 'Exported at (Dhaka time)',
        'orders_placed' => 'Orders placed (incl. later cancelled)',
        'placed_value' => 'Order value placed (incl. later cancelled)',
        'average_order_value' => 'Average order value (placed)',
        'recognised_revenue' => 'Net revenue recognised (delivered)',
        'gross_profit' => 'Gross profit (recognised)',
        'gross_margin_percent' => 'Gross margin % (recognised)',
        'net_profit' => 'Net profit (recognised)',
        'net_margin_percent' => 'Net margin % (recognised)',
        'cash_collected' => 'Cash collected (COD remitted) — cash, not revenue',
        'customers' => 'Customers who placed an order',
        'new_customers' => 'New customers (first order in period)',
        'returning_customers' => 'Returning customers',
        'low_stock' => 'Variants low on stock (now)',
        'out_of_stock' => 'Variants out of stock (now)',
        'open_orders' => 'Open orders, not yet revenue (now)',
        'open_orders_value' => 'Open order value, not yet revenue (now)',
    ],
    'granularity' => [
        'day' => 'Day',
        'week' => 'Week (Saturday–Friday)',
        'month' => 'Month',
    ],
    'status' => [
        'pending' => 'Pending',
        'on_hold' => 'On hold',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'ready_to_ship' => 'Ready to ship',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
        'partially_returned' => 'Partially returned',
    ],
    'other_categories' => 'Other categories',
    'no_category' => 'No category',
    'total' => 'Total',
    'all_products' => 'All products placed in the period',
];
