<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ArchiveProduct;
use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Listeners\RevalidateProductPages;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CatalogFixtures;

/*
 * ProductChanged → queued storefront revalidation (docs/api/revalidation.md).
 */

it('queues products + product:{slug} on the index queue after commit', function (): void {
    Queue::fake();

    app(CreateProduct::class)->handle(CatalogFixtures::simple(['name' => 'Magnetic Tiles']));

    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['products', 'product:magnetic-tiles'] && $job->afterCommit === true);
});

it('includes the old slug after a slug change, and revalidates on a price change and archive', function (): void {
    Queue::fake();
    $data = CatalogFixtures::simple(['name' => 'Magnetic Tiles']);
    $product = app(CreateProduct::class)->handle($data);

    app(UpdateProduct::class)->handle($product, new ProductData(...[...get_object_vars($data), 'slug' => 'magnetic-tiles-60']));
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['products', 'product:magnetic-tiles-60', 'product:magnetic-tiles']);

    app(UpdateProduct::class)->handle($product, new ProductData(...[...get_object_vars($data), 'slug' => 'magnetic-tiles-60', 'variants' => [new VariantData(price: Money::of('999'))]]));
    app(ArchiveProduct::class)->handle($product);

    Queue::assertPushed(RevalidateStorefrontTags::class, 4);
});

it('sends nothing when the transaction rolls back', function (): void {
    Queue::fake();

    DB::beginTransaction();
    app(CreateProduct::class)->handle(CatalogFixtures::simple());
    DB::rollBack();

    Queue::assertNothingPushed();
});

it('wires ProductChanged to the listener', function (): void {
    Event::fake([ProductChanged::class]);

    Event::assertListening(ProductChanged::class, RevalidateProductPages::class);
    expect(new ProductChanged(1, 'x'))->toBeInstanceOf(Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
});
