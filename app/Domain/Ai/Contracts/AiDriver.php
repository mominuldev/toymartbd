<?php

declare(strict_types=1);

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Exceptions\AiException;

/**
 * One AI provider (rule 5: add a provider by writing a driver). Drivers are stateless; the key
 * is passed per call and never logged.
 */
interface AiDriver
{
    /** @throws AiException */
    public function complete(AiPrompt $prompt, string $model, string $apiKey, int $maxTokens): AiResult;

    /**
     * A small real call proving the key works.
     *
     * @throws AiException
     */
    public function check(string $apiKey, string $model): void;
}
