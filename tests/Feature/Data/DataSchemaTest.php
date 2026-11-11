<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the P3-DAT-01/02 tables', function (string $table): void {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'settings', 'activity_log', 'audit_sensitive',
    'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
]);

it('adds staff columns to users', function (): void {
    expect(Schema::hasColumns('users', ['is_active', 'last_login_at']))->toBeTrue();
});

it('gives audit_sensitive no updated_at', function (): void {
    expect(Schema::hasColumn('audit_sensitive', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('audit_sensitive', 'created_at'))->toBeTrue();
});

it('uses a string primary key on settings', function (): void {
    $primary = collect(Schema::getIndexes('settings'))->firstWhere('primary', true);

    expect($primary['columns'] ?? null)->toBe(['key']);
});

it('stores JSON columns natively on MySQL', function (): void {
    $types = DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
        ->whereIn('TABLE_NAME', ['settings', 'audit_sensitive'])
        ->whereIn('COLUMN_NAME', ['value', 'before', 'after'])
        ->pluck('DATA_TYPE')
        ->unique()
        ->values()
        ->all();

    expect($types)->toBe(['json']);
})->group('mysql')->skip(onlyOnMysql(...), 'MySQL only');
