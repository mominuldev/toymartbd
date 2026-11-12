<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.7 `expense_categories` (P5 finance). `type` is ExpenseCategoryType
 * (cogs|operating|marketing|logistics|other); `parent_id` allows one level of grouping.
 * `ledger_type` (an addition) picks the ledger type its approved expenses post as (rent,
 * salary, utility…); null derives it from `type`.
 * Created before `financial_transactions` because the ledger's `category_id` points here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('name_bn', 120)->nullable();
            $table->string('type', 16)->comment('ExpenseCategoryType');
            $table->string('ledger_type', 32)->nullable()->comment('LedgerEntryType for its expenses; null = derived from type');
            $table->boolean('is_fixed')->default(false);
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['parent_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
