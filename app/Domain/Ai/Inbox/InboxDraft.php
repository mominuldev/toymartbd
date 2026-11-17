<?php

declare(strict_types=1);

namespace App\Domain\Ai\Inbox;

/** One suggested inbox reply: never sent, never stored as a message. */
final readonly class InboxDraft
{
    /** @param  list<string>  $basedOn */
    public function __construct(
        public string $text,
        public string $language,
        public array $basedOn,
        public string $model,
        public float $seconds,
        public bool $windowOpen,
        public string $replyState,
    ) {}
}
