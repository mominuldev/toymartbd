<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One SMS sign-in code (P4-STO-11). Only keyed hashes are stored — never the phone or the
 * code ({@see \App\Domain\Customer\Services\OtpCodes}). Written only by the OTP Actions.
 *
 * @property int $id
 * @property string $phone_hash
 * @property string $code_hash
 * @property string $purpose
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CustomerOtp extends Model
{
    /** @var list<string> */
    protected $fillable = ['phone_hash', 'code_hash', 'purpose', 'attempts', 'expires_at', 'consumed_at', 'ip_hash'];

    /** @var list<string> */
    protected $hidden = ['code_hash', 'phone_hash', 'ip_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
