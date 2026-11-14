<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS register sessions — the cash drawer (Figma B · 33, DATABASE-DESIGN §11).
 *
 * One row per drawer opened at a branch (`stock_locations`) on a named register. While a
 * session is open, `open_key` holds "{location_id}:{register}" and its UNIQUE index makes a
 * second open session on the same register impossible even under a race; closing sets it to
 * NULL (MySQL allows any number of NULLs in a unique index). `cashier_open_key` does the same
 * for the cashier: one open drawer per person.
 *
 * Money is DECIMAL(14,2) (rule 6). On close the row records expected cash (opening float +
 * cash kept from sales), what was counted and the difference (`over_short`, negative = short);
 * a closed session is never edited again (the model refuses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('register', 40)->comment('e.g. "Register 1"');
            $table->string('status', 16)->default('open')->comment('PosSessionStatus');
            $table->string('open_key', 80)->nullable()->unique()->comment('location:register while open, else NULL');
            $table->string('cashier_open_key', 40)->nullable()->unique()->comment('user id while open, else NULL');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 14, 2)->default('0.00');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('over_short', 14, 2)->nullable()->comment('counted − expected; negative = short');
            $table->string('close_note', 500)->nullable();
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['opened_by', 'status']);
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
