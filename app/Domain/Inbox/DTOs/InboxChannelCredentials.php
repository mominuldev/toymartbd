<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

use App\Domain\Inbox\Enums\InboxChannel;

/**
 * One channel's part of a save on Admin → Integrations → Messaging. `driver` and `accountId`
 * (phone_number_id / page_id) replace what is stored; null `accountId` removes the saved
 * value, so env applies again. For each secret (by its InboxChannelSettings name): a value in
 * `secrets` replaces it, absence keeps it, and a name in `clear` removes it.
 */
final readonly class InboxChannelCredentials
{
    /**
     * @param  array<string, string>  $secrets  name → new value
     * @param  list<string>  $clear  names of saved secrets to remove
     * @param  array<string, string|bool|null>  $extra  name → new value (null = remove)
     */
    public function __construct(
        public InboxChannel $channel,
        public string $driver,
        public ?string $accountId,
        public array $secrets = [],
        public array $clear = [],
        public array $extra = [],
    ) {}
}
