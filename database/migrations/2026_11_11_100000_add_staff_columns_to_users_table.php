<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users` are staff accounts (customers live in `customers`, keyed by phone).
 *
 * Staff are deactivated, never deleted: `audit_sensitive.user_id` restricts deletes so the
 * immutable audit trail always resolves to a real actor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'last_login_at']);
        });
    }
};
