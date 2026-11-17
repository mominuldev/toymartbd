<?php

declare(strict_types=1);

namespace App\Domain\Ai\Drivers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Domain\Ai\Contracts\AiDriver;
use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Exceptions\AiException;
use Closure;

/**
 * Anthropic Claude through the official PHP SDK (`anthropic-ai/sdk`), Messages API.
 *
 * - Structured answers use `outputConfig.format` with a JSON Schema; effort is set to `medium`
 *   explicitly (Claude Opus 5.5's default, stated so a model switch keeps it).
 * - Thinking is left to the model's default (adaptive; it cannot be disabled on Opus 5.5).
 * - The fixed instruction carries `cacheControl` so repeated calls read it from cache.
 * - A `refusal` stop is surfaced as {@see AiException::REFUSED}. Server-side refusal fallbacks
 *   are not enabled: their PHP binding is not documented yet.
 */
final class AnthropicDriver implements AiDriver
{
    /** @param  (Closure(string): Client)|null  $factory  test seam */
    public function __construct(private readonly ?Closure $factory = null) {}

    public function complete(AiPrompt $prompt, string $model, string $apiKey, int $maxTokens): AiResult
    {
        $outputConfig = ['effort' => 'medium'];
        if ($prompt->schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $prompt->schema];
        }

        $message = $this->guard(fn () => $this->client($apiKey)->messages->create(
            maxTokens: $maxTokens,
            messages: [['role' => 'user', 'content' => $prompt->user]],
            model: $model,
            outputConfig: $outputConfig,
            system: [['type' => 'text', 'text' => $prompt->system, 'cacheControl' => ['type' => 'ephemeral']]],
        ));

        if ($message->stopReason === 'refusal') {
            throw new AiException(AiException::REFUSED, 'The model declined this request.');
        }
        if ($message->stopReason === 'max_tokens') {
            throw new AiException(AiException::INVALID_OUTPUT, 'The answer was cut off.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new AiResult(
            $text,
            $prompt->schema !== null ? Json::object($text) : null,
            $message->usage->inputTokens,
            $message->usage->outputTokens,
            $message->model,
        );
    }

    public function check(string $apiKey, string $model): void
    {
        // The Models API costs nothing and proves both the key and the model id.
        $this->guard(fn () => $this->client($apiKey)->models->retrieve($model));
    }

    private function client(string $apiKey): Client
    {
        return $this->factory !== null ? ($this->factory)($apiKey) : new Client(apiKey: $apiKey, requestOptions: ['timeout' => 120.0, 'maxRetries' => 2]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function guard(Closure $call): mixed
    {
        try {
            return $call();
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new AiException(AiException::AUTH, 'Anthropic rejected the API key.');
        } catch (NotFoundException) {
            throw new AiException(AiException::PROVIDER, 'Anthropic does not know this model.');
        } catch (RateLimitException) {
            throw new AiException(AiException::RATE_LIMITED, 'Anthropic is rate limiting requests.');
        } catch (APIStatusException $e) {
            throw new AiException(AiException::PROVIDER, 'Anthropic answered with an error ('.$e->getCode().').');
        } catch (APIConnectionException) {
            throw new AiException(AiException::PROVIDER, 'Anthropic could not be reached.');
        } catch (APIException) {
            throw new AiException(AiException::PROVIDER, 'Anthropic request failed.');
        }
    }
}
