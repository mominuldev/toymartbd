<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice numbers (P8, PRD N-13): one gap-free counter per series (`INV` customer, `PINV`
 * supplier/purchase) per Dhaka year — `INV-26-000001`. The row is locked `FOR UPDATE`
 * inside IssueSalesInvoice / IssuePurchaseInvoice, in the same transaction as the invoice
 * insert, so a rolled-back issue returns its number and two concurrent issues serialise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table): void {
            $table->string('series', 8)->comment('INV | PINV');
            $table->unsignedSmallInteger('year')->comment('Dhaka calendar year');
            $table->unsignedInteger('last_value')->default(0);
            $table->primary(['series', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
