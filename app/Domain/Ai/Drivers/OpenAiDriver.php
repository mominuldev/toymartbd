<?php

declare(strict_types=1);

namespace App\Domain\Ai\Drivers;

use App\Domain\Ai\Contracts\AiDriver;
use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Exceptions\AiException;
use Illuminate\Http\Client\Factory as Http;

/**
 * OpenAI, Responses API over HTTP (`POST /v1/responses`, Bearer key).
 *
 * VERIFIED against developers.openai.com (2026-11-17): the endpoint and auth; `input` as a list
 * of `{role, content}` with a `system` message; structured output as
 * `text.format = {type: json_schema, name, schema, strict}`; `max_output_tokens`; `status`.
 * NOT VERIFIED (the API reference page refused automated reads): the response path
 * `output[].content[]` with `type: output_text|refusal`, `usage.input_tokens|output_tokens`, and
 * `GET /v1/models/{model}` for the key check. Parsing below is defensive; confirm with a live
 * call before relying on spend figures for this provider.
 */
final readonly class OpenAiDriver implements AiDriver
{
    private const string BASE = 'https://api.openai.com/v1';

    public function __construct(private Http $http) {}

    public function complete(AiPrompt $prompt, string $model, string $apiKey, int $maxTokens): AiResult
    {
        $body = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => $prompt->system],
                ['role' => 'user', 'content' => $prompt->user],
            ],
            'max_output_tokens' => $maxTokens,
        ];
        if ($prompt->schema !== null) {
            $body['text'] = ['format' => ['type' => 'json_schema', 'name' => $prompt->schemaName, 'schema' => $prompt->schema, 'strict' => true]];
        }

        $json = HttpErrors::send('OpenAI', fn () => $this->http->withToken($apiKey)->acceptJson()->timeout(120)->post(self::BASE.'/responses', $body))->json();
        $data = is_array($json) ? $json : [];

        $text = is_string($data['output_text'] ?? null) ? $data['output_text'] : '';
        foreach ((array) ($data['output'] ?? []) as $item) {
            foreach ((array) (is_array($item) ? ($item['content'] ?? []) : []) as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? null) === 'refusal') {
                    throw new AiException(AiException::REFUSED, 'The model declined this request.');
                }
                if ($text === '' && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }
        if (($data['status'] ?? 'completed') !== 'completed' || $text === '') {
            throw new AiException(AiException::INVALID_OUTPUT, 'OpenAI did not return a complete answer.');
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new AiResult(
            $text,
            $prompt->schema !== null ? Json::object($text) : null,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            is_string($data['model'] ?? null) ? $data['model'] : $model,
        );
    }

    public function check(string $apiKey, string $model): void
    {
        HttpErrors::send('OpenAI', fn () => $this->http->withToken($apiKey)->acceptJson()->timeout(20)->get(self::BASE.'/models/'.rawurlencode($model)));
    }
}
