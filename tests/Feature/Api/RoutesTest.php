<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Feature\Api\Fixtures\WidgetResource;

it('registers the three v1 audience groups and the health endpoint', function (): void {
    $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

    foreach (['api/v1/storefront/', 'api/v1/admin/', 'api/v1/webhooks/'] as $prefix) {
        expect($uris->contains(fn (string $uri): bool => str_starts_with($uri, $prefix)))
            ->toBeTrue("No route registered under {$prefix}");
    }

    expect(Route::has('api.v1.storefront.ping'))->toBeTrue()
        ->and(Route::has('api.v1.admin.ping'))->toBeTrue()
        ->and(Route::has('api.v1.webhooks.ping'))->toBeTrue()
        ->and(Route::has('api.v1.health'))->toBeTrue();
});

it('throttles storefront and admin but never webhooks', function (): void {
    $middleware = fn (string $name): array => Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

    expect($middleware('api.v1.storefront.ping'))->toContain('throttle:storefront')
        ->and($middleware('api.v1.admin.ping'))->toContain('throttle:admin')
        ->and(collect($middleware('api.v1.webhooks.ping'))->filter(fn (string $m): bool => str_starts_with($m, 'throttle')))->toBeEmpty();
});

it('serves each audience ping', function (string $audience): void {
    $this->getJson("/api/v1/{$audience}/ping")->assertOk()->assertJsonPath('audience', $audience);
})->with(['storefront', 'admin', 'webhooks']);

it('returns a single ApiResource bare', function (): void {
    $response = (new WidgetResource(['id' => 1, 'name' => 'Lego']))->response(request());

    expect($response->getData(true))->toBe(['id' => 1, 'name' => 'Lego']);
});

it('returns a paginated ApiResource collection as { data, links, meta }', function (): void {
    $paginator = new Illuminate\Pagination\LengthAwarePaginator(
        [['id' => 1, 'name' => 'Lego'], ['id' => 2, 'name' => 'পুতুল']], 2, 15,
    );

    $body = WidgetResource::collection($paginator)->response(request())->getData(true);

    expect($body)->toHaveKeys(['data', 'links', 'meta'])
        ->and($body['data'][1])->toBe(['id' => 2, 'name' => 'পুতুল'])
        ->and($body['meta']['total'])->toBe(2);
});
