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

        return match (true) {
            $response->successful() => $response,
            in_array($response->status(), [401, 403], true) => throw new AiException(AiException::AUTH, $provider.' rejected the API key.'),
            $response->status() === 429 => throw new AiException(AiException::RATE_LIMITED, $provider.' is rate limiting requests.'),
            $response->status() === 404 => throw new AiException(AiException::PROVIDER, $provider.' does not know this model.'),
            default => throw new AiException(AiException::PROVIDER, $provider.' answered with an error ('.$response->status().').'),
        };
    }
}
