<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One AI call — counts only, never content (see the migration). Append-only: updating or
 * deleting a row throws.
 *
 * @property int $id
 * @property string $feature
 * @property string $provider
 * @property string $model
 * @property int|null $user_id
 * @property string $status
 * @property string|null $reason
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property int $latency_ms
 * @property CarbonImmutable $created_at
 */
final class AiRequest extends Model
{
    public const string OK = 'ok';

    public const string FAILED = 'failed';

    public const string BLOCKED = 'blocked';

    public const ?string UPDATED_AT = null;

    protected $table = 'ai_requests';

    /** @var list<string> */
    protected $fillable = ['feature', 'provider', 'model', 'user_id', 'status', 'reason', 'input_tokens', 'output_tokens', 'cost_usd', 'latency_ms'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('ai_requests is append-only.'));
        self::deleting(static fn () => throw new LogicException('ai_requests is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cost_usd' => 'decimal:6', 'created_at' => 'immutable_datetime', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'latency_ms' => 'integer'];
    }
}
