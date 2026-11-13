<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Catalog\Services\ProductDescription;
use App\Domain\Content\DTOs\CmsPageData;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Events\CmsPageChanged;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Services\PageSlugs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or replace a static page. Body HTML (and FAQ answers) pass the ProductDescription
 * allowlist sanitiser; FAQ questions are plain text. Publishing without a date publishes now;
 * a future `published_at` schedules it. The slug changes only when one is given explicitly.
 *
 * SEO and slug history are written around this by the SEO module (Seo\Actions\SaveCmsPageWithSeo).
 */
final class SaveCmsPage
{
    public function __construct(private readonly PageSlugs $slugs) {}

    public function handle(?CmsPage $page, CmsPageData $data, ?int $actorId = null): CmsPage
    {
        try {
            return DB::transaction(function () use ($page, $data, $actorId): CmsPage {
                $page ??= new CmsPage(['author_id' => $actorId]);
                $previousSlug = $page->exists ? $page->slug : null;

                $slug = $data->slug ?? $page->slug ?? $this->slugs->generate($data->title, $data->titleBn);

                $publishedAt = match ($data->status) {
                    PageStatus::Published => $data->publishedAt ?? $page->published_at ?? now(),
                    default => $data->publishedAt ?? $page->published_at,
                };

                $page->fill([
                    'slug' => $slug,
                    'title' => trim($data->title),
                    'title_bn' => self::text($data->titleBn),
                    'content' => ProductDescription::sanitize($data->content),
                    'content_bn' => ProductDescription::sanitize($data->contentBn),
                    'faq_items' => self::faq($data->faqItems),
                    'template' => $data->template,
                    'status' => $data->status,
                    'published_at' => $publishedAt,
                ]);

                if ($page->exists && ! $page->isDirty()) {
                    return $page;
                }

                $page->updated_by = $actorId;
                $page->save();

                CmsPageChanged::dispatch($page->id, $page->slug, $previousSlug !== null && $previousSlug !== $page->slug ? $previousSlug : null);

                return $page;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => 'slug'])]);
        }
    }

    /**
     * @param  list<array{question: string, question_bn: string|null, answer: string, answer_bn: string|null}>|null  $items
     * @return list<array{question: string, question_bn: string|null, answer: string, answer_bn: string|null}>|null
     */
    private static function faq(?array $items): ?array
    {
        if ($items === null) {
            return null;
        }

        $out = [];
        foreach ($items as $item) {
            $question = self::plain($item['question']);
            $answer = ProductDescription::sanitize($item['answer']);

            if ($question === null || $answer === null) {
                continue;
            }

            $out[] = [
                'question' => $question,
                'question_bn' => self::plain($item['question_bn']),
                'answer' => $answer,
                'answer_bn' => ProductDescription::sanitize($item['answer_bn']),
            ];
        }

        return $out === [] ? null : $out;
    }

    private static function plain(?string $value): ?string
    {
        return self::text($value === null ? null : html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? null : trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === null || $value === '' ? null : $value;
    }
}
