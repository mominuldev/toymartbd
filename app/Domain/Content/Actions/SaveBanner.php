<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\DTOs\BannerData;
use App\Domain\Content\Enums\BannerPlacement;
use App\Domain\Content\Events\BannersChanged;
use App\Domain\Content\Models\Banner;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create or replace a banner. Placement rules are invariants and re-checked here: only the
 * category page and popup carry an image; only a category-page banner targets a category; the
 * info strip — the one placement allowed on cart and checkout — is text plus at most a link to
 * a static page, never to a product or category (rule 25b: checkout carries no upsell).
 */
final class SaveBanner
{
    public function handle(?Banner $banner, BannerData $data, ?int $actorId = null): Banner
    {
        self::assertPlacementRules($data);

        return DB::transaction(function () use ($banner, $data, $actorId): Banner {
            $banner ??= new Banner;

            $banner->fill([
                'placement' => $data->placement,
                'name' => trim($data->name),
                'title' => self::text($data->title),
                'title_bn' => self::text($data->titleBn),
                'body' => self::text($data->body),
                'body_bn' => self::text($data->bodyBn),
                'media_id' => $data->mediaId,
                'link_url' => self::text($data->linkUrl),
                'link_label' => self::text($data->linkLabel),
                'link_label_bn' => self::text($data->linkLabelBn),
                'category_id' => $data->categoryId,
                'priority' => $data->priority,
                'is_active' => $data->isActive,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
            ]);

            if ($banner->exists && ! $banner->isDirty()) {
                return $banner;
            }

            $banner->updated_by = $actorId;
            $banner->save();

            BannersChanged::dispatch();

            return $banner;
        });
    }

    public static function assertPlacementRules(BannerData $data): void
    {
        if ($data->mediaId !== null && ! $data->placement->allowsImage()) {
            throw new InvalidArgumentException("A {$data->placement->value} banner has no image.");
        }

        if ($data->categoryId !== null && $data->placement !== BannerPlacement::CategoryPage) {
            throw new InvalidArgumentException('Only a category-page banner targets a category.');
        }

        if ($data->placement === BannerPlacement::InfoStrip && $data->linkUrl !== null && ! self::isPageLink($data->linkUrl)) {
            throw new InvalidArgumentException('An info strip links only to a static page (/pages/…).');
        }

        if ($data->startsAt !== null && $data->endsAt !== null && $data->endsAt->lessThanOrEqualTo($data->startsAt)) {
            throw new InvalidArgumentException('A banner must end after it starts.');
        }
    }

    public static function isPageLink(string $url): bool
    {
        return preg_match('#^/pages/[a-z0-9]+(?:-[a-z0-9]+)*/?$#', $url) === 1;
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
