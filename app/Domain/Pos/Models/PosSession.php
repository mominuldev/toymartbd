<?php

declare(strict_types=1);

namespace App\Domain\Pos\Models;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Pos\Enums\PosSessionStatus;
use App\Domain\Pos\Exceptions\PosRefused;
use App\Models\User;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cash-drawer session on one register at one branch (Figma B · 33). Opened with a float,
 * closed once with a count; {@see \App\Domain\Pos\Actions\OpenRegisterSession} and
 * {@see \App\Domain\Pos\Actions\CloseRegisterSession} are its only writers. A closed session
 * is the audit record of that drawer and refuses any further change.
 *
 * @property int $id
 * @property int $location_id
 * @property string $register
 * @property PosSessionStatus $status
 * @property string|null $open_key
 * @property string|null $cashier_open_key
 * @property int $opened_by
 * @property Carbon $opened_at
 * @property Money $opening_float
 * @property int|null $closed_by
 * @property Carbon|null $closed_at
 * @property Money|null $expected_cash
 * @property Money|null $counted_cash
 * @property Money|null $over_short
 * @property string|null $close_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockLocation $location
 * @property-read User $opener
 * @property-read User|null $closer
 */
final class PosSession extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (self $session): void {
            if ($session->getRawOriginal('status') === PosSessionStatus::Closed->value) {
                throw PosRefused::sessionClosed();
            }
        });
        self::deleting(static fn (): never => throw PosRefused::sessionClosed());
    }

    public function isOpen(): bool
    {
        return $this->status === PosSessionStatus::Open;
    }

    public static function openKey(int $locationId, string $register): string
    {
        return $locationId.':'.mb_strtolower(trim($register));
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<PosSale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'session_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'status' => PosSessionStatus::class,
            'opened_by' => 'integer',
            'closed_by' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => MoneyCast::class,
            'expected_cash' => MoneyCast::class,
            'counted_cash' => MoneyCast::class,
            'over_short' => MoneyCast::class,
        ];
    }
}
