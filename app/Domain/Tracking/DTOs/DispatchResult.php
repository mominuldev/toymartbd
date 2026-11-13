<?php

declare(strict_types=1);

namespace App\Domain\Tracking\DTOs;

use App\Domain\Tracking\Enums\DispatchStatus;

/**
 * Outcome of one send to one destination.
 *
 * $responseExcerpt is at most 2 KB and never contains a secret; $sentPayload is the
 * body as sent — user data already SHA-256 hashed, no api_secret / access_token.
 */
final readonly class DispatchResult
{
    /**
     * @param  array<string, mixed>  $sentPayload
     */
    public function __construct(
        public DispatchStatus $status,
        public ?int $httpStatus = null,
        public ?string $responseExcerpt = null,
        public array $sentPayload = [],
        public ?string $error = null,
    ) {}
}
