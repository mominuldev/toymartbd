<?php

declare(strict_types=1);

namespace App\Domain\Staff\Services;

use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Staff two-factor sign-in with an authenticator app (RFC 6238 TOTP, 6 digits, 30 s), owner
 * decision 2026-11-14. SMS is deliberately not offered: it costs per message and a SIM swap
 * defeats it. Recovery codes are single-use and stored only as SHA-256 hashes.
 *
 * Who must use it is computed from live permissions — roles are editable — so a role edit
 * that grants a money permission makes its holders enrol at their next request.
 */
final readonly class TwoFactor
{
    public const int RECOVERY_CODES = 8;

    /** One step either side of now: tolerates a phone clock up to ~30 s out. */
    private const int WINDOW = 1;

    public function __construct(private Google2FA $google2fa) {}

    public function enforced(): bool
    {
        return config('auth.two_factor.enforce') === true;
    }

    /** Does this person have to set up two-factor before using the admin? */
    public function requiredFor(User $user): bool
    {
        return $this->enforced() && $this->seesMoney($user);
    }

    /** Owners, and anyone whose role grants a money permission (Permission::revealsMoney()). */
    public function seesMoney(User $user): bool
    {
        if ($user->hasRole(Role::SuperAdmin->value)) {
            return true;
        }

        $held = $user->getAllPermissions()->pluck('name')->all();
        foreach (Permission::cases() as $permission) {
            if ($permission->revealsMoney() && in_array($permission->value, $held, true)) {
                return true;
            }
        }

        return false;
    }

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUri(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('auth.two_factor.issuer'), $user->email, $secret);
    }

    /** The otpauth URI as a standalone SVG QR code (no external request, nothing cached). */
    public function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /**
     * The 30-second step a valid code belongs to, or null. A code at or before the last step
     * already used is refused, so an observed code cannot be replayed.
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $step = $this->google2fa->verifyKeyNewer($secret, $code, $lastUsedStep ?? 0, self::WINDOW);

        return is_int($step) ? $step : null;
    }

    /**
     * Fresh recovery codes, `xxxxx-xxxxx` lower-case. Returns the plain codes (shown once)
     * and their hashes (stored).
     *
     * @return array{plain: list<string>, hashes: list<string>}
     */
    public function newRecoveryCodes(): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $plain[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return ['plain' => $plain, 'hashes' => array_map(self::hash(...), $plain)];
    }

    /**
     * The stored hashes without the one matching `$code`, or null when it matches none.
     *
     * @param  list<string>  $hashes
     * @return list<string>|null
     */
    public function spendRecoveryCode(array $hashes, string $code): ?array
    {
        $hash = self::hash($code);
        foreach ($hashes as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($hashes[$index]);

                return array_values($hashes);
            }
        }

        return null;
    }

    private static function hash(string $code): string
    {
        return hash('sha256', Str::lower(trim($code)));
    }
}
