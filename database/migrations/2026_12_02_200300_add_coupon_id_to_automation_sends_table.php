<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Birthday reminders now remind a parent before each CHILD's birthday (P8 batch 3, owner
 * decision 2026-11-13): `automation_sends.subject_id` for `birthday` is now
 * `customer_children.id` (it was `customers.id`), and the send may carry a personal coupon —
 * `coupon_id` (a plain id: Notification never reads Marketing's table, it is for audit).
 *
 * The old customer-birthday rows are dropped: the automation never shipped, and a stale row
 * keyed on a customer id would silently block a child with the same id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_sends', function (Blueprint $table): void {
            $table->unsignedBigInteger('coupon_id')->nullable()->after('notification_log_id');
        });

        DB::table('automation_sends')->where('automation', 'birthday')->delete();
    }

    public function down(): void
    {
        Schema::table('automation_sends', function (Blueprint $table): void {
            $table->dropColumn('coupon_id');
        });
    }
};
