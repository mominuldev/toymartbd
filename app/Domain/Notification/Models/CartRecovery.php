<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\CartRecoverySkip;
use App\Domain\Notification\Enums\CartRecoveryStatus;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One abandoned cart and what recovery did about it (P7, docs/api/cart-recovery.md).
 * UNIQUE `cart_id`: a cart is reminded at most once. The restore link's token is stored
 * only as a SHA-256 hash; the message (cost, segments, status) is the notification log row
 * with `notifiable_type = cart_recovery`.
 *
 * @property int $id
 * @property int $cart_id
 * @property int|null $customer_id
 * @property string|null $phone_hash
 * @property CartRecoveryStatus $status
 * @property CartRecoverySkip|null $skip_reason
 * @property string $locale
 * @property Money $cart_value
 * @property int $item_count
 * @property Carbon $abandoned_at
 * @property string|null $restore_token_hash
 * @property Carbon|null $restore_expires_at
 * @property Carbon|null $restored_at
 * @property int|null $recovered_order_id
 * @property Carbon|null $recovered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CartRecovery extends Model
{
    /** `notification_logs.notifiable_type` for a recovery message. */
    public const string NOTIFIABLE = 'cart_recovery';

    /** The template key in TemplateCatalog. */
    public const string TEMPLATE = 'cart.recovery';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cart_id' => 'integer',
            'customer_id' => 'integer',
            'status' => CartRecoveryStatus::class,
            'skip_reason' => CartRecoverySkip::class,
            'cart_value' => MoneyCast::class,
            'item_count' => 'integer',
            'abandoned_at' => 'datetime',
            'restore_expires_at' => 'datetime',
            'restored_at' => 'datetime',
            'recovered_order_id' => 'integer',
            'recovered_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
