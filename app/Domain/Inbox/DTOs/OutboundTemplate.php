<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

/** An approved WhatsApp template to send: Meta's template name, language code and positional body parameters ({{1}} first). */
final readonly class OutboundTemplate
{
    /** @param  list<string>  $parameters */
    public function __construct(
        public string $name,
        public string $language,
        public array $parameters,
    ) {}
}
