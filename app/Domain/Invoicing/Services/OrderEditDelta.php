<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Ordering\Models\OrderItem;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What one order edit did to the money, split by direction (P8 batch 3, owner decision
 * 2026-11-13): every reduction goes on a credit note, every increase on a supplementary
 * invoice; one edit can produce both. Read from the `edited` order_events row EditOrder
 * writes (`meta.before` / `meta.after`: lines with quantity, unit price and line total, and
 * the order totals) — nothing is priced here (rule 1), only before and after are compared.
 *
 * Per line (matched on variant — EditOrder keeps one active line per variant), the change in
 * `line_total` (net of the line's discounts, so a coupon or promotion that moved with the
 * edit is in it):
 *  - fell → a credit line: `kind` `item` with the quantity removed when the quantity fell,
 *    else `adjustment` (the line's price or discount moved, no units came off);
 *  - rose → a supplementary line: `item` with the quantity added, else `adjustment`.
 * Delivery charge: a decrease is credited (`shipping`), an increase invoiced. Whatever the
 * lines and delivery do not explain of the grand-total change (nothing today: tax is zero) is
 * an explicit adjustment, so each document's total is exact and credit − supplementary
 * equals the edit's change in the order total, to the poisha.
 */
final readonly class OrderEditDelta
{
    /**
     * @param  list<array<string, mixed>>  $creditLines  CreditNote line shape
     * @param  list<array<string, mixed>>  $invoiceLines  Invoice line shape (+ `kind`)
     */
    public function __construct(
        public array $creditLines,
        public Money $creditShipping,
        public Money $creditAdjustment,
        public array $invoiceLines,
        public Money $invoiceShipping,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  the edited order_events row's meta
     * @param  Collection<int, OrderItem>  $items  the order's items (names, BN names, variants)
     */
    public static function fromEdit(array $meta, Collection $items): self
    {
        $before = self::lines($meta['before']['lines'] ?? []);
        $after = self::lines($meta['after']['lines'] ?? []);
        $byVariant = $items->sortBy('id')->keyBy('variant_id');

        $credit = [];
        $invoice = [];
        $variants = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($variants);
        foreach ($variants as $variantId) {
            $b = $before[$variantId] ?? null;
            $a = $after[$variantId] ?? null;
            $delta = ($a['line_total'] ?? Money::zero())->minus($b['line_total'] ?? Money::zero());
            if ($delta->isZero()) {
                continue;
            }
            $quantityDelta = ($a['quantity'] ?? 0) - ($b['quantity'] ?? 0);
            /** @var OrderItem|null $item */
            $item = $byVariant->get($variantId);
            $sku = (string) ($a['sku'] ?? $b['sku'] ?? $item->sku ?? '');
            $name = ['name' => $item !== null ? $item->product_name : $sku, 'name_bn' => $item?->product_name_bn, 'variant' => $item?->variant_name, 'sku' => $sku];

            if ($delta->isNegative()) {
                $units = $quantityDelta < 0;
                $credit[] = ['kind' => $units ? 'item' : 'adjustment'] + $name + [
                    'quantity' => $units ? -$quantityDelta : 0,
                    'unit_price' => ($b['unit_price'] ?? $a['unit_price'] ?? Money::zero())->toDecimal(),
                    'amount' => $delta->negate()->toDecimal(),
                ];
            } else {
                $units = $quantityDelta > 0;
                $invoice[] = ['kind' => $units ? 'item' : 'adjustment'] + $name + [
                    'quantity' => $units ? $quantityDelta : 0,
                    'unit_price' => ($a['unit_price'] ?? $b['unit_price'] ?? Money::zero())->toDecimal(),
                    'discount' => '0.00',
                    'line_total' => $delta->toDecimal(),
                ];
            }
        }

        $total = static fn (string $side, string $key): Money => Money::of((string) ($meta[$side]['totals'][$key] ?? '0.00'));
        $shipping = $total('after', 'shipping_charge')->minus($total('before', 'shipping_charge'));
        $creditShipping = $shipping->isNegative() ? $shipping->negate() : Money::zero();
        $invoiceShipping = $shipping->isNegative() ? Money::zero() : $shipping;

        $explained = self::sum($invoice, 'line_total')->plus($invoiceShipping)->minus(self::sum($credit, 'amount'))->minus($creditShipping);
        $residual = $total('after', 'grand_total')->minus($total('before', 'grand_total'))->minus($explained);
        $creditAdjustment = Money::zero();
        if ($residual->isNegative()) {
            $creditAdjustment = $residual->negate();
        } elseif (! $residual->isZero()) {
            $invoice[] = ['kind' => 'adjustment', 'name' => '', 'name_bn' => null, 'variant' => null, 'sku' => '', 'quantity' => 0, 'unit_price' => '0.00', 'discount' => '0.00', 'line_total' => $residual->toDecimal()];
        }

        return new self($credit, $creditShipping, $creditAdjustment, $invoice, $invoiceShipping);
    }

    public function creditSubtotal(): Money
    {
        return self::sum($this->creditLines, 'amount');
    }

    public function creditTotal(): Money
    {
        return $this->creditSubtotal()->plus($this->creditShipping)->plus($this->creditAdjustment);
    }

    public function invoiceSubtotal(): Money
    {
        return self::sum($this->invoiceLines, 'line_total');
    }

    public function invoiceTotal(): Money
    {
        return $this->invoiceSubtotal()->plus($this->invoiceShipping);
    }

    public function needsCreditNote(): bool
    {
        return $this->creditTotal()->greaterThan(Money::zero());
    }

    public function needsSupplementaryInvoice(): bool
    {
        return $this->invoiceTotal()->greaterThan(Money::zero());
    }

    /**
     * @param  mixed  $lines  the meta's line list
     * @return array<int, array{sku: string|null, quantity: int, unit_price: Money, line_total: Money}> keyed by variant id
     */
    private static function lines(mixed $lines): array
    {
        $out = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line) || ! isset($line['variant_id'])) {
                continue;
            }
            $out[(int) $line['variant_id']] = [
                'sku' => isset($line['sku']) ? (string) $line['sku'] : null,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'unit_price' => Money::of((string) ($line['unit_price'] ?? '0.00')),
                'line_total' => Money::of((string) ($line['line_total'] ?? '0.00')),
            ];
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private static function sum(array $lines, string $key): Money
    {
        return array_reduce($lines, static fn (Money $sum, array $line): Money => $sum->plus(Money::of((string) $line[$key])), Money::zero());
    }
}
