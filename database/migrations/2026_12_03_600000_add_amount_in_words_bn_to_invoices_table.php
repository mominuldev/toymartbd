<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9 batch 1 (Phase 8 closeout): the total in Bangla words on the immutable snapshot
 * (AmountInWords::bangla, table-driven lakh/crore). Expand only: a nullable column added at
 * the end (MySQL 8 adds it INSTANT, no table copy). Documents issued before this keep null —
 * their Bangla PDF prints no words, exactly as before; nothing is backfilled, because a
 * snapshot never changes after issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('amount_in_words_bn', 400)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('amount_in_words_bn');
        });
    }
};
