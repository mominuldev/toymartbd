<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/**
 * `courier_settlements.status` (§3.6). `received`: imported, lines under review. `reconciled`:
 * a person confirmed it and its matched lines were booked to the ledger. (`expected` and
 * `disputed` from §3.6 are not stored states: an unresolved variance is shown as disputed.)
 */
enum SettlementStatus: string
{
    case Received = 'received';
    case Reconciled = 'reconciled';
}
