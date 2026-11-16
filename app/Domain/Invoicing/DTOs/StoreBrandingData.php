<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\DTOs;

/**
 * One save of Store settings › Site & branding (the whole section). `texts` is keyed by
 * StoreBranding::TEXT_FIELDS and `media` by StoreBranding::MEDIA_KEYS; null removes the saved
 * value.
 */
final readonly class StoreBrandingData
{
    /**
     * @param  array<string, string|null>  $texts
     * @param  array<string, int|null>  $media
     */
    public function __construct(public array $texts, public array $media) {}
}
