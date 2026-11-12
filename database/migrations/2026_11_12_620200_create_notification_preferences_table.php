<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer notification preferences (P4-NOT-03). A missing row is the default: order
 * updates on, marketing off (consent first). The `security` category (OTP, login codes)
 * has no row and cannot be opted out of — App\Domain\Notification\Enums\NotificationCategory.
 * `customer_id` has no FK: `customers` is created by a later migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('channel', 16);
            $table->string('category', 32);
            $table->boolean('enabled');
            $table->string('source', 16);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'channel', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
