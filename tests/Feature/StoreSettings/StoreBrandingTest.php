<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Invoicing\Jobs\RenderStoreFavicons;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * Store settings › Site & branding (Figma B · 38 Premium 390:69035). The logo is the invoice
 * logo's row; the icon and share image have shape rules; a new icon queues the favicon render.
 */

const BRANDING_URL = '/api/v1/admin/settings/branding';
const BRANDING_INVOICE_URL = '/api/v1/admin/settings/invoice';
const BRANDING_STORE_INFO_URL = '/api/v1/storefront/store-info';

beforeEach(function (): void {
    Queue::fake();
    config(['services.storefront.url' => 'https://toymartbd.com']);
});

function brandingImage(int $width, int $height, array $overrides = []): Media
{
    return Media::factory()->create(['width' => $width, 'height' => $height, ...$overrides]);
}

/** A ready image media row with a real WebP master on the originals disk. */
function brandingIconWithMaster(int $width, int $height): Media
{
    $media = brandingImage($width, $height);
    $gd = imagecreatetruecolor($width, $height);
    imagefill($gd, 0, 0, (int) imagecolorallocate($gd, 79, 70, 229));
    ob_start();
    imagewebp($gd);
    $bytes = (string) ob_get_clean();
    Storage::disk('local')->put($media->originalPath(), $bytes);

    return $media;
}

/** @return array<string, string|int|null> */
function brandingBody(array $overrides = []): array
{
    return [
        'site_name_en' => 'Toy Mart BD',
        'site_name_bn' => 'টয় মার্ট বিডি',
        'tagline_en' => 'Toys that teach',
        'tagline_bn' => 'খেলনায় শেখা',
        'description_en' => 'Educational toys delivered across Bangladesh, cash on delivery.',
        'description_bn' => 'সারা বাংলাদেশে শিক্ষামূলক খেলনা, ক্যাশ অন ডেলিভারি।',
        'logo_media_id' => null,
        'logo_dark_media_id' => null,
        'icon_media_id' => null,
        'share_image_media_id' => null,
        ...$overrides,
    ];
}

it('starts empty, with the business name as the site-name fallback', function (): void {
    Support::actingAs(Role::Manager);

    $this->getJson(BRANDING_URL)->assertOk()
        ->assertJsonPath('values.site_name_en', null)
        ->assertJsonPath('values.logo_media_id', null)
        ->assertJsonPath('media.logo', null)
        ->assertJsonPath('media.share_image', null)
        ->assertJsonPath('fallbacks', ['site_name_en' => 'Toy Mart BD', 'site_name_bn' => 'টয় মার্ট বিডি'])
        ->assertJsonPath('storefront_host', 'toymartbd.com')
        ->assertJsonPath('limits', ['site_name' => 60, 'tagline' => 80, 'description' => 160]);

    Setting::put(CompanyDetails::PREFIX.'name_en', 'Toy Mart Bangladesh', SettingGroup::Store);
    $this->getJson(BRANDING_URL)->assertOk()->assertJsonPath('fallbacks.site_name_en', 'Toy Mart Bangladesh');
});

it('round-trips the section, Bangla included, with each image described', function (): void {
    Support::actingAs(Role::Manager);
    $logo = brandingImage(800, 240, ['filename' => 'logo.png']);
    $dark = brandingImage(800, 240);
    $icon = brandingImage(1000, 1005);  // within 1%
    $share = brandingImage(1200, 630);

    $body = brandingBody(['logo_media_id' => $logo->id, 'logo_dark_media_id' => $dark->id, 'icon_media_id' => $icon->id, 'share_image_media_id' => $share->id]);
    $this->putJson(BRANDING_URL, $body)->assertOk()
        ->assertJsonPath('values.site_name_bn', 'টয় মার্ট বিডি')
        ->assertJsonPath('values.description_bn', 'সারা বাংলাদেশে শিক্ষামূলক খেলনা, ক্যাশ অন ডেলিভারি।')
        ->assertJsonPath('values.icon_media_id', $icon->id)
        ->assertJsonPath('media.logo.id', $logo->id)
        ->assertJsonPath('media.logo.filename', 'logo.png')
        ->assertJsonPath('media.logo.width', 800)
        ->assertJsonPath('media.logo.height', 240)
        ->assertJsonPath('media.logo.url', $logo->url(320))
        ->assertJsonPath('media.share_image.id', $share->id);

    $this->getJson(BRANDING_URL)->assertOk()->assertJsonPath('values', [
        ...collect($body)->except(['logo_media_id', 'logo_dark_media_id', 'icon_media_id', 'share_image_media_id'])->all(),
        'logo_media_id' => $logo->id,
        'logo_dark_media_id' => $dark->id,
        'icon_media_id' => $icon->id,
        'share_image_media_id' => $share->id,
    ]);
    expect(Setting::query()->find(StoreBranding::PREFIX.'tagline_en')?->updated_by)->toBeInt();
});

it('shares the logo with invoice details, both ways', function (): void {
    Support::actingAs(Role::Manager);
    $logo = brandingImage(800, 240);
    $other = brandingImage(600, 200);

    $this->putJson(BRANDING_URL, brandingBody(['logo_media_id' => $logo->id]))->assertOk();
    $this->getJson(BRANDING_INVOICE_URL)->assertOk()->assertJsonPath('logo_media_id', $logo->id);
    expect(app(CompanyDetails::class)->saved()['logo_media_id'])->toBe($logo->id);

    $this->putJson(BRANDING_INVOICE_URL, ['logo_media_id' => $other->id])->assertOk();
    $this->getJson(BRANDING_URL)->assertOk()->assertJsonPath('values.logo_media_id', $other->id)->assertJsonPath('media.logo.id', $other->id);
});

it('leaves the invoice-only details alone', function (): void {
    Support::actingAs(Role::Manager);
    $this->putJson(BRANDING_INVOICE_URL, ['name_en' => 'Toy Mart BD Ltd', 'address_en' => 'House 7, Mirpur'])->assertOk();

    $this->putJson(BRANDING_URL, brandingBody())->assertOk();

    $this->getJson(BRANDING_INVOICE_URL)->assertOk()->assertJsonPath('name_en', 'Toy Mart BD Ltd')->assertJsonPath('address_en', 'House 7, Mirpur');
});

it('clears a value sent as null or blank', function (): void {
    Support::actingAs(Role::Manager);
    $dark = brandingImage(800, 240);
    $this->putJson(BRANDING_URL, brandingBody(['logo_dark_media_id' => $dark->id]))->assertOk();

    $this->putJson(BRANDING_URL, brandingBody(['tagline_en' => '   ', 'site_name_bn' => null]))->assertOk()
        ->assertJsonPath('values.tagline_en', null)
        ->assertJsonPath('values.site_name_bn', null)
        ->assertJsonPath('values.logo_dark_media_id', null)
        ->assertJsonPath('media.logo_dark', null);

    expect(Setting::query()->whereKey(StoreBranding::PREFIX.'tagline_en')->exists())->toBeFalse();
});

it('counts characters, not bytes, against the limits', function (): void {
    Support::actingAs(Role::Manager);

    $this->putJson(BRANDING_URL, brandingBody(['site_name_bn' => str_repeat('খ', 60), 'description_bn' => str_repeat('ক', 160)]))->assertOk();
    $this->putJson(BRANDING_URL, brandingBody(['site_name_bn' => str_repeat('খ', 61)]))->assertUnprocessable()->assertJsonPath('errors.0.field', 'site_name_bn');
    $this->putJson(BRANDING_URL, brandingBody(['tagline_en' => str_repeat('a', 81)]))->assertUnprocessable()->assertJsonPath('errors.0.field', 'tagline_en');
    $this->putJson(BRANDING_URL, brandingBody(['description_en' => str_repeat('a', 161)]))->assertUnprocessable()->assertJsonPath('errors.0.field', 'description_en');
});

it('refuses an image that cannot fill the slot, with a message for that field', function (string $field, Closure $media, string $key): void {
    Support::actingAs(Role::Manager);
    $id = $media();

    $this->putJson(BRANDING_URL, brandingBody([$field => $id]))
        ->assertUnprocessable()
        ->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'validation-error'))
        ->assertJsonPath('errors.0.field', $field)
        ->assertJsonPath('errors.0.message', __($key));
})->with([
    'non-square icon' => ['icon_media_id', fn (): int => brandingImage(1000, 800)->id, 'store_settings.branding_icon_not_square'],
    'icon just over 1% off square' => ['icon_media_id', fn (): int => brandingImage(1000, 1012)->id, 'store_settings.branding_icon_not_square'],
    'icon under 512' => ['icon_media_id', fn (): int => brandingImage(256, 256)->id, 'store_settings.branding_icon_too_small'],
    'share image too narrow' => ['share_image_media_id', fn (): int => brandingImage(800, 419)->id, 'store_settings.branding_share_too_small'],
    'share image square' => ['share_image_media_id', fn (): int => brandingImage(1200, 1200)->id, 'store_settings.branding_share_wrong_ratio'],
    'share image 16:9' => ['share_image_media_id', fn (): int => brandingImage(1600, 900)->id, 'store_settings.branding_share_wrong_ratio'],
    'logo still processing' => ['logo_media_id', fn (): int => brandingImage(800, 240, ['processing_status' => ImageProcessingStatus::Processing])->id, 'store_settings.branding_media_not_ready'],
    'dark logo failed' => ['logo_dark_media_id', fn (): int => brandingImage(800, 240, ['processing_status' => ImageProcessingStatus::Failed])->id, 'store_settings.branding_media_not_ready'],
    'a PDF as the icon' => ['icon_media_id', fn (): int => Media::factory()->document()->create()->id, 'store_settings.branding_media_not_image'],
    'a media id that does not exist' => ['share_image_media_id', fn (): int => 999999, 'store_settings.branding_media_missing'],
]);

it('accepts a share image within 3% of 1.91:1', function (int $width, int $height): void {
    Support::actingAs(Role::Manager);

    $this->putJson(BRANDING_URL, brandingBody(['share_image_media_id' => brandingImage($width, $height)->id]))->assertOk();
})->with(['1200 × 630' => [1200, 630], '1200 × 612' => [1200, 612], '2400 × 1256' => [2400, 1256]]);

it('translates the refusal for a Bangla admin', function (): void {
    Support::actingAs(Role::Manager);
    $this->putJson(BRANDING_URL, brandingBody(['icon_media_id' => brandingImage(1000, 800)->id]), ['Accept-Language' => 'bn'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.message', 'স্টোর আইকন বর্গাকার হতে হবে (প্রস্থ ও উচ্চতা সমান)।');
});

it('purges the storefront store-info cache on save, and queues the favicons only when the icon changes', function (): void {
    Support::actingAs(Role::Manager);
    $icon = brandingImage(1024, 1024);

    $this->putJson(BRANDING_URL, brandingBody(['icon_media_id' => $icon->id]))->assertOk();
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['store-info']);
    Queue::assertPushedWithChain(RenderStoreFavicons::class, [RevalidateStorefrontTags::class], fn (RenderStoreFavicons $job): bool => $job->mediaId === $icon->id);
    Queue::assertPushed(RenderStoreFavicons::class, 1);

    // The same icon again: no new render.
    $this->putJson(BRANDING_URL, brandingBody(['icon_media_id' => $icon->id, 'tagline_en' => 'New']))->assertOk();
    Queue::assertPushed(RenderStoreFavicons::class, 1);

    // Removing the icon: nothing to render, and the old favicons stop counting.
    Setting::put(StoreBranding::ICON_PNGS_KEY, ['media_id' => $icon->id, 'paths' => []], SettingGroup::Store);
    $this->putJson(BRANDING_URL, brandingBody())->assertOk();
    Queue::assertPushed(RenderStoreFavicons::class, 1);
    expect(Setting::query()->whereKey(StoreBranding::ICON_PNGS_KEY)->exists())->toBeFalse();
});

describe('favicon job', function (): void {
    beforeEach(function (): void {
        Storage::fake('public');
        Storage::fake('local');
        config(['media.disk' => 'public', 'media.originals_disk' => 'local', 'media.cdn_url' => null]);
    });

    it('renders PNG favicons 32/180/192/512 to a new path and the storefront serves them', function (): void {
        $icon = brandingIconWithMaster(600, 600);
        Setting::put(StoreBranding::MEDIA_KEYS['icon_media_id'], $icon->id, SettingGroup::Store);

        app()->call([new RenderStoreFavicons($icon->id), 'handle']);

        $saved = Setting::get(StoreBranding::ICON_PNGS_KEY);
        expect($saved['media_id'])->toBe($icon->id)->and(array_keys($saved['paths']))->toEqual([32, 180, 192, 512]);
        foreach ($saved['paths'] as $size => $path) {
            expect($path)->toStartWith(RenderStoreFavicons::DIRECTORY.'/')->toEndWith("/icon-{$size}.png");
            $info = getimagesizefromstring((string) Storage::disk('public')->get($path));
            expect($info[0])->toBe((int) $size)->and($info[1])->toBe((int) $size)->and($info['mime'])->toBe('image/png');
        }

        // A second render writes to a fresh directory: paths never change in place.
        app()->call([new RenderStoreFavicons($icon->id), 'handle']);
        expect(dirname(Setting::get(StoreBranding::ICON_PNGS_KEY)['paths']['32']))->not->toBe(dirname($saved['paths']['32']));

        $token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
        $icons = $this->withToken($token)->getJson(BRANDING_STORE_INFO_URL)->assertOk()->json('icons');
        expect(array_keys($icons))->toBe(['png32', 'png180', 'png192', 'png512'])
            ->and($icons['png512'])->toStartWith('http')->toEndWith('/icon-512.png');
    });

    it('does nothing when the icon changed before it ran', function (): void {
        $icon = brandingIconWithMaster(600, 600);
        $newer = brandingImage(1024, 1024);
        Setting::put(StoreBranding::MEDIA_KEYS['icon_media_id'], $newer->id, SettingGroup::Store);

        app()->call([new RenderStoreFavicons($icon->id), 'handle']);

        expect(Setting::get(StoreBranding::ICON_PNGS_KEY))->toBeNull()
            ->and(Storage::disk('public')->allFiles(RenderStoreFavicons::DIRECTORY))->toBe([]);
    });

    it('is queued after commit on the media queue', function (): void {
        $job = new RenderStoreFavicons(1);

        expect($job->afterCommit)->toBeTrue()->and($job->queue)->toBe(config('media.queue'));
    });
});

it('keeps a branding image out of the media library delete', function (): void {
    Support::actingAs(Role::Manager);
    $icon = brandingImage(1024, 1024);
    $share = brandingImage(1200, 630);
    $this->putJson(BRANDING_URL, brandingBody(['icon_media_id' => $icon->id, 'share_image_media_id' => $share->id]))->assertOk();

    $usages = app(MediaUsages::class);
    expect($usages->for($icon->id)[0]->type)->toBe('store_branding')
        ->and($usages->for($icon->id)[0]->title)->toBe('Store icon & favicon')
        ->and($usages->for($share->id)[0]->adminPath)->toBe('/admin/settings/branding')
        ->and($usages->isUsed($icon->id))->toBeTrue()
        ->and($usages->whereUsed(Media::query(), false)->pluck('id')->all())->not->toContain($icon->id, $share->id);
});

describe('role matrix', function (): void {
    it('guards GET /settings/branding with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(BRANDING_URL));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('guards PUT /settings/branding with settings.update', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(BRANDING_URL, brandingBody()));
    })->with(RoleMatrix::dataset(Permission::SettingsUpdate));

    it('refuses a guest with 401', function (): void {
        $this->getJson(BRANDING_URL)->assertUnauthorized();
        $this->putJson(BRANDING_URL, brandingBody())->assertUnauthorized();
    });
});
