<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.3 `customer_risk_profiles` — keyed on the PHONE (E.164), not the
 * customer, so a repeat fake-order caller is recognised as a new guest. The counts and
 * `internal_score` are OUR computation (a projection rebuilt from orders on every order
 * event); `courier_*` hold verified external data with its own timestamp and are never
 * blended into our score (rule 24). `status` is set only by staff, with a reason and who/when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_risk_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 16)->unique()->comment('E.164');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedInteger('returned_count')->default(0);
            $table->unsignedInteger('failed_delivery_count')->default(0);
            $table->unsignedInteger('refused_count')->default(0);
            $table->unsignedTinyInteger('internal_score')->default(0)->comment('0–100, ours');
            $table->json('score_breakdown')->nullable();
            $table->timestamp('score_computed_at')->nullable();
            $table->json('courier_data')->nullable();
            $table->timestamp('courier_checked_at')->nullable();
            $table->unsignedInteger('courier_delivered_count')->nullable();
            $table->unsignedInteger('courier_cancelled_count')->nullable();
            $table->string('status', 16)->default('normal')->comment('RiskProfileStatus');
            $table->string('status_reason', 500)->nullable();
            $table->foreignId('status_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_set_at')->nullable();
            $table->string('manual_note', 1000)->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();
            $table->index(['status']);
            $table->index(['internal_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_risk_profiles');
    }
};
