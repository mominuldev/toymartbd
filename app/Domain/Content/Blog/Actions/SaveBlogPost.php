<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Actions;

use App\Domain\Content\Blog\DTOs\BlogPostData;
use App\Domain\Content\Blog\DTOs\GuideSectionData;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogPostProduct;
use App\Domain\Content\Blog\Models\BlogPostSection;
use App\Domain\Content\Blog\Services\BlogCacheTags;
use App\Domain\Content\Blog\Services\BlogHtml;
use App\Domain\Content\Blog\Services\BlogSlugHistory;
use App\Domain\Content\Blog\Services\BlogSlugs;
use App\Domain\Content\Blog\Services\ReadingTime;
use App\Domain\Seo\Contracts\SeoMetaStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or replace a blog post document in one transaction: the post, its tags, guide
 * sections and picks, manual related products, its seo_meta and — when a live post's slug
 * changes — its slug_history row (the 301 middleware reads it). Then purges the storefront
 * cache (`content:blog`, `content:post:{id}`) after commit.
 *
 * Publishing: `draft` clears `published_at`; `published` keeps the original instant of an
 * already-published post, else now; `scheduled` needs a future `publishAt` (UTC) — the post
 * goes live at that instant and the sweep flips it to `published` and purges the cache.
 * Body HTML is sanitised here and reading time computed here, never by a client (rule 1).
 */
final class SaveBlogPost
{
    /** The seo_meta columns the post editor may write; the SEO module's panel writes the rest too. */
    public const array SEO_COLUMNS = ['title', 'title_bn', 'description', 'description_bn', 'canonical_url', 'robots', 'og_image', 'focus_keyword'];

    public function __construct(
        private readonly BlogSlugs $slugs,
        private readonly BlogSlugHistory $history,
        private readonly SeoMetaStore $seoMeta,
    ) {}

    public function handle(?BlogPost $existing, BlogPostData $data, ?int $actorId = null): BlogPost
    {
        $now = CarbonImmutable::now();

        if ($data->status === PostStatus::Scheduled && ($data->publishAt === null || $data->publishAt->lessThanOrEqualTo($now))) {
            throw ValidationException::withMessages(['publish_at' => __('blog.publish_at_future')]);
        }

        try {
            $post = DB::transaction(function () use ($existing, $data, $actorId, $now): BlogPost {
                $post = $existing ?? new BlogPost;
                $previousSlug = $existing?->slug;
                $wasLive = $existing?->isLive() ?? false;

                $body = BlogHtml::sanitize($data->body);
                $bodyBn = BlogHtml::sanitize($data->bodyBn);

                $post->fill([
                    'type' => $data->type,
                    'title' => $data->title,
                    'title_bn' => $data->titleBn,
                    'excerpt' => $data->excerpt,
                    'excerpt_bn' => $data->excerptBn,
                    'body' => $body,
                    'body_bn' => $bodyBn,
                    'cover_media_id' => $data->coverMediaId,
                    'author_id' => $data->authorId,
                    'category_id' => $data->categoryId,
                    'reading_minutes' => ReadingTime::minutes($body.' '.$this->sectionText($data, 'en'), 'en'),
                    'reading_minutes_bn' => $bodyBn === null ? null : ReadingTime::minutes($bodyBn.' '.$this->sectionText($data, 'bn'), 'bn'),
                    'is_featured' => $data->isFeatured,
                    'guide_age_band' => $data->type === PostType::BuyingGuide ? $data->guideAgeBand : null,
                    'guide_budget_min' => $data->type === PostType::BuyingGuide ? $data->guideBudgetMin : null,
                    'guide_budget_max' => $data->type === PostType::BuyingGuide ? $data->guideBudgetMax : null,
                    'updated_by' => $actorId,
                ]);

                if ($existing === null) {
                    $post->created_by = $actorId;
                }

                if ($data->slug !== null && $data->slug !== '') {
                    $post->slug = $data->slug;
                } elseif ($existing === null) {
                    $post->slug = $this->slugs->generate(BlogPost::class, $data->title, $data->titleBn);
                }

                $this->applyStatus($post, $data, $now);
                $post->save();

                $post->tags()->sync($data->tagIds);
                $this->replaceLinks($post, $data);
                $this->history->record(BlogPost::SEO_TYPE, $post->id, $previousSlug, $post->slug, $wasLive, $actorId);

                if ($data->seo !== null && $data->seo !== []) {
                    // The SEO module's one write path (merge: the panel's other columns stay).
                    $this->seoMeta->merge(BlogPost::SEO_TYPE, $post->id, array_intersect_key($data->seo, array_flip(self::SEO_COLUMNS)));
                }

                BlogCacheTags::purge([$post->id => array_values(array_unique(array_filter([$post->slug, $previousSlug])))]);

                return $post;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }

        return $post->refresh();
    }

    private function applyStatus(BlogPost $post, BlogPostData $data, CarbonImmutable $now): void
    {
        $post->status = $data->status;

        $post->published_at = match ($data->status) {
            PostStatus::Draft => null,
            PostStatus::Scheduled => $data->publishAt,
            // Republishing an already-published post keeps its original date.
            PostStatus::Published => $post->exists && $post->getOriginal('status') === PostStatus::Published && $post->getOriginal('published_at') !== null
                ? $post->getOriginal('published_at')
                : $now,
        };
    }

    private function replaceLinks(BlogPost $post, BlogPostData $data): void
    {
        BlogPostSection::query()->where('post_id', $post->id)->delete(); // picks cascade
        BlogPostProduct::query()->where('post_id', $post->id)->delete();

        if ($data->type === PostType::BuyingGuide) {
            foreach ($data->sections as $i => $section) {
                $row = BlogPostSection::query()->create([
                    'post_id' => $post->id,
                    'position' => $i,
                    'heading' => $section->heading,
                    'heading_bn' => $section->headingBn,
                    'body' => BlogHtml::sanitize($section->body),
                    'body_bn' => BlogHtml::sanitize($section->bodyBn),
                    'age_band' => $section->ageBand,
                    'budget_min' => $section->budgetMin,
                    'budget_max' => $section->budgetMax,
                ]);

                foreach ($section->picks as $j => $pick) {
                    BlogPostProduct::query()->create([
                        'post_id' => $post->id,
                        'section_id' => $row->id,
                        'product_id' => $pick->productId,
                        'position' => $j,
                        'note' => $pick->note,
                        'note_bn' => $pick->noteBn,
                    ]);
                }
            }
        }

        foreach (array_values(array_unique($data->relatedProductIds)) as $i => $productId) {
            BlogPostProduct::query()->create(['post_id' => $post->id, 'section_id' => null, 'product_id' => $productId, 'position' => $i]);
        }
    }

    /** Guide section headings and intros count towards reading time. */
    private function sectionText(BlogPostData $data, string $locale): string
    {
        if ($data->type !== PostType::BuyingGuide) {
            return '';
        }

        return implode(' ', array_map(static fn (GuideSectionData $s): string => $locale === 'bn'
            ? ($s->headingBn ?? '').' '.BlogHtml::plainText(BlogHtml::sanitize($s->bodyBn))
            : $s->heading.' '.BlogHtml::plainText(BlogHtml::sanitize($s->body)), $data->sections));
    }
}
