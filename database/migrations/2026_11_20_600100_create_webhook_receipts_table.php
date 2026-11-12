<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.12 `webhook_receipts` (P4-PAY-03): replay protection on the provider's
 * event id (UNIQUE provider + external_event_id) and the raw inbound payload kept for
 * dispute resolution. Only signature-verified payloads are written (rule 19: verify before
 * writing anything), so `signature_valid` is always true today; the column stays for a
 * provider whose payloads we must keep even when unverifiable.
 *
 * `headers` holds an allowlisted subset only — never Authorization or cookies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('external_event_id', 191);
            $table->boolean('signature_valid')->default(false);
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('source', 16)->default('webhook')->comment('webhook|return');
            $table->timestamp('processed_at')->nullable();
            $table->string('result', 32)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
    }
};
