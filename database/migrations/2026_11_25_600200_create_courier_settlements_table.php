<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.6 `courier_settlements` (COD settlement reconciliation, §4.5): one
 * courier remittance — imported from the courier portal's export (CSV/XLSX) or entered by
 * hand — matched line by line to our shipments. Header totals are SUMS of its lines, written
 * at import. Additions to §3.6: `source` (csv|xlsx|manual), `variance_count`, who imported /
 * confirmed and when. `status`: `received` (imported, under review) → `reconciled`
 * (confirmed: ledger booked). `disputed` is shown when a variance line is unresolved.
 * UNIQUE(provider_id, reference): the same statement cannot be imported twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('courier_providers')->restrictOnDelete();
            $table->string('reference', 120)->comment("The courier's payment id / statement number");
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('cod_collected', 14, 2)->default('0.00');
            $table->decimal('delivery_charges', 14, 2)->default('0.00');
            $table->decimal('cod_fees', 14, 2)->default('0.00');
            $table->decimal('return_charges', 14, 2)->default('0.00');
            $table->decimal('adjustments', 14, 2)->default('0.00');
            $table->decimal('net_received', 14, 2)->default('0.00');
            $table->date('received_at')->comment('Dhaka date the money arrived');
            $table->string('status', 16)->default('received')->comment('SettlementStatus');
            $table->string('source', 8)->comment('csv|xlsx|manual');
            $table->unsignedInteger('variance_count')->default(0);
            $table->string('statement_path', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_id', 'reference']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_settlements');
    }
};
