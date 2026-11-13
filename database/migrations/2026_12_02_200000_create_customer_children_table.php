<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's children (P8 batch 3, owner decision 2026-11-13; DATABASE-DESIGN §3.3
 * "children_profiles", built smaller). Children's data, so minimised: an optional first name or
 * nickname, the birthday as month + day, and an OPTIONAL birth year — only because the approved
 * design shows an age ("Arian · 6 years"); nothing else (no gender, no interests). At most
 * {@see App\Domain\Customer\Models\CustomerChild::MAX_PER_CUSTOMER} per customer. A child the
 * parent removes is deleted, not soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 40)->nullable()->comment('first name or nickname, optional');
            $table->unsignedTinyInteger('birth_month');
            $table->unsignedTinyInteger('birth_day');
            $table->unsignedSmallInteger('birth_year')->nullable()->comment('optional: only to show an age');
            $table->timestamps();

            $table->index(['birth_month', 'birth_day'], 'customer_children_birthday_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_children');
    }
};
