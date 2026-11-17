<?php

declare(strict_types=1);

namespace App\Domain\Ai\Drivers;

use App\Domain\Ai\Exceptions\AiException;

/** @internal Decodes a provider's structured answer, tolerating a ```json fence. */
final class Json
{
    /** @return array<string, mixed> */
    public static function object(string $text): array
    {
        $clean = trim($text);
        if (str_starts_with($clean, '```')) {
            $clean = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $clean);
        }
        $decoded = json_decode($clean, true);
        if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new AiException(AiException::INVALID_OUTPUT, 'The answer was not the expected JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
