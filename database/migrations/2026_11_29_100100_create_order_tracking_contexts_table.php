<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shopper's browser context at checkout (PRD K-02): what the server-side `purchase`
 * needs to match the buyer (client IP, user agent, GA client/session ids, Meta fbp/fbc
 * cookies) plus the consent they gave. One row per order, written by
 * RecordOrderTrackingContext after PlaceOrder commits — never inside its transaction.
 *
 * IP, user agent and click ids are personal data. Retention: `toymart:tracking:prune-contexts`
 * (daily) blanks them `config('tracking.context_retention_days')` (90) days after the row was
 * written and stamps `redacted_at`; the consent record is kept as evidence of what the
 * shopper agreed to. A purchase on delivery or a refund after that point is sent with
 * server context only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_tracking_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('client_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ga_client_id', 100)->nullable();
            $table->string('ga_session_id', 100)->nullable();
            $table->string('fbp', 255)->nullable();
            $table->string('fbc', 255)->nullable();
            $table->string('event_source_url', 2048)->nullable();
            $table->json('consent')->nullable();
            $table->timestamp('redacted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tracking_contexts');
    }
};
