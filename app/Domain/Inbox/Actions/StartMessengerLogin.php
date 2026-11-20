<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Exceptions\MetaConnectionFailed;
use App\Domain\Inbox\Services\MetaPageLogin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Continue with Facebook", step 1: a one-time `state` bound to the signed-in staff member (so a
 * code arriving on someone else's session is refused — CSRF for the login redirect), and the
 * Facebook dialog URL the admin navigates to. Nothing is written to the database.
 */
final readonly class StartMessengerLogin
{
    public const string STATE_KEY = 'inbox:fb-login:state:';

    public function __construct(private MetaPageLogin $login) {}

    /** @return array{url: string, redirect_uri: string} */
    public function handle(int $userId): array
    {
        if (! $this->login->configured()) {
            throw new MetaConnectionFailed('Add the Meta app first: its App ID and App secret.', 'not_configured');
        }

        $state = Str::random(48);
        Cache::put(self::STATE_KEY.$state, $userId, now()->addMinutes((int) config('inbox.oauth_ttl_minutes', 10)));

        return ['url' => $this->login->dialogUrl($state), 'redirect_uri' => MetaPageLogin::redirectUri()];
    }
}
