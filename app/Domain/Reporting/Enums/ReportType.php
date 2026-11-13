<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * A report that can be scheduled by email. Each names the permissions a recipient must
 * hold to receive it — the same ones its admin screen needs — so an email never shows a
 * staff member more than their role may see (rule 20/21).
 */
enum ReportType: string
{
    case Sales = 'sales';
    case Pnl = 'pnl';
    case Couriers = 'couriers';

    /** @return list<Permission> */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::Sales => [Permission::ReportsView],
            self::Pnl => [Permission::FinanceView],
            self::Couriers => [Permission::ReportsView, Permission::ShipmentsView],
        };
    }

    public function canReceive(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }
        foreach ($this->requiredPermissions() as $permission) {
            if (! $user->checkPermissionTo($permission->value)) {
                return false;
            }
        }

        return true;
    }

    /** The admin screen the email links to. */
    public function adminPath(): string
    {
        return match ($this) {
            self::Sales => '/admin/analytics/sales',
            self::Pnl => '/admin/finance/pnl',
            self::Couriers => '/admin/analytics/couriers',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
