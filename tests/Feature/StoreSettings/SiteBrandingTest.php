<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Services\SiteBranding;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Orders\Support;
use Tests\Support\ImageFixtures;
use Tests\Support\RoleMatrix;

/*
 * Store settings › Site & branding (Figma B · 38 Store settings · Premium): the storefront's name,
 * tagline and description per locale, logos, the store icon its favicons are made from and the
 * share image. Shown on the storefront through GET /storefront/store-info (`site`).
 */

const SITE_URL = '/api/v1/admin/settings/site';

/** @return array<string, mixed> */
function siteBody(array $overrides = []): array
{
    return [
        'name' => 'Toy Mart BD',
        'name_bn' => 'টয় মার্ট বিডি',
        'tagline' => 'Toys & learning for every age',
        'tagline_bn' => 'সব বয়সের খেলনা ও শেখা',
        'description' => 'Educational toys, STEM kits and baby essentials, delivered across Bangladesh.',
        'description_bn' => 'শিক্ষামূলক খেলনা, STEM কিট ও শিশুর প্রয়োজনীয় জিনিস।',
        'logo_media_id' => null,
        'logo_dark_media_id' => null,
        'icon_media_id' => null,
        'social_image_media_id' => null,
        ...$overrides,
    ];
}

function siteImage(int $width, int $height, array $attributes = []): Media
{
    return Media::factory()->create(['width' => $width, 'height' => $height, ...$attributes]);
}

it('starts empty, offering the business name (else the brand) as the fallback', function (): void {
    Support::actingAs(Role::Manager);

    $this->getJson(SITE_URL)->assertOk()
        ->assertJsonPath('name', null)
        ->assertJsonPath('logo', null)
        ->assertJsonPath('icon', null)
        ->assertJsonPath('fallback_names', ['name' => 'Toy Mart BD', 'name_bn' => 'টয় মার্ট বিডি'])
        ->assertJsonPath('max_lengths.description', 160);

    $this->putJson('/api/v1/admin/settings/business', ['name_en' => 'Toy Mart BD Ltd', 'name_bn' => null])->assertOk();
    $this->getJson(SITE_URL)->assertOk()->assertJsonPath('fallback_names.name', 'Toy Mart BD Ltd');
});

it('round-trips the section with its images, and purges the storefront store-info cache', function (): void {
    Queue::fake();
    Support::actingAs(Role::Manager);
    $logo = siteImage(1200, 360);
    $dark = siteImage(800, 240);
    $icon = siteImage(1024, 1024);
    $social = siteImage(1200, 630);

    $this->putJson(SITE_URL, siteBody(['logo_media_id' => $logo->id, 'logo_dark_media_id' => $dark->id, 'icon_media_id' => $icon->id, 'social_image_media_id' => $social->id]))
        ->assertOk()
        ->assertJsonPath('name_bn', 'টয় মার্ট বিডি')
        ->assertJsonPath('tagline', 'Toys & learning for every age')
        ->assertJsonPath('logo.id', $logo->id)
        ->assertJsonPath('logo.url', $logo->url(480))
        ->assertJsonPath('logo.width', 1200)
        ->assertJsonPath('logo_dark.id', $dark->id)
        ->assertJsonPath('icon.id', $icon->id)
        ->assertJsonPath('social_image.filename', $social->filename);

    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['store-info']);
    expect(Setting::query()->find('store.icon_media_id')?->value)->toBe($icon->id)
        ->and(Setting::query()->find('store.name')?->updated_by)->not->toBeNull();

    $this->getJson(SITE_URL)->assertOk()->assertJsonPath('icon.width', 1024)->assertJsonPath('description_bn', 'শিক্ষামূলক খেলনা, STEM কিট ও শিশুর প্রয়োজনীয় জিনিস।');
});

it('does not purge the storefront when nothing changed', function (): void {
    Support::actingAs(Role::Manager);
    $this->putJson(SITE_URL, siteBody())->assertOk();

    Queue::fake();
    $this->putJson(SITE_URL, siteBody(['name' => '  Toy Mart BD  ']))->assertOk();
    Queue::assertNotPushed(RevalidateStorefrontTags::class);
});

it('clears a value sent as null or blank', function (): void {
    Support::actingAs(Role::Manager);
    $icon = siteImage(512, 512);
    $this->putJson(SITE_URL, siteBody(['icon_media_id' => $icon->id]))->assertOk();

    $this->putJson(SITE_URL, siteBody(['tagline' => '   ', 'icon_media_id' => null]))->assertOk()
        ->assertJsonPath('tagline', null)
        ->assertJsonPath('icon', null);
    expect(Setting::query()->find('store.tagline'))->toBeNull()->and(Setting::query()->find('store.icon_media_id'))->toBeNull();
});

it('refuses an image of the wrong kind, state, size or shape', function (string $field, Closure $media): void {
    Support::actingAs(Role::Manager);

    $this->putJson(SITE_URL, siteBody([$field => $media()->id]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', $field);
})->with([
    'a PDF as the logo' => ['logo_media_id', fn (): Media => Media::factory()->document()->create()],
    'a processing image' => ['logo_media_id', fn (): Media => siteImage(1200, 360, ['processing_status' => ImageProcessingStatus::Processing])],
    'a tiny logo' => ['logo_dark_media_id', fn (): Media => siteImage(100, 40)],
    'a landscape icon' => ['icon_media_id', fn (): Media => siteImage(1024, 768)],
    'a small icon' => ['icon_media_id', fn (): Media => siteImage(256, 256)],
    'a square share image' => ['social_image_media_id', fn (): Media => siteImage(1200, 1200)],
    'a narrow share image' => ['social_image_media_id', fn (): Media => siteImage(400, 210)],
]);

it('refuses a missing media id and over-long text', function (): void {
    Support::actingAs(Role::Manager);

    $this->putJson(SITE_URL, siteBody(['icon_media_id' => 999999]))->assertUnprocessable()->assertJsonPath('errors.0.field', 'icon_media_id');
    $this->putJson(SITE_URL, siteBody(['description_bn' => str_repeat('শ', 161)]))->assertUnprocessable()->assertJsonPath('errors.0.field', 'description_bn');
    $this->putJson(SITE_URL, siteBody(['name' => str_repeat('a', 61)]))->assertUnprocessable()->assertJsonPath('errors.0.field', 'name');
});

it('keeps a branding image from being deleted from the media library', function (): void {
    Support::actingAs(Role::Manager);
    $icon = siteImage(512, 512);
    $other = siteImage(512, 512);
    $this->putJson(SITE_URL, siteBody(['icon_media_id' => $icon->id]))->assertOk();
    $usages = app(MediaUsages::class);

    expect($usages->isUsed($icon->id))->toBeTrue()
        ->and($usages->for($icon->id)[0]->type)->toBe('site_branding')
        ->and($usages->for($icon->id)[0]->adminPath)->toBe('/admin/settings/site')
        ->and($usages->isUsed($other->id))->toBeFalse();
    $this->deleteJson("/api/v1/admin/media/{$icon->id}")->assertStatus(409);

    $this->putJson(SITE_URL, siteBody())->assertOk();
    expect(app(MediaUsages::class)->isUsed($icon->id))->toBeFalse();
});

describe('storefront', function (): void {
    beforeEach(function (): void {
        $this->token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    });

    it('serves the site identity in store-info, names falling back to the business name', function (): void {
        $this->withToken($this->token)->getJson('/api/v1/storefront/store-info')->assertOk()
            ->assertJsonPath('site.name_en', 'Toy Mart BD')
            ->assertJsonPath('site.tagline_en', null)
            ->assertJsonPath('site.logo', null)
            ->assertJsonPath('site.icons', [])
            ->assertJsonPath('site.social_image', null);

        Setting::put('invoice.company.name_en', 'Toy Mart BD Ltd', App\Models\Enums\SettingGroup::Store);
        $this->withToken($this->token)->getJson('/api/v1/storefront/store-info')->assertJsonPath('site.name_en', 'Toy Mart BD Ltd');
    });

    it('serves logos, the favicon PNG URLs by size and the share JPEG', function (): void {
        Support::actingAs(Role::Manager);
        $logo = siteImage(1200, 360);
        $icon = siteImage(1024, 1024);
        $social = siteImage(1200, 630);
        $this->putJson(SITE_URL, siteBody(['name' => 'ToyMart', 'logo_media_id' => $logo->id, 'icon_media_id' => $icon->id, 'social_image_media_id' => $social->id]))->assertOk();

        $this->withToken($this->token)->getJson('/api/v1/storefront/store-info')->assertOk()
            ->assertJsonPath('site.name_en', 'ToyMart')
            ->assertJsonPath('site.tagline_bn', 'সব বয়সের খেলনা ও শেখা')
            ->assertJsonPath('site.logo.url', $logo->url(480))
            ->assertJsonPath('site.logo.width', 1200)
            ->assertJsonPath('site.logo_dark', null)
            ->assertJsonPath('site.icons.0', ['size' => 32, 'url' => url("/site-assets/{$icon->id}/icon-32.png")])
            ->assertJsonPath('site.icons.1', ['size' => 180, 'url' => url("/site-assets/{$icon->id}/icon-180.png")])
            ->assertJsonCount(4, 'site.icons')
            ->assertJsonPath('site.social_image', ['url' => url("/site-assets/{$social->id}/share.jpg"), 'width' => 1200, 'height' => 630]);
    });
});

describe('site assets', function (): void {
    it('makes the favicon PNG and share JPEG once, from the current images only', function (): void {
        Storage::fake(MediaUrls::disk());
        Storage::fake(MediaUrls::originalsDisk());
        $icon = siteImage(600, 600);
        $social = siteImage(1200, 630);
        Storage::disk(MediaUrls::originalsDisk())->put($icon->originalPath(), ImageFixtures::bytes('webp', 600, 600));
        Storage::disk(MediaUrls::originalsDisk())->put($social->originalPath(), ImageFixtures::bytes('webp', 1200, 630));
        Setting::put(SiteBranding::key('icon_media_id'), $icon->id, App\Models\Enums\SettingGroup::Store);
        Setting::put(SiteBranding::key('social_image_media_id'), $social->id, App\Models\Enums\SettingGroup::Store);

        $this->get("/site-assets/{$icon->id}/icon-180.png")->assertRedirect()->assertHeader('Cache-Control', 'max-age=300, public');
        $png = Storage::disk(MediaUrls::disk())->get("{$icon->base_path}/site-icon-180.png");
        expect(substr((string) $png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")->and(getimagesizefromstring((string) $png)[0])->toBe(180);

        $this->get("/site-assets/{$social->id}/share.jpg")->assertRedirect();
        $jpeg = (string) Storage::disk(MediaUrls::disk())->get("{$social->base_path}/site-share.jpg");
        expect(getimagesizefromstring($jpeg))->toMatchArray([0 => 1200, 1 => 630, 'mime' => 'image/jpeg']);

        // Not the current icon, not a known size, the icon id asking for the share image: 404.
        $this->get('/site-assets/'.siteImage(600, 600)->id.'/icon-32.png')->assertNotFound();
        $this->get("/site-assets/{$icon->id}/icon-64.png")->assertNotFound();
        $this->get("/site-assets/{$icon->id}/share.jpg")->assertNotFound();
    });
});

describe('role matrix', function (): void {
    it('guards GET /settings/site with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(SITE_URL));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('guards PUT /settings/site with settings.update', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(SITE_URL, siteBody()));
    })->with(RoleMatrix::dataset(Permission::SettingsUpdate));

    it('refuses a guest with 401', function (): void {
        $this->getJson(SITE_URL)->assertUnauthorized();
    });
});
