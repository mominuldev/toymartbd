<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.2 `suppliers` (P5 purchasing). Archived, never deleted: purchases
 * and payments reference it. `phone` / `alt_phone` are stored E.164 (rule 30).
 * `opening_balance` is what we already owed the supplier when the system went live
 * (positive = we owe them); the payable balance builds on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('alt_phone', 20)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address', 500)->nullable();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('tin', 32)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->string('status', 16)->default('active')->comment('SupplierStatus');
            $table->string('note', 1000)->nullable();
            $table->timestamps();

            $table->index(['status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
