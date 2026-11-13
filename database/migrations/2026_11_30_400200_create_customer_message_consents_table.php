<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purpose-specific message consent (P8 notification automation). Today one purpose:
 * `birthday` — a birthday message uses `customers.date_of_birth`, which is personal data, so
 * it needs its own opt-in on top of the channel preferences. No row = NOT consented (the
 * default). Owned by Notification (it never writes `customers`); provenance kept: who
 * recorded it, from where, when it was given and when withdrawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_message_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('purpose', 32)->comment('birthday');
            $table->boolean('consented')->default(false);
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('source', 16)->comment('admin | storefront');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'purpose'], 'customer_message_consents_unique');
            $table->index(['purpose', 'consented'], 'customer_message_consents_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_message_consents');
    }
};
