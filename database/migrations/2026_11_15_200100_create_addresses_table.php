<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.3 `addresses` (P4-ORD-01). Division → district → upazila → area +
 * free-text line + landmark (rule 31 — the landmark materially reduces failed deliveries,
 * so it is never dropped). `upazila_id` is nullable: a Dhaka-city address has no upazila.
 * Postcode is optional everywhere (no official bulk list exists).
 *
 * Orders never reference an address row — they snapshot it (§3.4), so editing or
 * deleting an address never rewrites an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('label', 64)->nullable();
            $table->string('recipient_name', 160);
            $table->string('phone', 16)->comment('E.164');
            $table->string('alt_phone', 16)->nullable()->comment('E.164');
            $table->foreignId('division_id')->constrained('divisions')->restrictOnDelete();
            $table->foreignId('district_id')->constrained('districts')->restrictOnDelete();
            $table->foreignId('upazila_id')->nullable()->constrained('upazilas')->restrictOnDelete();
            $table->string('area', 160)->nullable();
            $table->string('address_line', 500);
            $table->string('landmark', 255)->nullable();
            $table->string('postcode', 8)->nullable();
            $table->string('type', 16)->default('home')->comment('home|office|other');
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
