<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Enums;

/** Where a reel video's detected length stands (video_lengths.status). */
enum VideoLengthStatus: string
{
    /** Queued, not looked up yet. */
    case Pending = 'pending';
    /** Read from the provider or the file. */
    case Found = 'found';
    /** The provider has no such public video, or the file has no readable length. */
    case Unavailable = 'unavailable';
    /** The lookup failed after its retries (network, provider error); the daily sweep tries again. */
    case Failed = 'failed';
    /** A YouTube link while YOUTUBE_API_KEY is not set; looked up once the key is added. */
    case NoKey = 'no_key';
    /** Facebook / Instagram: no official API gives a video's length — the editor types it. */
    case Unsupported = 'unsupported';

    /** Worth another lookup by the sweep. */
    public function retryable(): bool
    {
        return in_array($this, [self::Pending, self::Failed, self::NoKey], true);
    }
}
