<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a host's live settings across a demo seed: every `settings` row is written to the
 * backups disk as JSON, the seed runs, and each row is restored exactly (encrypted values
 * byte for byte). Settings that did not exist before stay as the seeders wrote them.
 */
final class SettingsSnapshot
{
    /**
     * @param  callable(): void  $seed
     * @return array{file: string, restored: int}
     */
    public static function around(string $label, callable $seed): array
    {
        $snapshot = DB::table('settings')->get()->map(fn (object $row): array => (array) $row)->all();
        $file = 'settings-before-'.$label.'-'.now()->format('YmdHis').'.json';
        Storage::disk('backups')->put($file, (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        try {
            $seed();
        } finally {
            foreach ($snapshot as $row) {
                $key = $row['key'];
                unset($row['key']);
                DB::table('settings')->updateOrInsert(['key' => $key], $row);
            }
        }

        return ['file' => $file, 'restored' => count($snapshot)];
    }
}
