<?php

declare(strict_types=1);

use App\Domain\Ai\Enums\AiProvider;
use App\Domain\Ai\Services\AiPricing;
use App\Domain\Ai\Services\FigureGuard;
use App\Domain\Ai\Services\Redactor;

it('removes phones, emails and long digit runs but keeps order numbers and prices', function (string $in, string $out): void {
    expect(Redactor::clean($in))->toBe($out);
})->with([
    'bd local' => ['Call 01711000222 now', 'Call [removed] now'],
    'bd spaced' => ['Phone: 017 1100 0222.', 'Phone: [removed].'],
    'e164' => ['+8801711000222 asked', '[removed] asked'],
    'dashed intl' => ['880-1711-000222', '[removed]'],
    'email' => ['mail rafi.a@example.com ok', 'mail [removed] ok'],
    'card' => ['card 4111 1111 1111 1111', 'card [removed]'],
    'order kept' => ['Order TMB-26-00126 for ৳6,260', 'Order TMB-26-00126 for ৳6,260'],
    'lakh kept' => ['Sales ৳2,48,600 from 212 orders', 'Sales ৳2,48,600 from 212 orders'],
    'bangla kept' => ['আমার অর্ডার কবে পাব?', 'আমার অর্ডার কবে পাব?'],
]);

it('flags figures that are not in the facts', function (): void {
    $facts = ['sales' => '248600.00', 'orders' => 212, 'change' => '14.2', 'stock' => ['left' => 12, 'rate' => '4.0']];

    expect(FigureGuard::unknown('Sales were ৳2,48,600 from 212 orders — 14% more. 12 left at 4 a day.', $facts))->toBe([])
        ->and(FigureGuard::unknown('Sales were ৳2,50,000 from 212 orders, up 14.2%.', $facts))->toBe(['2,50,000'])
        ->and(FigureGuard::unknown('Expect 3 things and a top 5 list.', []))->toBe([])
        ->and(FigureGuard::unknown('Margin is 37%.', $facts))->toBe(['37']);
});

it('estimates cost from list prices, the dearest model when unknown', function (): void {
    expect(AiPricing::cost(AiProvider::Anthropic, 'claude-opus-5-5', 1_000_000, 100_000))->toBe('6.000000')
        ->and(AiPricing::cost(AiProvider::Anthropic, 'claude-haiku-5-5', 1000, 1000))->toBe('0.000600')
        ->and(AiPricing::cost(AiProvider::OpenAi, 'no-such-model', 1_000_000, 0))->toBe('10.000000');
});
