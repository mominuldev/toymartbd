<?php

declare(strict_types=1);

namespace Tests\Feature\Finance\Capital;

use App\Domain\Finance\Models\CapitalHolder;
use Illuminate\Testing\TestResponse;

/** Shared helpers for the owner-capital tests (P10). */
final class CapitalKit
{
    public const string URL = '/api/v1/admin/finance/capital';

    private static int $key = 0;

    public static function holder(string $name = 'Mominul Islam', bool $active = true): CapitalHolder
    {
        return CapitalHolder::query()->create(['name' => $name, 'name_bn' => 'মমিনুল ইসলাম', 'is_active' => $active]);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function body(CapitalHolder $holder, array $overrides = []): array
    {
        return [
            'kind' => 'contribution',
            'holder_id' => $holder->id,
            'amount' => '50000.00',
            'occurred_on' => now('Asia/Dhaka')->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'DBBL-7781',
            'note' => 'Eid stock',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public static function record(array $body, ?string $key = null, array $headers = []): TestResponse
    {
        return test()->postJson(self::URL.'/entries', $body, ['Idempotency-Key' => $key ?? self::nextKey(), ...$headers]);
    }

    /** @param  array<string, string>  $headers */
    public static function reverse(int $entryId, string $reason = 'Typed the wrong amount', ?string $key = null, array $headers = []): TestResponse
    {
        return test()->postJson(self::URL."/entries/{$entryId}/reverse", ['reason' => $reason], ['Idempotency-Key' => $key ?? self::nextKey(), ...$headers]);
    }

    public static function nextKey(): string
    {
        return 'capital-test-'.(++self::$key).'-'.bin2hex(random_bytes(4));
    }
}
