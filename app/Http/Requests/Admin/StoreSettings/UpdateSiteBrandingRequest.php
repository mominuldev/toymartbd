<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\StoreSettings;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\DTOs\SiteBrandingData;
use App\Domain\Content\Services\SiteBranding;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Closure;

/**
 * `PUT /settings/site` — Store settings › Site & branding; needs settings.update. The whole
 * section: a null or blank field clears the saved value (the storefront then falls back — the
 * business name, its built-in wordmark, its own icon).
 *
 * Every image is a ready library image. The store icon must be square and at least 512 × 512
 * (the largest app icon made from it); the share image about 1.91 : 1 and at least 600 wide;
 * a logo at least 120 wide.
 */
final class UpdateSiteBrandingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Storefront name in English; blank uses the business name. */
            'name' => ['nullable', 'string', 'max:'.SiteBranding::TEXT_FIELDS['name']],
            'name_bn' => ['nullable', 'string', 'max:'.SiteBranding::TEXT_FIELDS['name_bn']],
            /** Shown under the logo and in the home page title. */
            'tagline' => ['nullable', 'string', 'max:'.SiteBranding::TEXT_FIELDS['tagline']],
            'tagline_bn' => ['nullable', 'string', 'max:'.SiteBranding::TEXT_FIELDS['tagline_bn']],
            /** The search and social description a page uses when it has none of its own. */
            'description' => ['nullable', 'string', 'max:'.SiteBranding::TEXT_FIELDS['description']],
            'description_bn' => ['nullable', 'string', 'max:'.SiteBranding::TEXT_FIELDS['description_bn']],
            /** Logo for light backgrounds (`media.id`). */
            'logo_media_id' => ['nullable', 'integer', self::libraryImage(static fn (Media $m): ?string => ($m->width ?? 0) >= SiteBranding::LOGO_MIN_WIDTH ? null : 'logo_too_small')],
            /** Optional logo for dark backgrounds (`media.id`). */
            'logo_dark_media_id' => ['nullable', 'integer', self::libraryImage(static fn (Media $m): ?string => ($m->width ?? 0) >= SiteBranding::LOGO_MIN_WIDTH ? null : 'logo_too_small')],
            /** Square store icon, ≥ 512 × 512: the favicons and app icons are made from it (`media.id`). */
            'icon_media_id' => ['nullable', 'integer', self::libraryImage(static fn (Media $m): ?string => SiteBranding::iconFits($m->width, $m->height) ? null : 'icon_shape')],
            /** Share image, ~1.91 : 1 and ≥ 600 wide (`media.id`). */
            'social_image_media_id' => ['nullable', 'integer', self::libraryImage(static fn (Media $m): ?string => SiteBranding::socialFits($m->width, $m->height) ? null : 'social_shape')],
        ];
    }

    public function toData(): SiteBrandingData
    {
        $text = [];
        foreach (array_keys(SiteBranding::TEXT_FIELDS) as $field) {
            $value = $this->input($field);
            $text[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $media = [];
        foreach (SiteBranding::MEDIA_FIELDS as $field) {
            $value = $this->input($field);
            $media[$field] = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
        }

        return new SiteBrandingData($text, $media);
    }

    /**
     * A ready library image that passes `$check` (which returns a store_settings message key, or null).
     *
     * @param  Closure(Media): ?string  $check
     */
    private static function libraryImage(Closure $check): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($check): void {
            if ($value === null) {
                return;
            }
            $media = is_int($value) || (is_string($value) && ctype_digit($value)) ? Media::query()->find((int) $value) : null;
            if (! $media instanceof Media || $media->kind !== MediaKind::Image || $media->processing_status !== ImageProcessingStatus::Ready) {
                $fail((string) __('store_settings.image_not_ready'));

                return;
            }
            $problem = $check($media);
            if ($problem !== null) {
                $fail((string) __('store_settings.'.$problem));
            }
        };
    }
}
