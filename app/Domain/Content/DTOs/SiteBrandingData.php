<?php

declare(strict_types=1);

namespace App\Domain\Content\DTOs;

/**
 * Store settings › Site & branding, as validated by UpdateSiteBrandingRequest: every field of
 * the section (null clears it). Text keyed by SiteBranding::TEXT_FIELDS, media ids by
 * SiteBranding::MEDIA_FIELDS.
 */
final readonly class SiteBrandingData
{
    /**
     * @param  array<string, string|null>  $text
     * @param  array<string, int|null>  $media
     */
    public function __construct(
        public array $text,
        public array $media,
    ) {}
}
