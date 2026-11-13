<?php

declare(strict_types=1);

use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Support\PreviewToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\CmsBlocks\CmsKit;

/*
 * Homepage builder API (Phase 7): draft editing, ordering, publish → immutable versions,
 * revert, preview tokens and the storefront revalidation on publish.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://shop.test/api/revalidate' => Http::response(['revalidated' => ['content:home']])]);
    config(['services.storefront.url' => 'https://shop.test', 'services.storefront.revalidate_secret' => 'secret']);
    $this->editor = CmsKit::actAsEditor();
});

/** @return list<int> */
function cmsDraftIds(): array
{
    return CmsBlock::query()->inPlacement(BlockPlacement::Home)->pluck('id')->map(intval(...))->all();
}

it('adds blocks at the end or at a position, and lists the draft in order', function (): void {
    $hero = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'hero'])->assertCreated()->json('id');
    $faq = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'faq'])->assertCreated()->json('id');
    $usp = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'usp_band', 'position' => 1, 'label' => 'Promise'])->assertCreated()
        ->assertJsonPath('position', 1)->assertJsonPath('label', 'Promise')->json('id');

    $draft = $this->getJson(CmsKit::ADMIN)->assertOk();

    expect(array_column($draft->json('blocks'), 'id'))->toBe([$hero, $usp, $faq])
        ->and(array_column($draft->json('blocks'), 'position'))->toBe([0, 1, 2])
        ->and($draft->json('live'))->toBeNull()
        ->and($draft->json('has_unpublished_changes'))->toBeTrue()
        ->and(array_column($draft->json('block_types'), 'type'))->toContain('hero', 'product_rail', 'faq', 'testimonials', 'blog_teaser');
});

it('reorders, duplicates below the original and deletes closing the gap', function (): void {
    $a = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'hero'])->json('id');
    $b = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'age_tiles'])->json('id');
    $c = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'faq'])->json('id');

    $this->putJson(CmsKit::ADMIN.'/order', ['ids' => [$c, $a, $b]])->assertOk();
    expect(cmsDraftIds())->toBe([$c, $a, $b]);

    // Not a permutation of the draft → 422, nothing changes.
    $this->putJson(CmsKit::ADMIN.'/order', ['ids' => [$a, $b]])->assertUnprocessable();
    $this->putJson(CmsKit::ADMIN.'/order', ['ids' => [$a, $b, $c, 99999]])->assertUnprocessable();
    expect(cmsDraftIds())->toBe([$c, $a, $b]);

    $copy = $this->postJson(CmsKit::ADMIN.'/blocks/'.$a.'/duplicate')->assertCreated();
    expect(cmsDraftIds())->toBe([$c, $a, $copy->json('id'), $b])
        ->and($copy->json('uuid'))->not->toBe(CmsBlock::query()->find($a)?->uuid)
        ->and($copy->json('content'))->toBe(CmsBlock::query()->find($a)?->content);

    $this->deleteJson(CmsKit::ADMIN.'/blocks/'.$a)->assertNoContent();
    expect(cmsDraftIds())->toBe([$c, $copy->json('id'), $b])
        ->and(CmsBlock::query()->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2]);
});

it('stores the schedule in UTC from Dhaka wall-clock time and checks the window', function (): void {
    $id = $this->postJson(CmsKit::ADMIN.'/blocks', [
        'type' => 'hero', 'starts_at' => '2026-06-15T00:00', 'ends_at' => '2026-06-22T23:59',
    ])->assertCreated()
        // Dhaka is UTC+6: 15 Jun 00:00 Dhaka = 14 Jun 18:00 UTC.
        ->assertJsonPath('starts_at', '2026-06-14T18:00:00Z')
        ->assertJsonPath('ends_at', '2026-06-22T17:59:00Z')
        ->json('id');

    // An explicit offset is honoured as given.
    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$id, ['starts_at' => '2026-06-15T00:00:00+00:00'])->assertOk()->assertJsonPath('starts_at', '2026-06-15T00:00:00Z');
    // A PATCH of only one bound is checked against the stored other bound.
    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$id, ['ends_at' => '2026-06-01T00:00'])->assertUnprocessable();
    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$id, ['starts_at' => null, 'ends_at' => null])->assertOk()->assertJsonPath('starts_at', null);
});

it('updates visibility per locale and device', function (): void {
    $id = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'faq'])->json('id');

    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$id, ['show_bn' => false, 'show_mobile' => false, 'is_enabled' => false])->assertOk()
        ->assertJsonPath('show_bn', false)->assertJsonPath('show_en', true)
        ->assertJsonPath('show_mobile', false)->assertJsonPath('show_desktop', true)->assertJsonPath('is_enabled', false);
});

it('publishes an immutable version, refuses an unchanged draft, and revalidates content:home', function (): void {
    Bus::fake([RevalidateStorefrontTags::class]);

    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'hero'])->assertCreated();
    $v1 = $this->postJson(CmsKit::ADMIN.'/publish', ['note' => 'Eid launch'])->assertCreated()
        ->assertJsonPath('version', 1)->assertJsonPath('block_count', 1)->assertJsonPath('note', 'Eid launch')
        ->assertJsonPath('is_live', true)->assertJsonPath('published_by.id', $this->editor->id);

    Bus::assertDispatched(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:home']);

    expect($this->getJson(CmsKit::ADMIN)->json('has_unpublished_changes'))->toBeFalse();
    $this->postJson(CmsKit::ADMIN.'/publish')->assertConflict();

    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'faq'])->assertCreated();
    expect($this->getJson(CmsKit::ADMIN)->json('has_unpublished_changes'))->toBeTrue();
    $this->postJson(CmsKit::ADMIN.'/publish')->assertCreated()->assertJsonPath('version', 2);

    $versions = $this->getJson(CmsKit::ADMIN.'/versions')->assertOk();
    expect(array_column($versions->json('data'), 'version'))->toBe([2, 1])
        ->and(array_column($versions->json('data'), 'is_live'))->toBe([true, false]);

    // Versions are append-only.
    $version = CmsLayoutVersion::query()->findOrFail($v1->json('id'));
    expect(fn () => $version->update(['note' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $version->delete())->toThrow(LogicException::class);
});

it('sends the signed revalidation for content:home to the storefront when publishing', function (): void {
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'hero'])->assertCreated();
    $this->postJson(CmsKit::ADMIN.'/publish')->assertCreated();

    Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://shop.test/api/revalidate'
        && $request['tags'] === ['content:home']
        && str_starts_with($request->header('X-Revalidate-Signature')[0] ?? '', 'sha256='));
});

it('keeps a published version unchanged while the draft is edited', function (): void {
    $id = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'faq'])->json('id');
    $this->postJson(CmsKit::ADMIN.'/publish')->assertCreated();

    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$id, ['content' => ['title' => 'Changed', 'items' => [['question' => 'Q', 'answer' => 'A']]]])->assertOk();

    $live = CmsLayoutVersion::latestFor(BlockPlacement::Home);
    expect($live?->blocks[0]['content']['title'])->toBe('Questions parents ask');
});

it('reverts to an earlier version as a new live version, or restores it to the draft only', function (): void {
    Bus::fake([RevalidateStorefrontTags::class]);

    $hero = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'hero'])->json('id');
    $v1 = $this->postJson(CmsKit::ADMIN.'/publish')->json('id');
    $this->deleteJson(CmsKit::ADMIN.'/blocks/'.$hero)->assertNoContent();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'faq'])->assertCreated();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'rich_text'])->assertCreated();
    $v2 = $this->postJson(CmsKit::ADMIN.'/publish')->json('id');

    $this->postJson(CmsKit::ADMIN.'/versions/'.$v1.'/revert')->assertCreated()
        ->assertJsonPath('version', 3)->assertJsonPath('restored_from_version', 1)->assertJsonPath('block_count', 1);

    $draft = $this->getJson(CmsKit::ADMIN)->assertOk();
    expect(array_column($draft->json('blocks'), 'type'))->toBe(['hero'])
        ->and($draft->json('has_unpublished_changes'))->toBeFalse()
        ->and(CmsLayoutVersion::query()->count())->toBe(3);
    Bus::assertDispatchedTimes(RevalidateStorefrontTags::class, 3);

    // Restore-to-draft: the draft changes, nothing goes live.
    $this->postJson(CmsKit::ADMIN.'/versions/'.$v2.'/revert', ['publish' => false])->assertOk()
        ->assertJsonPath('has_unpublished_changes', true);
    expect(array_column($this->getJson(CmsKit::ADMIN)->json('blocks'), 'type'))->toBe(['faq', 'rich_text'])
        ->and(CmsLayoutVersion::query()->count())->toBe(3);
});

it('mints a short-lived preview token for the storefront preview route', function (): void {
    CarbonImmutable::setTestNow('2026-11-13T10:00:00Z');

    $response = $this->postJson(CmsKit::ADMIN.'/preview-token', ['locale' => 'bn'])->assertOk()
        ->assertJsonPath('expires_at', '2026-11-13T10:10:00Z');

    $token = $response->json('token');
    expect(PreviewToken::verify($token, 'home_draft'))->toBe($this->editor->id)
        ->and(PreviewToken::verify($token, 'blog_post'))->toBeNull()
        ->and($response->json('url'))->toBe('https://shop.test/api/preview/home?'.http_build_query(['token' => $token, 'locale' => 'bn']));

    $this->postJson(CmsKit::ADMIN.'/preview-token', ['locale' => 'fr'])->assertUnprocessable();

    CarbonImmutable::setTestNow();
});
