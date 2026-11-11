<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ArchiveBrand;
use App\Domain\Catalog\Actions\CreateBrand;
use App\Domain\Catalog\Actions\UpdateBrand;
use App\Domain\Catalog\DTOs\BrandData;
use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Listeners\RevalidateBrandPages;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Storefront cache revalidation, sender side (docs/api/revalidation.md).
 */

const REVALIDATE_SECRET = 'test-revalidate-secret';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'services.storefront.url' => 'https://shop.test/',
        'services.storefront.revalidate_secret' => REVALIDATE_SECRET,
    ]);
});

function fakeStorefront(int $status = 200): void
{
    Http::fake(['https://shop.test/api/revalidate' => Http::response(['revalidated' => []], $status)]);
}

/** @return list<string> */
function sentTags(Request $request): array
{
    return json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR)['tags'];
}

it('POSTs a signed request with the brand tags after create', function (): void {
    fakeStorefront();

    app(CreateBrand::class)->handle(new BrandData(name: 'LEGO', nameBn: 'লেগো'));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-Revalidate-Timestamp')[0] ?? '';
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), REVALIDATE_SECRET);

        return $request->method() === 'POST'
            && $request->url() === 'https://shop.test/api/revalidate'
            && $request->hasHeader('Content-Type', 'application/json')
            && ctype_digit($timestamp)
            && abs((int) $timestamp - time()) <= 5
            && hash_equals($expected, $request->header('X-Revalidate-Signature')[0] ?? '')
            && $request->body() === '{"tags":["brands","brand:lego"]}'
            && sentTags($request) === ['brands', 'brand:lego'];
    });
});

it('includes the old slug when the slug changes', function (): void {
    fakeStorefront();
    $brand = Brand::factory()->create(['slug' => 'lego']);

    app(UpdateBrand::class)->handle($brand, ['slug' => 'lego-group']);

    Http::assertSent(fn (Request $request): bool => sentTags($request) === ['brands', 'brand:lego-group', 'brand:lego']);
});

it('revalidates on archive, and not at all for a no-op update or re-archive', function (): void {
    fakeStorefront();
    $brand = Brand::factory()->create(['slug' => 'barbie', 'name' => 'Barbie']);

    app(UpdateBrand::class)->handle($brand, ['name' => 'Barbie']);
    Http::assertNothingSent();

    app(ArchiveBrand::class)->handle($brand);
    app(ArchiveBrand::class)->handle($brand);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => sentTags($request) === ['brands', 'brand:barbie']);
});

it('waits for the outer transaction to commit', function (): void {
    fakeStorefront();

    DB::beginTransaction();
    app(CreateBrand::class)->handle(new BrandData(name: 'Hot Wheels'));
    Http::assertNothingSent();
    DB::commit();

    Http::assertSentCount(1);
});

it('sends nothing when the transaction rolls back', function (): void {
    fakeStorefront();

    DB::beginTransaction();
    app(CreateBrand::class)->handle(new BrandData(name: 'Hot Wheels'));
    DB::rollBack();

    expect(Brand::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('queues the job on the index queue after commit', function (): void {
    Queue::fake();

    app(CreateBrand::class)->handle(new BrandData(name: 'Funskool'));

    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['brands', 'brand:funskool'] && $job->afterCommit === true);
});

it('wires BrandChanged to the listener and dispatches it after commit', function (): void {
    Event::fake([BrandChanged::class]);

    app(CreateBrand::class)->handle(new BrandData(name: 'Play-Doh'));

    Event::assertListening(BrandChanged::class, RevalidateBrandPages::class);
    Event::assertDispatched(BrandChanged::class, fn (BrandChanged $e): bool => $e->slug === 'play-doh' && $e->previousSlug === null);
    expect(new BrandChanged(1, 'x'))->toBeInstanceOf(Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
});

it('skips silently when the storefront URL or secret is empty', function (string $key): void {
    config(["services.storefront.{$key}" => '']);
    fakeStorefront();

    app(CreateBrand::class)->handle(new BrandData(name: 'LEGO'));

    Http::assertNothingSent();
})->with(['url', 'revalidate_secret']);

it('does not retry a 4xx', function (): void {
    fakeStorefront(401);

    $job = (new RevalidateStorefrontTags(['brands']))->withFakeQueueInteractions();
    $job->handle();

    Http::assertSentCount(1);
    $job->assertFailed();
    $job->assertNotReleased();
});

it('throws on a 5xx or a connection error so the queue retries with backoff', function (): void {
    fakeStorefront(503);

    $job = new RevalidateStorefrontTags(['brands']);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60, 300]);

    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('refused'));
    expect(fn () => (new RevalidateStorefrontTags(['brands']))->handle())->toThrow(RuntimeException::class);
});

it('drops tags outside the contract vocabulary', function (): void {
    fakeStorefront();

    (new RevalidateStorefrontTags(['brands', 'Brand:LEGO', 'brand:'.str_repeat('a', 100)]))->handle();

    Http::assertSent(fn (Request $request): bool => sentTags($request) === ['brands']);
});

it('keeps the longest allowed slug inside the 100-character tag limit', function (): void {
    fakeStorefront();

    app(CreateBrand::class)->handle(new BrandData(name: str_repeat('Long brand name ', 7)));
    $slug = Brand::query()->value('slug');

    expect(strlen('brand:'.str_repeat('a', Brand::SLUG_MAX)))->toBe(100)
        ->and(strlen((string) $slug))->toBeLessThanOrEqual(Brand::SLUG_BASE_MAX);
    Http::assertSent(fn (Request $request): bool => sentTags($request) === ['brands', 'brand:'.$slug]);
});
