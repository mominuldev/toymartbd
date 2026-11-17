<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ad spend typed in by staff (P10, Analytics › Marketing ROI). One row per campaign per Dhaka
 * month: `campaign` is the `utm_campaign` value it pays for, `month` the first day of that
 * month. Typed by hand — we never read an ad account. Every create, edit and delete is audited
 * (audit_sensitive), so the row itself carries only who created and last changed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_spends', function (Blueprint $table): void {
            $table->id();
            $table->string('campaign', 128)->comment('orders.utm_campaign it applies to');
            $table->string('source', 64)->nullable()->comment('facebook, google … (display only)');
            $table->date('month')->comment('First day of a Dhaka calendar month');
            $table->decimal('amount', 14, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spends');
    }
};
