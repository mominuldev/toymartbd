<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Enums\LedgerEntryType as T;

/**
 * The five-block statement (04-admin-spec A19 / B7 / C8): which ledger types make each
 * line, and where the running subtotals fall. This is the only place that decides it.
 *
 * Every amount is the entry's **effect on profit**: Σ in − Σ out over recognised entries
 * (`is_recognised_revenue`, rule 10). Revenue lines come out positive and cost lines
 * negative, and a reversal (an `in` cogs entry when a return is restocked, say) nets
 * against its own line without special cases.
 *
 * Not drawn as its own line: the spec's "written-down returns". The ledger has no type for
 * it; a write-down is recorded as `inventory_write_off` and shows in "Damaged stock
 * written off". `supplier_payment` and `cod_received` are cash movements, never P&L — a
 * recognised entry of either is reported as `unmapped` rather than silently counted.
 */
final class PnlLayout
{
    /** Subtotal keys, in statement order. */
    public const array SUBTOTALS = ['gross_revenue', 'net_revenue', 'gross_profit', 'profit_after_delivery', 'profit_after_losses', 'net_profit'];

    /**
     * @return list<array{key: string, number: int, rows: list<array{kind: 'line', key: string, types: list<T>}|array{kind: 'subtotal', key: string}>}>
     */
    public static function blocks(): array
    {
        $line = static fn (string $key, T ...$types): array => ['kind' => 'line', 'key' => $key, 'types' => array_values($types)];
        $subtotal = static fn (string $key): array => ['kind' => 'subtotal', 'key' => $key];

        return [
            ['key' => 'revenue', 'number' => 1, 'rows' => [
                $line('product_revenue', T::SaleRevenue),
                $line('delivery_revenue', T::ShippingRevenue),
                $subtotal('gross_revenue'),
                $line('discounts', T::DiscountGiven),
                $subtotal('net_revenue'),
            ]],
            ['key' => 'goods', 'number' => 2, 'rows' => [
                $line('cogs', T::Cogs),
                $subtotal('gross_profit'),
            ]],
            ['key' => 'delivery', 'number' => 3, 'rows' => [
                $line('courier_charges', T::CourierCharge),
                $line('cod_fees', T::CourierCodFee),
                $line('return_charges', T::CourierReturnCharge),
                $line('gateway_fees', T::GatewayFee),
                $line('packaging', T::Packaging),
                $subtotal('profit_after_delivery'),
            ]],
            ['key' => 'losses', 'number' => 4, 'rows' => [
                $line('refunds', T::Refund),
                $line('write_offs', T::InventoryWriteOff),
                $subtotal('profit_after_losses'),
            ]],
            ['key' => 'running', 'number' => 5, 'rows' => [
                $line('marketing', T::Marketing),
                $line('salaries', T::Salary),
                $line('rent', T::Rent),
                $line('utilities', T::Utility),
                $line('other_expenses', T::OtherExpense),
                $line('other_income', T::OtherIncome),
                $subtotal('net_profit'),
            ]],
        ];
    }

    /** @return list<T> every type that belongs in the P&L */
    public static function pnlTypes(): array
    {
        $types = [];
        foreach (self::blocks() as $block) {
            foreach ($block['rows'] as $row) {
                if ($row['kind'] === 'line') {
                    array_push($types, ...$row['types']);
                }
            }
        }

        return $types;
    }

    /** @return list<string> */
    public static function pnlTypeValues(): array
    {
        return array_map(static fn (T $t): string => $t->value, self::pnlTypes());
    }
}
