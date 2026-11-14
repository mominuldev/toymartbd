<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store settings › Branches & warehouses (Figma B · 38 241:50827): what kind of place a
 * stock location is, where it is, and one line of operating facts.
 *
 *  - `type` — warehouse | retail | pickup (App\Domain\Inventory\Enums\StockLocationType);
 *  - the address per rule 31: division → district → upazila → area + free-text line +
 *    landmark (the landmark is what gets a courier rider to the door);
 *  - `notes` — the facts line ("Pathao pickup 4–7 pm · 2 packers").
 *
 * Expand-only: every column is nullable with no backfill, so the default location the
 * 2026_11_14 migration seeds and the demo branches keep working untouched; nothing reads
 * these as required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->string('type', 16)->nullable()->after('description_bn')->comment('warehouse|retail|pickup');
            $table->foreignId('division_id')->nullable()->after('type')->constrained('divisions')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->after('division_id')->constrained('districts')->restrictOnDelete();
            $table->foreignId('upazila_id')->nullable()->after('district_id')->constrained('upazilas')->restrictOnDelete();
            $table->string('area', 160)->nullable()->after('upazila_id');
            $table->string('address_line', 500)->nullable()->after('area');
            $table->string('landmark', 255)->nullable()->after('address_line');
            $table->string('notes', 160)->nullable()->after('landmark');
        });
    }

    public function down(): void
    {
        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('upazila_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('division_id');
            $table->dropColumn(['type', 'area', 'address_line', 'landmark', 'notes']);
        });
    }
};
