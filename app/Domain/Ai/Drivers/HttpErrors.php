<?php

declare(strict_types=1);

namespace App\Domain\Ai\Drivers;

use App\Domain\Ai\Exceptions\AiException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/** @internal Maps an HTTP provider's status to an {@see AiException}; never includes the body (it may echo input). */
final class HttpErrors
{
    /** @param  Closure(): Response  $call */
    public static function send(string $provider, Closure $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new AiException(AiException::PROVIDER, $provider.' could not be reached.');
        }

        // Gemini answers a bad key with 400 INVALID_ARGUMENT / reason API_KEY_INVALID, not 401.
        // A 429 that says the plan's quota is used up (Gemini "exceeded your current quota" — a free
        // key gets it on the first call to a Pro model; OpenAI "insufficient_quota") is not a rate limit.
        $noQuota = $response->status() === 429 && (str_contains($response->body(), 'exceeded your current quota') || str_contains($response->body(), 'insufficient_quota'));
        $badKey = $response->status() === 400 && (str_contains($response->body(), 'API_KEY_INVALID') || str_contains($response->body(), 'API key not valid'));

        return match (true) {
            $response->successful() => $response,
            $badKey, in_array($response->status(), [401, 403], true) => throw new AiException(AiException::AUTH, $provider.' rejected the API key.'),
            $noQuota => throw new AiException(AiException::NO_QUOTA, $provider.' has no quota for this model on this key’s plan.'),
            $response->status() === 429 => throw new AiException(AiException::RATE_LIMITED, $provider.' is rate limiting requests.'),
            $response->status() === 404 => throw new AiException(AiException::PROVIDER, $provider.' does not know this model.'),
            default => throw new AiException(AiException::PROVIDER, $provider.' answered with an error ('.$response->status().').'),
        };
    }
}
