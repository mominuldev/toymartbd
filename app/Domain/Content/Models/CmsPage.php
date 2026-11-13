<?php

declare(strict_types=1);

namespace App\Domain\Content\Models;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Enums\PageTemplate;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A static page (DATABASE-DESIGN §3.9 `cms_pages`): About, Contact, Delivery policy, Returns
 * policy, Privacy, Terms, FAQ … One ASCII slug for both locales (rule 37). `content*` is HTML
 * sanitised on save (ProductDescription allowlist) and again on read. Live = published and
 * `published_at` reached; a future `published_at` is a scheduled publish.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $title_bn
 * @property string|null $content
 * @property string|null $content_bn
 * @property list<array{question: string, question_bn: string|null, answer: string, answer_bn: string|null}>|null $faq_items
 * @property PageTemplate $template
 * @property PageStatus $status
 * @property Carbon|null $published_at
 * @property int|null $author_id
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CmsPage extends Model
{
    /** `seo_meta.seoable_type` / `slug_history.sluggable_type` — a short key, never a class name. */
    public const string MORPH = 'page';

    /** Leaves room for a "-NNN" uniqueness suffix; `page:{slug}` stays within the 100-char tag limit. */
    public const int SLUG_BASE_MAX = 90;

    public const int SLUG_MAX = 94;

    public const int MAX_FAQ_ITEMS = 60;

    protected $table = 'cms_pages';

    /** @var list<string> */
    protected $fillable = [
        'slug', 'title', 'title_bn', 'content', 'content_bn', 'faq_items', 'template', 'status', 'published_at',
        'author_id', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'template' => 'default',
        'status' => 'draft',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'template' => PageTemplate::class,
            'status' => PageStatus::class,
            'published_at' => 'datetime',
            'faq_items' => 'array',
            'author_id' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function isLive(): bool
    {
        return $this->status === PageStatus::Published
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    public function isScheduled(): bool
    {
        return $this->status === PageStatus::Published
            && $this->published_at !== null
            && $this->published_at->greaterThan(now());
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', PageStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
