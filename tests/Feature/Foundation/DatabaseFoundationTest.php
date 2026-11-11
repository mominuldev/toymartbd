<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('round-trips Bangla text unchanged', function (): void {
    $name = 'খেলনা মার্ট — যুক্তাক্ষর ক্ষ ত্র জ্ঞ';

    $user = User::factory()->create(['name' => $name]);

    expect(User::query()->findOrFail($user->id)->name)->toBe($name);
});

it('stores timestamps in UTC while displaying in Dhaka', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.display_timezone'))->toBe('Asia/Dhaka')
        ->and(date_default_timezone_get())->toBe('UTC');
});

it('uses utf8mb4_0900_ai_ci on every table and text column', function (): void {
    $database = DB::connection()->getDatabaseName();

    $tables = DB::table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', $database)
        ->where('TABLE_COLLATION', '!=', 'utf8mb4_0900_ai_ci')
        ->pluck('TABLE_NAME');

    $columns = DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', $database)
        ->whereNotNull('COLLATION_NAME')
        ->where('COLLATION_NAME', '!=', 'utf8mb4_0900_ai_ci')
        ->selectRaw("CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS col")
        ->pluck('col');

    expect($tables)->toBeEmpty()->and($columns)->toBeEmpty();
})->group('mysql')->skip(onlyOnMysql(...), 'MySQL only — run make test-mysql');
