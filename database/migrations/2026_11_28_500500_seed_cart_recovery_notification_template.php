<?php

declare(strict_types=1);

use App\Domain\Notification\Actions\SyncDefaultTemplates;
use Illuminate\Database\Migrations\Migration;

/**
 * Inserts the `cart.recovery` SMS template (EN + BN, measured) — SyncDefaultTemplates only
 * adds catalog rows that do not exist yet, so an admin's edits are never overwritten.
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
