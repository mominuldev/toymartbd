<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "When did this background duty last succeed?" (P10 monitoring, SYSTEM-ARCHITECTURE §11).
 *
 * One row per key, overwritten in place. Keys in use:
 *  - `scheduler` — the scheduler's every-minute beat (routes/console.php);
 *  - `backup`, `backup.restore_rehearsal` — `toymart:backup` / `toymart:backup:restore-rehearsal`;
 *  - `courier.<code>` — the last answer from that courier's API (poll or booking);
 *  - `synthetic.cart` — the synthetic add-to-cart check.
 *
 * Recording never throws: monitoring must not break the duty it watches, so a failed write is
 * logged and swallowed. Writes are single-row upserts — no transaction, safe after an external
 * HTTP call (rule 4). `meta` and `last_error` carry operational detail only, never PII (rule 20).
 *
 * @property string $key
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $updated_at
 */
#[Table(name: 'system_heartbeats', key: 'key', keyType: 'string', incrementing: false)]
class SystemHeartbeat extends Model
{
    public const string SCHEDULER = 'scheduler';

    public const string BACKUP = 'backup';

    public const string RESTORE_REHEARSAL = 'backup.restore_rehearsal';

    public const string SYNTHETIC_CART = 'synthetic.cart';

    public const string COURIER_PREFIX = 'courier.';

    public const CREATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['key', 'last_success_at', 'last_failure_at', 'last_error', 'meta'];

    /** @param  array<string, scalar|null>  $meta */
    public static function beat(string $key, array $meta = []): void
    {
        self::record($key, ['last_success_at' => now(), 'meta' => $meta === [] ? null : $meta]);
    }

    public static function failed(string $key, string $error): void
    {
        self::record($key, ['last_failure_at' => now(), 'last_error' => Str::limit($error, 250, '…')]);
    }

    public static function lastSuccess(string $key): ?Carbon
    {
        try {
            return static::query()->find($key)?->last_success_at;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The most recent success among keys starting with $prefix (e.g. every courier).
     */
    public static function latestSuccessWithPrefix(string $prefix): ?Carbon
    {
        $value = static::query()->where('key', 'like', $prefix.'%')->max('last_success_at');

        return $value === null ? null : Carbon::parse((string) $value);
    }

    /** @param  array<string, mixed>  $values */
    private static function record(string $key, array $values): void
    {
        try {
            $now = now();
            $row = ['key' => $key, 'updated_at' => $now];
            foreach ($values as $column => $value) {
                $row[$column] = match (true) {
                    $value instanceof Carbon => $value->toDateTimeString(),
                    is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
                    default => $value,
                };
            }

            // One atomic statement: two workers beating the same key never collide.
            static::query()->upsert([$row], ['key'], array_keys(array_diff_key($row, ['key' => true])));
        } catch (Throwable $e) {
            Log::warning('Heartbeat not recorded.', ['heartbeat' => $key, 'exception' => $e::class]);
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
