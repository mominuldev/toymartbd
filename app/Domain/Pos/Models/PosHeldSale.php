<?php

declare(strict_types=1);

namespace App\Domain\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A parked cart ("Hold sale"): variant ids and quantities, the coupon code and the customer's
 * phone — never prices (resume re-prices from current data, rule 1) and never a stock
 * reservation. Resumed once.
 *
 * @property int $id
 * @property int $session_id
 * @property string|null $label
 * @property array<string, int> $lines variant id (as a JSON key) → quantity
 * @property string|null $coupon_code
 * @property string|null $customer_phone E.164
 * @property int $held_by
 * @property Carbon|null $resumed_at
 * @property int|null $resumed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PosSession $session
 * @property-read User $holder
 */
final class PosHeldSale extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<int, int> variant id → quantity, ascending variant id */
    public function lineMap(): array
    {
        $lines = [];
        foreach ($this->lines as $variantId => $quantity) {
            $lines[(int) $variantId] = (int) $quantity;
        }
        ksort($lines);

        return $lines;
    }

    /** @return BelongsTo<PosSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'session_id' => 'integer',
            'lines' => 'array',
            'held_by' => 'integer',
            'resumed_by' => 'integer',
            'resumed_at' => 'datetime',
        ];
    }
}
