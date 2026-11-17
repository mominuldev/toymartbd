<?php

declare(strict_types=1);

namespace App\Domain\Ai\DTOs;

/**
 * One request to a provider: a fixed instruction, the user turn (already redacted by
 * {@see \App\Domain\Ai\Services\AiClient}), and optionally a JSON Schema the answer must match.
 * Feature code builds it; drivers translate it to their own API.
 */
final readonly class AiPrompt
{
    /** @param  array<string, mixed>|null  $schema  JSON Schema (object, additionalProperties false) for structured output */
    public function __construct(
        public string $system,
        public string $user,
        public ?array $schema = null,
        public string $schemaName = 'answer',
    ) {}

    public function withUser(string $user): self
    {
        return new self($this->system, $user, $this->schema, $this->schemaName);
    }
}
