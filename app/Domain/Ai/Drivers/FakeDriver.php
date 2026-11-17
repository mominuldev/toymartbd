<?php

declare(strict_types=1);

namespace App\Domain\Ai\Drivers;

use App\Domain\Ai\Contracts\AiDriver;
use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Exceptions\AiException;

/**
 * Tests and CI: answers from a queue, records every prompt it was sent (so a test can prove
 * a phone number never reached a provider). Bound in place of all three drivers by
 * {@see \App\Domain\Ai\Services\AiDrivers::fake()}.
 */
final class FakeDriver implements AiDriver
{
    /** @var list<array<string, mixed>|string|AiException> */
    private array $queue = [];

    /** @var list<array{prompt: AiPrompt, model: string}> */
    public array $sent = [];

    /** @param  array<string, mixed>|string|AiException  ...$answers  JSON object, plain text, or an exception to throw */
    public function respond(array|string|AiException ...$answers): self
    {
        array_push($this->queue, ...$answers);

        return $this;
    }

    public function complete(AiPrompt $prompt, string $model, string $apiKey, int $maxTokens): AiResult
    {
        $this->sent[] = ['prompt' => $prompt, 'model' => $model];
        $next = array_shift($this->queue) ?? ($prompt->schema !== null ? [] : 'OK');
        if ($next instanceof AiException) {
            throw $next;
        }
        $text = is_array($next) ? (string) json_encode($next, JSON_UNESCAPED_UNICODE) : $next;

        return new AiResult($text, $prompt->schema !== null ? (is_array($next) ? $next : Json::object($text)) : null, 1000, 200, $model);
    }

    public function check(string $apiKey, string $model): void
    {
        if (str_starts_with($apiKey, 'bad-')) {
            throw new AiException(AiException::AUTH, 'Fake provider rejected the key.');
        }
    }
}
