<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Enums\AiProvider;

/** Estimated USD cost of one call from the list prices in {@see AiProvider::models()}; an unknown model counts at the provider's dearest. */
final class AiPricing
{
    public static function cost(AiProvider $provider, string $model, int $inputTokens, int $outputTokens): string
    {
        $models = $provider->models();
        [, $in, $out] = $models[$model] ?? array_reduce($models, static fn (?array $max, array $m): array => $max === null || bccomp($m[2], $max[2], 2) > 0 ? $m : $max);

        return bcadd(
            bcdiv(bcmul((string) $inputTokens, $in, 6), '1000000', 6),
            bcdiv(bcmul((string) $outputTokens, $out, 6), '1000000', 6),
            6,
        );
    }
}
