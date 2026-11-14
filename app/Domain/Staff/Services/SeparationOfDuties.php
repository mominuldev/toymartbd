<?php

declare(strict_types=1);

namespace App\Domain\Staff\Services;

use App\Models\Enums\Permission as P;

/**
 * Pairs one person should not hold together (Figma B · 35's warning, "Order operator can
 * both issue and approve refunds. Separate those two."). Advisory: the editor warns, it does
 * not block — a two-person shop sometimes has to. Only pairs a role does NOT hold by default
 * are flagged, so the manager (everything but staff and roles, by design) is not nagged.
 */
final class SeparationOfDuties
{
    /** @return list<array{P, P}> */
    public static function pairs(): array
    {
        return [
            [P::RefundsCreate, P::RefundsApprove],
            [P::InventoryAdjust, P::InventoryApproveAdjustments],
            [P::PurchasingCreate, P::FinanceRecordSupplierPayments],
            [P::FinanceManageExpenses, P::FinanceExport],
        ];
    }

    /**
     * Pairs present in `$held` that are not both in `$defaults`.
     *
     * @param  list<string>  $held
     * @param  list<string>  $defaults
     * @return list<array{string, string}>
     */
    public static function introduced(array $held, array $defaults): array
    {
        $found = [];
        foreach (self::pairs() as [$a, $b]) {
            $has = in_array($a->value, $held, true) && in_array($b->value, $held, true);
            $default = in_array($a->value, $defaults, true) && in_array($b->value, $defaults, true);
            if ($has && ! $default) {
                $found[] = [$a->value, $b->value];
            }
        }

        return $found;
    }
}
