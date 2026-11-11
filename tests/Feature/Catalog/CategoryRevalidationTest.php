<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\ArchiveCategory;
use App\Domain\Catalog\Actions\CreateCategory;
use App\Domain\Catalog\Actions\MoveCategory;
use App\Domain\Catalog\Actions\UpdateCategory;
use App\Domain\Catalog\DTOs\CategoryData;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Listeners\RevalidateCategoryPages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
 * CategoryChanged → queued storefront revalidation (docs/api/revalidation.md).
 */

/** @return list<string> */
function pushedCategoryTags(): array
{
    $tags = [];
    Queue::assertPushed(RevalidateStorefrontTags::class, function (RevalidateStorefrontTags $job) use (&$tags): bool {
        $tags[] = $job->tags;

        return true;
    });

    return array_merge(...$tags);
}

it('wires CategoryChanged to the listener and holds it until commit', function (): void {
    Event::fake([CategoryChanged::class]);

    app(CreateCategory::class)->handle(new CategoryData(name: 'Puzzles'));

    Event::assertListening(CategoryChanged::class, RevalidateCategoryPages::class);
    Event::assertDispatched(CategoryChanged::class, fn (CategoryChanged $e): bool => $e->slug === 'puzzles');
    expect(new CategoryChanged(1, 'x'))->toBeInstanceOf(Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
});

it('queues the categories and category tags on the index queue after create', function (): void {
    Queue::fake();

    app(CreateCategory::class)->handle(new CategoryData(name: 'Puzzles'));

    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['categories', 'category:puzzles'] && $job->afterCommit === true);
});

it('sends the old slug and the descendants on a slug change', function (): void {
    $root = Category::factory()->create(['slug' => 'toys']);
    Category::factory()->childOf($root)->create(['slug' => 'puzzles']);
    Queue::fake();

    app(UpdateCategory::class)->handle($root, ['slug' => 'all-toys']);

    expect(pushedCategoryTags())->toBe(['categories', 'category:all-toys', 'category:toys', 'category:puzzles']);
});

it('sends only the category itself for a flag change, and nothing for a no-op', function (): void {
    $root = Category::factory()->create(['slug' => 'toys', 'show_in_menu' => true]);
    Category::factory()->childOf($root)->create();
    Queue::fake();

    app(UpdateCategory::class)->handle($root, ['show_in_menu' => true]);
    Queue::assertNothingPushed();

    app(UpdateCategory::class)->handle($root, ['show_in_menu' => false]);
    expect(pushedCategoryTags())->toBe(['categories', 'category:toys']);
});

it('sends the moved subtree on a move, and the category on archive', function (): void {
    $a = Category::factory()->create(['slug' => 'a']);
    $b = Category::factory()->create(['slug' => 'b']);
    Category::factory()->childOf($b)->create(['slug' => 'b-child']);
    Queue::fake();

    app(MoveCategory::class)->handle($b, $a->id, 0);
    expect(pushedCategoryTags())->toBe(['categories', 'category:b', 'category:b-child']);

    Queue::fake();
    app(ArchiveCategory::class)->handle(Category::query()->where('slug', 'b-child')->firstOrFail());
    expect(pushedCategoryTags())->toBe(['categories', 'category:b-child']);
});

it('sends nothing when the transaction rolls back', function (): void {
    Queue::fake();

    DB::beginTransaction();
    app(CreateCategory::class)->handle(new CategoryData(name: 'Puzzles'));
    DB::rollBack();

    Queue::assertNothingPushed();
});

it('keeps the longest allowed slug inside the 100-character tag limit', function (): void {
    expect(strlen('category:'.str_repeat('a', Category::SLUG_MAX)))->toBe(100)
        ->and(preg_match(RevalidateStorefrontTags::TAG_PATTERN, 'category:'.str_repeat('a', Category::SLUG_MAX)))->toBe(1);
});
