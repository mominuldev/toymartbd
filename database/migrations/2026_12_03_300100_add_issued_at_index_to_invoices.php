<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 performance (docs/audits/PERFORMANCE.md P-08). The invoices list filters `type IN (sales,
 * purchase)` (everything a finance user may see) and sorts newest first. (type, issued_at) cannot
 * serve that order across two types, so MySQL sorts every invoice on each page load. An index led
 * by issued_at lets it walk newest-first and stop after one page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->index(['issued_at', 'id'], 'invoices_issued_at_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_issued_at_id_idx');
        });
    }
};
