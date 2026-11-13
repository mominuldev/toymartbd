<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Content\Blocks\Models\CmsBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\CmsBlocks\CmsKit;

/*
 * CMS block images are media usages (P7 follow-up): saving a draft block registers its hero /
 * promo images, publishing registers the live set, removing them unregisters — so the Media
 * Library shows "where used" and refuses to delete an image a block still shows.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    CmsKit::actAsEditor();
});

/** @return list<array{type: string, title: string}> */
function blockUsages(int $mediaId): array
{
    return array_map(static fn ($u): array => ['type' => $u->type, 'title' => $u->title], app(MediaUsages::class)->for($mediaId));
}

it('registers a draft block image on save and the live one on publish', function (): void {
    $image = Media::factory()->create();

    $id = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'promo_split', 'label' => 'Eid promo', 'content' => ['headline' => 'Eid', 'media_id' => $image->id]])
        ->assertCreated()->json('id');

    expect(blockUsages($image->id))->toBe([['type' => 'cms_block_draft', 'title' => 'Eid promo']])
        ->and(app(MediaUsages::class)->isUsed($image->id))->toBeTrue();

    $this->postJson(CmsKit::ADMIN.'/publish')->assertSuccessful();

    expect(array_column(blockUsages($image->id), 'type'))->toBe(['cms_block_live', 'cms_block_draft'])
        ->and(app(MediaUsages::class)->for($image->id)[0]->adminPath)->toBe('/admin/store/home')
        ->and(app(MediaUsages::class)->for($image->id)[0]->subjectId)->toBe($id);
});

it('registers every hero slide image', function (): void {
    [$a, $b] = [Media::factory()->create(), Media::factory()->create()];

    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'hero', 'content' => ['slides' => [['headline' => 'One', 'media_id' => $a->id], ['headline' => 'Two', 'media_id' => $b->id]]]])
        ->assertCreated();

    expect(blockUsages($a->id))->toHaveCount(1)->and(blockUsages($b->id))->toHaveCount(1);
});

it('unregisters when the image is replaced, the block deleted and the deletion published', function (): void {
    [$old, $new] = [Media::factory()->create(), Media::factory()->create()];
    $id = $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'promo_split', 'content' => ['headline' => 'x', 'media_id' => $old->id]])->json('id');
    $this->postJson(CmsKit::ADMIN.'/publish')->assertSuccessful();

    $this->patchJson(CmsKit::ADMIN.'/blocks/'.$id, ['content' => ['headline' => 'x', 'media_id' => $new->id]])->assertSuccessful();
    // The old image is still live until the change is published.
    expect(array_column(blockUsages($old->id), 'type'))->toBe(['cms_block_live'])
        ->and(array_column(blockUsages($new->id), 'type'))->toBe(['cms_block_draft']);

    $this->deleteJson(CmsKit::ADMIN.'/blocks/'.$id)->assertSuccessful();
    expect(blockUsages($new->id))->toBe([]);

    $this->postJson(CmsKit::ADMIN.'/publish')->assertSuccessful();
    expect(blockUsages($old->id))->toBe([])
        ->and(app(MediaUsages::class)->isUsed($old->id))->toBeFalse()
        ->and(DB::table('cms_block_media')->count())->toBe(0);
});

it('re-registers a reverted version and skips an image deleted since', function (): void {
    [$kept, $gone] = [Media::factory()->create(), Media::factory()->create()];
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'hero', 'content' => ['slides' => [['headline' => 'A', 'media_id' => $kept->id], ['headline' => 'B', 'media_id' => $gone->id]]]]);
    $this->postJson(CmsKit::ADMIN.'/publish')->assertSuccessful();
    $v1 = DB::table('cms_layout_versions')->max('id');

    CmsBlock::query()->get()->each(fn (CmsBlock $b) => $this->deleteJson(CmsKit::ADMIN.'/blocks/'.$b->id)->assertSuccessful());
    $this->postJson(CmsKit::ADMIN.'/publish')->assertSuccessful();
    $gone->delete(); // nothing references it any more

    $this->postJson(CmsKit::ADMIN.'/versions/'.$v1.'/revert')->assertSuccessful();

    expect(array_column(blockUsages($kept->id), 'type'))->toBe(['cms_block_live', 'cms_block_draft'])
        ->and(DB::table('cms_block_media')->where('media_id', $gone->id)->exists())->toBeFalse();
});

it('makes the library refuse to delete an image a block uses', function (): void {
    $image = Media::factory()->create();
    $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'promo_split', 'content' => ['headline' => 'x', 'media_id' => $image->id]])->assertCreated();

    auth()->forgetGuards();
    $this->actingAs(App\Models\User::factory()->withRole(App\Models\Enums\Role::SuperAdmin)->create()->refresh(), 'web');
    $this->deleteJson('/api/v1/admin/media/'.$image->id)->assertStatus(409);
    expect(Media::query()->whereKey($image->id)->exists())->toBeTrue();
});
