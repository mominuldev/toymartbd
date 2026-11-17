<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Models\User;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Database\Factories\AdSpendFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the owner spent on one ad campaign in one Dhaka month (P10, Analytics › Marketing ROI).
 * Typed in by staff — we never read an ad account. `campaign` matches `orders.utm_campaign`;
 * `month` is the first day of the month. Unique per (campaign, month). Every create, edit and
 * delete is audited through the Actions (CreateAdSpend / UpdateAdSpend / DeleteAdSpend).
 *
 * @property int $id
 * @property string $campaign
 * @property string|null $source
 * @property Carbon $month
 * @property Money $amount
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read User|null $updater
 */
final class AdSpend extends Model
{
    /** @use HasFactory<AdSpendFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['campaign', 'source', 'month', 'amount', 'note', 'created_by', 'updated_by'];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => MoneyCast::class,
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    protected static function newFactory(): AdSpendFactory
    {
        return AdSpendFactory::new();
    }
}
