<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Brand;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A brand in the admin: `{ id, name, name_bn, slug, description, description_bn,
 * is_active, logo_url, created_at, updated_at }`. `is_active` is false once archived.
 *
 * @property Brand $resource
 */
#[SchemaName('Brand')]
final class BrandResource extends ApiResource
{
    /**
     * @return array{id: int, name: string, name_bn: string|null, slug: string, description: string|null, description_bn: string|null, is_active: bool, logo_url: string|null, created_at: string, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        $brand = $this->resource;

        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'name_bn' => $brand->name_bn,
            'slug' => $brand->slug,
            'description' => $brand->description,
            'description_bn' => $brand->description_bn,
            'is_active' => $brand->isActive(),
            /** @var string|null */
            'logo_url' => self::logoUrl($brand),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => self::utcIso($brand->created_at),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'updated_at' => self::utcIso($brand->updated_at),
        ];
    }

    /** `2026-11-11T09:30:00Z`. Plain DateTime calls: Carbon's own API is costly for Scramble to analyse. */
    private static function utcIso(?DateTimeInterface $at): string
    {
        return $at === null ? '' : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }

    public static function logoUrl(Brand $brand): ?string
    {
        return $brand->logo_path !== null ? Storage::disk('public')->url($brand->logo_path) : null;
    }
}
