<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Exceptions\MetaConnectionFailed;
use App\Domain\Inbox\Services\MetaPageLogin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Continue with Facebook", step 2: Facebook sent the owner back with `code` and `state`. The
 * state must be the one this staff member started (single use). The code becomes a long-lived
 * user token and the list of Pages they chose; the Page tokens stay on the server — encrypted in
 * the cache for a few minutes under a random `session` — and only ids and names go to the browser.
 */
final readonly class FinishMessengerLogin
{
    public const string SESSION_KEY = 'inbox:fb-login:session:';

    public function __construct(private MetaPageLogin $login) {}

    /** @return array{session: string, pages: list<array{id: string, name: string, can_message: bool}>} */
    public function handle(string $code, string $state, int $userId): array
    {
        $owner = Cache::pull(StartMessengerLogin::STATE_KEY.$state);
        if ($owner !== $userId) {
            throw new MetaConnectionFailed('This Facebook sign-in has expired or was started by someone else. Press Continue with Facebook again.', 'state_mismatch');
        }

        $pages = $this->login->pages($this->login->exchangeCode($code));
        if ($pages === []) {
            throw new MetaConnectionFailed('No Page was shared. Press Continue with Facebook again and tick the shop’s Page.', 'no_pages');
        }

        $session = Str::random(48);
        Cache::put(self::SESSION_KEY.$session, encrypt(['user' => $userId, 'pages' => $pages]), now()->addMinutes((int) config('inbox.oauth_ttl_minutes', 10)));

        return [
            'session' => $session,
            'pages' => array_map(static fn (array $p): array => ['id' => $p['id'], 'name' => $p['name'], 'can_message' => $p['can_message']], $pages),
        ];
    }
}
