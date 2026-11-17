<?php

declare(strict_types=1);

namespace App\Domain\Ai\Drivers;

use App\Domain\Ai\Contracts\AiDriver;
use App\Domain\Ai\DTOs\AiPrompt;
use App\Domain\Ai\DTOs\AiResult;
use App\Domain\Ai\Exceptions\AiException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Google Gemini, `POST /v1beta/models/{model}:generateContent`, key in `x-goog-api-key`.
 *
 * VERIFIED against aistudio.google.com (2026-11-17): endpoint and header; the response's
 * `candidates[].content.parts[].text`, `finishReason`, `promptFeedback.blockReason`, and
 * `usageMetadata.promptTokenCount|candidatesTokenCount|thoughtsTokenCount`;
 * `generationConfig.maxOutputTokens|responseMimeType`, and that `responseJsonSchema` is the
 * current schema field. NOT VERIFIED: the request's `systemInstruction` / `contents` shape
 * (the long-standing one is used). Confirm with a live call.
 */
final readonly class GeminiDriver implements AiDriver
{
    private const string BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Finish reasons that mean the answer was blocked, not finished. */
    private const array BLOCKED = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'LANGUAGE', 'OTHER'];

    public function __construct(private Http $http) {}

    public function complete(AiPrompt $prompt, string $model, string $apiKey, int $maxTokens): AiResult
    {
        $config = ['maxOutputTokens' => $maxTokens];
        if ($prompt->schema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseJsonSchema'] = $prompt->schema;
        }
        $body = [
            'systemInstruction' => ['parts' => [['text' => $prompt->system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt->user]]]],
            'generationConfig' => $config,
        ];

        $json = HttpErrors::send('Gemini', fn () => $this->http->withHeaders(['x-goog-api-key' => $apiKey])->acceptJson()->timeout(120)
            ->post(self::BASE.rawurlencode($model).':generateContent', $body))->json();
        $data = is_array($json) ? $json : [];

        if (is_string($data['promptFeedback']['blockReason'] ?? null)) {
            throw new AiException(AiException::REFUSED, 'Gemini blocked this request.');
        }
        $candidate = is_array($data['candidates'][0] ?? null) ? $data['candidates'][0] : [];
        $finish = $candidate['finishReason'] ?? null;
        if (in_array($finish, self::BLOCKED, true)) {
            throw new AiException(AiException::REFUSED, 'Gemini declined to answer.');
        }
        if ($finish === 'MAX_TOKENS') {
            throw new AiException(AiException::INVALID_OUTPUT, 'The answer was cut off.');
        }
        $text = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && ($part['thought'] ?? false) !== true) {
                $text .= $part['text'];
            }
        }
        if ($text === '') {
            throw new AiException(AiException::INVALID_OUTPUT, 'Gemini returned no text.');
        }
        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];

        return new AiResult(
            $text,
            $prompt->schema !== null ? Json::object($text) : null,
            (int) ($usage['promptTokenCount'] ?? 0),
            (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            $model,
        );
    }

    /**
     * Checked on the cheapest model: free AI Studio keys often have no quota for the Pro-tier
     * models, and the check only needs to prove the key.
     */
    public const string CHECK_MODEL = 'gemini-3.5-flash-lite';

    public function check(string $apiKey, string $model): void
    {
        try {
            $this->complete(new AiPrompt('Reply with OK.', 'OK?'), self::CHECK_MODEL, $apiKey, 64);
        } catch (AiException $e) {
            // A short or empty answer still proves the key; only auth and transport failures matter.
            if ($e->reason === AiException::INVALID_OUTPUT || $e->reason === AiException::REFUSED) {
                return;
            }
            throw $e;
        }
    }
}
