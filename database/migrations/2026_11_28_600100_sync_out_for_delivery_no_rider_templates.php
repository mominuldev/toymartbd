<?php

declare(strict_types=1);

use App\Domain\Notification\Actions\SyncDefaultTemplates;
use Illuminate\Database\Migrations\Migration;

/**
 * Inserts `order.out_for_delivery.no_rider` (SMS + WhatsApp, EN + BN, measured): the "out
 * for delivery" message sent when no rider number is recorded on the shipment — it drops the
 * rider line instead of naming our hotline (owner decision 2026-11-13). Insert-only and
 * idempotent: an admin's edits to existing templates are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SyncDefaultTemplates::class)->handle();
    }

    public function down(): void
    {
        // Templates are data an admin may have edited; leave them.
    }
};
