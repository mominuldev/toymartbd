<?php

declare(strict_types=1);

// Finance report labels used in CSV exports (P5). The admin screens carry their own
// strings in resources/js/admin/i18n/messages.reports.ts.
return [
    'pnl' => [
        'block' => [
            'revenue' => 'What customers owed us',
            'goods' => 'What the toys cost us',
            'delivery' => 'What it cost to deliver and get paid',
            'losses' => 'What went wrong',
            'running' => 'What it cost to run the shop',
        ],
        'line' => [
            'product_revenue' => 'Product revenue',
            'delivery_revenue' => 'Delivery charges collected',
            'gross_revenue' => 'Gross revenue',
            'discounts' => 'Discounts and coupons',
            'net_revenue' => 'Net revenue',
            'cogs' => 'Cost of goods sold',
            'gross_profit' => 'Gross profit',
            'courier_charges' => 'Courier delivery charges',
            'cod_fees' => 'COD collection fees',
            'return_charges' => 'Courier return charges',
            'gateway_fees' => 'Payment gateway fees',
            'packaging' => 'Packaging',
            'profit_after_delivery' => 'Profit after delivery',
            'refunds' => 'Refunds to customers',
            'write_offs' => 'Damaged stock written off',
            'profit_after_losses' => 'Profit after losses',
            'marketing' => 'Advertising',
            'salaries' => 'Salaries',
            'rent' => 'Rent',
            'utilities' => 'Utilities',
            'other_expenses' => 'Other running costs',
            'other_income' => 'Other income',
            'net_profit' => 'Net profit',
        ],
        'expenses_by_category' => 'Expenses by category',
        'cash' => 'Cash, not profit',
        'cash_in' => 'Received',
        'cash_out' => 'Paid out',
        'cash_net' => 'Net cash movement',
    ],
    'valuation' => [
        'total' => 'Total',
    ],
    'couriers' => [
        'all' => 'All couriers',
        'no_district' => 'No district',
    ],
];
