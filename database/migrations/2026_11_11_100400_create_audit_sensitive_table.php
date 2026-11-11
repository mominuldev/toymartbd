<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.10 — immutable, insert-only audit of price, cost, refund, role and
 * credential changes (PRD P-03).
 *
 * Enforcement is layered:
 *   1. App\Models\AuditSensitive throws on update/delete (model events and query builder).
 *   2. The production application DB user holds INSERT + SELECT only on this table.
 *      The DBA applies, after migrating (migrations run as a separate migrator user):
 *
 *        REVOKE ALL PRIVILEGES ON toymartbd.audit_sensitive FROM 'toymartbd_app'@'%';
 *        GRANT SELECT, INSERT ON toymartbd.audit_sensitive TO 'toymartbd_app'@'%';
 *
 *      MySQL has no table-level "deny", so the app user must not hold a schema-wide
 *      UPDATE/DELETE grant (`toymartbd.*`) either — grant those per table instead.
 *
 * `user_id` restricts deletes: a user with audit history is deactivated (`users.is_active`),
 * never deleted, and the FK can never rewrite an audit row via ON DELETE SET NULL.
 * There is no `updated_at` — a row never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_sensitive', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 64)->index();
            $table->nullableMorphs('model');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_sensitive');
    }
};
