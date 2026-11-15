<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Catalog\Services\ProductDescription;
use App\Domain\Content\Blocks\Actions\CreateBlock;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\DTOs\BlockSettings;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Models\CmsPage;
use Illuminate\Support\Facades\DB;

/**
 * Moves a page that predates the section builder onto it (owner decision 2026-11-16: every page
 * is built from sections, like the homepage): its rich text becomes a Rich text section, its FAQ
 * items become FAQ sections (20 questions each, answers as plain text), and the result is
 * published as version 1 so the storefront shows the same content. A page that already has a
 * draft or a published layout is left alone, so running it twice changes nothing. The page's own
 * `content*` and `faq_items` columns are kept (expand-and-contract).
 */
final class BuildPageLayoutFromContent
{
    public const int FAQ_PER_BLOCK = 20;

    public function __construct(private readonly CreateBlock $create, private readonly PublishLayout $publish) {}

    /** @return int the number of sections created */
    public function handle(CmsPage $page, ?int $userId = null): int
    {
        $key = LayoutKey::page($page->id);

        return DB::transaction(function () use ($page, $key, $userId): int {
            if (CmsBlock::query()->where('placement', $key->value)->exists() || CmsLayoutVersion::latestFor($key) !== null) {
                return 0;
            }

            $created = 0;
            $html = self::filled($page->content) ?? self::filled($page->content_bn);
            if ($html !== null) {
                $this->add($key, BlockType::RichText, [
                    'html' => $html,
                    'html_bn' => self::filled($page->content) !== null ? self::filled($page->content_bn) : null,
                ], $userId);
                $created++;
            }

            foreach (array_chunk($page->faq_items ?? [], self::FAQ_PER_BLOCK) as $index => $chunk) {
                $this->add($key, BlockType::Faq, [
                    'title' => mb_substr($index === 0 ? $page->title : $page->title.' ('.($index + 1).')', 0, 120),
                    'title_bn' => $page->title_bn === null ? null : mb_substr($page->title_bn, 0, 120),
                    'items' => array_map(static fn (array $item): array => [
                        'question' => mb_substr(trim($item['question']), 0, 200),
                        'question_bn' => self::plain($item['question_bn'] ?? null, 200),
                        'answer' => self::plain($item['answer'], 2000) ?? '',
                        'answer_bn' => self::plain($item['answer_bn'] ?? null, 2000),
                    ], $chunk),
                ], $userId);
                $created++;
            }

            if ($created > 0) {
                $this->publish->handle($key, $userId, 'Moved from the page editor');
            }

            return $created;
        });
    }

    /** @param  array<string, mixed>  $content */
    private function add(LayoutKey $key, BlockType $type, array $content, ?int $userId): void
    {
        $schema = $type->schema();
        $this->create->handle($key, $type, new BlockSettings(content: $schema->normalize($content)), null, $userId);
    }

    private static function filled(?string $html): ?string
    {
        return $html !== null && trim(ProductDescription::plainText($html)) !== '' ? $html : null;
    }

    private static function plain(?string $html, int $max): ?string
    {
        if ($html === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
