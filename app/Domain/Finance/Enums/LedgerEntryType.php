<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/**
 * `financial_transactions.type` — every ledger entry type in DATABASE-DESIGN §3.7.
 * Adding a case is additive; renaming one rewrites history and is forbidden.
 */
enum LedgerEntryType: string
{
    case SaleRevenue = 'sale_revenue';
    case Cogs = 'cogs';
    case DiscountGiven = 'discount_given';
    case ShippingRevenue = 'shipping_revenue';
    case CourierCharge = 'courier_charge';
    case CourierCodFee = 'courier_cod_fee';
    case CourierReturnCharge = 'courier_return_charge';
    case GatewayFee = 'gateway_fee';
    case Packaging = 'packaging';
    case Marketing = 'marketing';
    case Salary = 'salary';
    case Rent = 'rent';
    case Utility = 'utility';
    case Refund = 'refund';
    case InventoryWriteOff = 'inventory_write_off';
    case SupplierPayment = 'supplier_payment';
    case CodReceived = 'cod_received';
    case OtherIncome = 'other_income';
    case OtherExpense = 'other_expense';

    /**
     * The direction an entry of this type normally takes. A per-type figure is reported
     * in this direction (an income type as in − out, a cost type as out − in), so a
     * reversing entry reduces it.
     */
    public function naturalDirection(): Direction
    {
        return match ($this) {
            self::SaleRevenue, self::ShippingRevenue, self::CodReceived, self::OtherIncome => Direction::In,
            default => Direction::Out,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
