<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\StoreSettings;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Invoicing\DTOs\StoreBrandingData;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Closure;

/**
 * `PUT /settings/branding` — Store settings › Site & branding; needs settings.update. The whole
 * section: a null or empty field removes the saved value (a blank site name falls back to the
 * business name). Every media id must be a ready image in the media library; the icon must be
 * square and at least 512 × 512, the share image at least 1200 wide at 1.91:1 (± 3%).
 */
final class UpdateStoreBrandingRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Shown in the browser tab and search results. Blank uses the business name. */
            'site_name_en' => ['nullable', 'string', 'max:60'],
            'site_name_bn' => ['nullable', 'string', 'max:60'],
            'tagline_en' => ['nullable', 'string', 'max:80'],
            'tagline_bn' => ['nullable', 'string', 'max:80'],
            /** The search and social description; Google shows about 155 characters. */
            'description_en' => ['nullable', 'string', 'max:160'],
            'description_bn' => ['nullable', 'string', 'max:160'],
            /**
             * The logo for light backgrounds (storefront header, invoices, emails). The same value
             * as `logo_media_id` on `/settings/invoice`.
             */
            'logo_media_id' => ['nullable', 'integer', self::mediaRule()],
            /** The logo for dark backgrounds (optional). */
            'logo_dark_media_id' => ['nullable', 'integer', self::mediaRule()],
            /** Store icon and favicon: square (|w − h| ≤ 1%), at least 512 × 512. */
            'icon_media_id' => ['nullable', 'integer', self::mediaRule()],
            /** Social share image: at least 1200 wide, 1.91:1 (± 3%). */
            'share_image_media_id' => ['nullable', 'integer', self::mediaRule()],
        ];
    }

    public function toData(): StoreBrandingData
    {
        $texts = [];
        foreach (array_keys(StoreBranding::TEXT_FIELDS) as $field) {
            $value = $this->input($field);
            $texts[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $media = [];
        foreach (array_keys(StoreBranding::MEDIA_KEYS) as $field) {
            $value = $this->input($field);
            $media[$field] = is_numeric($value) ? (int) $value : null;
        }

        return new StoreBrandingData($texts, $media);
    }

    private static function mediaRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || ! is_numeric($value)) {
                return; // `integer` reports it
            }
            $problem = StoreBranding::mediaProblem($attribute, Media::query()->find((int) $value));
            if ($problem !== null) {
                $fail((string) __($problem));
            }
        };
    }
}
