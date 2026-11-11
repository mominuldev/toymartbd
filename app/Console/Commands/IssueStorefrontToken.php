<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mints the Next.js storefront's server token (P3-API-05, SYSTEM-ARCHITECTURE §5).
 *
 * The token is a Sanctum personal access token owned by a dedicated service
 * account that is inactive, roleless and has an unusable random password: it
 * can never sign in to the admin, and EnsureActiveStaffSession refuses every
 * bearer token on the admin API anyway. Only `storefront:read` is granted.
 *
 * The plain-text token is printed once; Sanctum stores only its SHA-256 hash.
 * It goes into the storefront's server-side env (never NEXT_PUBLIC_*, rule 28).
 */
#[Signature('toymart:storefront-token {--rotate : Revoke every existing storefront token first}')]
#[Description('Issue the storefront server API token (printed once).')]
final class IssueStorefrontToken extends Command
{
    public const string SERVICE_EMAIL = 'storefront@service.toymartbd.internal';

    public const string TOKEN_NAME = 'storefront-server';

    /** @var list<string> */
    public const array ABILITIES = ['storefront:read'];

    public function handle(): int
    {
        $account = User::query()->firstOrCreate(
            ['email' => self::SERVICE_EMAIL],
            [
                'name' => 'Storefront server (service account)',
                'password' => Hash::make(Str::random(64)),
                'is_active' => false,
            ],
        );

        if ($this->option('rotate') === true) {
            $revoked = $account->tokens()->where('name', self::TOKEN_NAME)->delete();
            $this->components->info("Revoked {$revoked} existing storefront token(s).");
        }

        $token = $account->createToken(self::TOKEN_NAME, self::ABILITIES);

        $this->components->info('Storefront token issued with abilities: '.implode(', ', self::ABILITIES));
        $this->components->warn('Copy it now; it is not stored and will not be shown again.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
