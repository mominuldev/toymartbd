<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * MySQL refuses an identifier over 64 characters (error 1059). Laravel's default index name is
 * table_columns_index, which overflows on long tables and multi-column keys — and the SQLite
 * runs never notice, so the failure only surfaced in CI's MySQL group (recommendation_events,
 * 2026-11-25; notification_template_versions before it). This checks every migrated index and
 * foreign key name on any driver.
 */
it('names every index and foreign key within MySQL’s 64-character limit', function (): void {
    $tooLong = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        $names = [
            ...array_column(Schema::getIndexes($table), 'name'),
            ...array_column(Schema::getForeignKeys($table), 'name'),
        ];
        foreach (array_filter($names) as $name) {
            if (strlen((string) $name) > 64) {
                $tooLong[] = "{$table}: {$name}";
            }
        }
    }

    expect($tooLong)->toBe([]);
});
