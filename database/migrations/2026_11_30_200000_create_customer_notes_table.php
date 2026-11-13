<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff notes on a customer (DATABASE-DESIGN §3.3 `customer_notes`, checklist §2.8
 * "Customer profile: notes"). Append-only — the author and time are the audit trail;
 * a correction is a new note. Customers are never deleted, so the FK restricts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'created_at'], 'customer_notes_customer_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
