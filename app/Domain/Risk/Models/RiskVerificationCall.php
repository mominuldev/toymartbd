<?php

declare(strict_types=1);

namespace App\Domain\Risk\Models;

use App\Domain\Risk\Enums\CallOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One verification call about a held order (insert-only: the call log is evidence).
 *
 * @property int $id
 * @property int $order_id
 * @property string $phone
 * @property CallOutcome $outcome
 * @property string|null $note
 * @property int|null $user_id
 * @property Carbon|null $created_at
 */
final class RiskVerificationCall extends Model
{
    public const null UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['order_id', 'phone', 'outcome', 'note', 'user_id'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('A verification call is insert-only.'));
        self::deleting(static fn (): never => throw new LogicException('A verification call is insert-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'outcome' => CallOutcome::class,
            'user_id' => 'integer',
        ];
    }
}
