<?php

declare(strict_types=1);

namespace App\Domain\Ai\Enums;

/**
 * The four AI features (Figma "AI for growth" 536:82212). Every one only suggests: a person
 * approves each change, price and message.
 */
enum AiFeature: string
{
    case Advisor = 'advisor';
    case ProductCopy = 'product_copy';
    case InboxReply = 'inbox_reply';
    case RestockNote = 'restock_note';

    /** Output ceiling per call — generous enough never to cut a draft mid-sentence. */
    public function maxTokens(): int
    {
        return match ($this) {
            self::Advisor => 8000,
            self::ProductCopy => 8000,
            self::InboxReply => 2000,
            self::RestockNote => 2000,
        };
    }
}
