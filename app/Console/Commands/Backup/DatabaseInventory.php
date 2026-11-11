<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Illuminate\Database\Connection;

/**
 * Exact row counts and the applied migrations for one MySQL connection — what the
 * backup manifest records and the restore rehearsal compares.
 */
final readonly class DatabaseInventory
{
    public function __construct(private Connection $db) {}

    /** @return array<string, int> table => COUNT(*), base tables only, sorted by name */
    public function counts(): array
    {
        $tables = $this->db->select(
            "select table_name as name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name",
            [$this->db->getDatabaseName()],
        );

        $counts = [];

        foreach ($tables as $table) {
            $name = (string) $table->name;
            // information_schema.table_rows is an estimate for InnoDB; COUNT(*) is exact.
            $counts[$name] = (int) $this->db->table($name)->count();
        }

        return $counts;
    }

    /**
     * CHECKSUM TABLE per table: proves the restored CONTENT matches — Bangla text
     * included — not just the row count. Comparable on the same MySQL major version.
     *
     * @param  list<string>  $tables
     * @return array<string, string|null>
     */
    public function checksums(array $tables): array
    {
        $sums = [];

        foreach ($tables as $table) {
            $row = $this->db->selectOne('CHECKSUM TABLE `'.str_replace('`', '``', $table).'`');
            $sums[$table] = isset($row->Checksum) ? (string) $row->Checksum : null;
        }

        return $sums;
    }

    /** @return list<string> */
    public function migrations(): array
    {
        /** @var list<string> $names */
        $names = $this->db->table('migrations')->orderBy('migration')->pluck('migration')->map(fn (mixed $m): string => (string) $m)->values()->all();

        return $names;
    }
}
