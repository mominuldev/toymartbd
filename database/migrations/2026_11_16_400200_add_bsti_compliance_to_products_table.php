<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE-DESIGN §3.1 (as built, P4 batch 5) — BSTI certification for a toy: the
 * certificate number, its expiry date and the certificate PDF. The PDF is content-sniffed
 * on upload and stored privately on the originals disk (`compliance/{product}/{ulid}.pdf`);
 * `bsti_certificate_path` is that key, never a public URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('bsti_certificate_no', 64)->nullable()->after('vat_rate');
            $table->date('bsti_expires_on')->nullable()->after('bsti_certificate_no');
            $table->string('bsti_certificate_path')->nullable()->after('bsti_expires_on');
            $table->string('bsti_certificate_name', 191)->nullable()->after('bsti_certificate_path');
            $table->unsignedInteger('bsti_certificate_size')->nullable()->after('bsti_certificate_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['bsti_certificate_no', 'bsti_expires_on', 'bsti_certificate_path', 'bsti_certificate_name', 'bsti_certificate_size']);
        });
    }
};
