<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

/**
 * Bangladesh divisions → districts → upazilas (P3-DAT-03), from the sourced dataset in
 * database/data/geo/ (provenance in SOURCE.md).
 *
 * Idempotent: every row is upserted by the dataset's own `id`, so re-running it updates
 * names, P-codes and zone flags in place and never duplicates or renumbers a row.
 * Zone flags come from dhaka-zones.json, keyed by upazila id — never by name (rule 32).
 */
final class GeographySeeder extends Seeder
{
    private const string DATA_DIR = 'data/geo';

    public function run(): void
    {
        $now = Carbon::now();

        $divisions = array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'name_bn' => (string) $row['name_bn'],
            'pcode' => $row['pcode'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->read('divisions.json'));

        $districts = array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'division_id' => (int) $row['division_id'],
            'name' => (string) $row['name'],
            'name_bn' => (string) $row['name_bn'],
            'pcode' => $row['pcode'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->read('districts.json'));

        $zones = $this->readZones();

        $upazilas = array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'district_id' => (int) $row['district_id'],
            'name' => (string) $row['name'],
            'name_bn' => (string) $row['name_bn'],
            'pcode' => $row['pcode'] ?? null,
            'is_inside_dhaka_metro' => in_array((int) $row['id'], $zones['is_inside_dhaka_metro'], true),
            'is_dhaka_subcity' => in_array((int) $row['id'], $zones['is_dhaka_subcity'], true),
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->read('upazilas.json'));

        DB::table('divisions')->upsert($divisions, ['id'], ['name', 'name_bn', 'pcode', 'updated_at']);
        DB::table('districts')->upsert($districts, ['id'], ['division_id', 'name', 'name_bn', 'pcode', 'updated_at']);

        foreach (array_chunk($upazilas, 200) as $chunk) {
            DB::table('upazilas')->upsert(
                $chunk,
                ['id'],
                ['district_id', 'name', 'name_bn', 'pcode', 'is_inside_dhaka_metro', 'is_dhaka_subcity', 'updated_at'],
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $file): array
    {
        $data = $this->decode($file);

        if (! array_is_list($data)) {
            throw new RuntimeException("Geography dataset {$file} must be a JSON list.");
        }

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /**
     * @return array{is_inside_dhaka_metro: list<int>, is_dhaka_subcity: list<int>}
     */
    private function readZones(): array
    {
        $data = $this->decode('dhaka-zones.json');
        $zones = [];

        foreach (['is_inside_dhaka_metro', 'is_dhaka_subcity'] as $flag) {
            $ids = $data[$flag] ?? null;

            if (! is_array($ids)) {
                throw new RuntimeException("dhaka-zones.json is missing the {$flag} list.");
            }

            $zones[$flag] = array_values(array_map(intval(...), $ids));
        }

        /** @var array{is_inside_dhaka_metro: list<int>, is_dhaka_subcity: list<int>} $zones */
        return $zones;
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $file): array
    {
        $path = database_path(self::DATA_DIR.'/'.$file);
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Geography dataset not found: {$path}");
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Geography dataset {$file} is not valid JSON.", 0, $e);
        }

        if (! is_array($data)) {
            throw new RuntimeException("Geography dataset {$file} must decode to an array.");
        }

        return $data;
    }
}
