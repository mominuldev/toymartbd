<?php

declare(strict_types=1);

use App\Domain\Notification\Actions\SyncDefaultTemplates;
use Illuminate\Database\Migrations\Migration;

/**
 * Data migration: the default EN + BN templates (05-content-and-assets-spec §A.17), so
 * every environment can send from the first deploy. Insert-only and idempotent — an
 * admin's edit is never overwritten. `php artisan toymart:notifications:sync-templates`
 * adds templates introduced later.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SyncDefaultTemplates::class)->handle();
    }

    public function down(): void
    {
        // Rows are dropped with the table by 2026_11_12_620000.
    }
};
