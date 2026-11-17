<?php

declare(strict_types=1);

namespace App\Domain\Ai\DTOs;

/** A provider's answer: the text, the decoded JSON when a schema was asked for, and token usage. */
final readonly class AiResult
{
    /** @param  array<string, mixed>|null  $json */
    public function __construct(
        public string $text,
        public ?array $json,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
    ) {}
}
