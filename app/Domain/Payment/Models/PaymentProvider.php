<?php

declare(strict_types=1);

namespace App\Domain\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An online payment driver's switchboard row (`payment_gateways`, P4-PAY-02). Mirrors
 * `courier_providers.verified` (rule 33a): production never takes an online payment
 * through a provider that is not `verified`. Credentials are encrypted at rest and hidden
 * from serialisation (rule 20).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property bool $verified
 * @property bool $sandbox
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $verified_at
 * @property int $position
 */
final class PaymentProvider extends Model
{
    protected $table = 'payment_gateways';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active', 'verified', 'sandbox', 'credentials', 'settings', 'verified_at', 'position'];

    /** @var list<string> */
    protected $hidden = ['credentials'];

    public static function forCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'verified' => 'boolean',
            'sandbox' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'verified_at' => 'datetime',
            'position' => 'integer',
        ];
    }
}
