<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Content\Actions\SaveBanner;
use App\Domain\Content\DTOs\BannerData;
use App\Domain\Content\Enums\BannerPlacement;
use App\Domain\Content\Models\Banner;
use App\Http\Requests\ApiFormRequest;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /content/banners` and `PUT /content/banners/{banner}` — the whole banner. `starts_at` /
 * `ends_at` are ISO 8601 instants (the admin sends Dhaka time with +06:00); stored UTC.
 *
 * Placement rules: only `category_page` and `popup` take an image (`media_id`, a library
 * image); only `category_page` targets a `category_id`; `info_strip` — the only placement shown
 * on cart and checkout — is text plus at most a link to a static page (`/pages/…`), never a
 * product or category (rule 25b).
 */
final class BannerRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $banner = $this->route('banner');

        return $banner instanceof Banner
            ? ($this->user()?->can('update', $banner) ?? false)
            : ($this->user()?->can('create', Banner::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'placement' => ['required', Rule::enum(BannerPlacement::class)],
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:191'],
            'title_bn' => ['nullable', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:500'],
            'body_bn' => ['nullable', 'string', 'max:500'],
            'media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where('kind', MediaKind::Image->value)],
            'link_url' => ['nullable', 'string', 'max:2048'],
            'link_label' => ['nullable', 'string', 'max:60'],
            'link_label_bn' => ['nullable', 'string', 'max:60'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'priority' => ['sometimes', 'integer', 'min:-100', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            /** @format date-time */
            'starts_at' => ['nullable', 'date'],
            /** @format date-time */
            'ends_at' => ['nullable', 'date'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $this->toData();

            if ($data->title === null && $data->body === null && $data->mediaId === null) {
                $validator->errors()->add('title', 'A banner needs a title, text or an image.');
            }

            if ($data->mediaId !== null && ! $data->placement->allowsImage()) {
                $validator->errors()->add('media_id', 'This placement is text-only.');
            }

            if ($data->categoryId !== null && $data->placement !== BannerPlacement::CategoryPage) {
                $validator->errors()->add('category_id', 'Only a category-page banner targets a category.');
            }

            if ($data->linkUrl !== null) {
                if ($data->placement === BannerPlacement::InfoStrip && ! SaveBanner::isPageLink($data->linkUrl)) {
                    $validator->errors()->add('link_url', 'An info strip shows on checkout, so it may only link to a store page (/pages/…).');
                } elseif (! SaveMenuRequest::validUrl($data->linkUrl)) {
                    $validator->errors()->add('link_url', 'Enter a site path starting with / or a full https:// address.');
                }
            }

            if ($data->startsAt !== null && $data->endsAt !== null && $data->endsAt->lessThanOrEqualTo($data->startsAt)) {
                $validator->errors()->add('ends_at', 'The banner must end after it starts.');
            }
        }];
    }

    public function toData(): BannerData
    {
        $str = fn (string $key): ?string => is_string($this->input($key)) && trim($this->input($key)) !== '' ? trim($this->input($key)) : null;
        $instant = fn (string $key): ?CarbonImmutable => $str($key) === null ? null : CarbonImmutable::parse((string) $str($key))->utc();

        return new BannerData(
            placement: BannerPlacement::from((string) $this->input('placement')),
            name: (string) $str('name'),
            title: $str('title'),
            titleBn: $str('title_bn'),
            body: $str('body'),
            bodyBn: $str('body_bn'),
            mediaId: $this->filled('media_id') ? $this->integer('media_id') : null,
            linkUrl: $str('link_url'),
            linkLabel: $str('link_label'),
            linkLabelBn: $str('link_label_bn'),
            categoryId: $this->filled('category_id') ? $this->integer('category_id') : null,
            priority: $this->integer('priority'),
            isActive: $this->has('is_active') ? $this->boolean('is_active') : true,
            startsAt: $instant('starts_at'),
            endsAt: $instant('ends_at'),
        );
    }
}
