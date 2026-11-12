<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Domain\Notification\Support\TemplateRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One editable template (P4-NOT-03), EN + BN. Character and segment counts are
 * measured server-side by {@see \App\Domain\Notification\Services\TemplateMeasurer}
 * whenever the body changes.
 *
 * @property int $id
 * @property string $key
 * @property NotificationChannel $channel
 * @property NotificationCategory $category
 * @property string|null $subject_en
 * @property string|null $subject_bn
 * @property string|null $preheader_en
 * @property string|null $preheader_bn
 * @property string $body_en
 * @property string $body_bn
 * @property string|null $provider_template
 * @property bool $is_active
 * @property int|null $chars_en
 * @property int|null $chars_bn
 * @property int|null $segments_en
 * @property int|null $segments_bn
 * @property string|null $encoding_en
 * @property string|null $encoding_bn
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NotificationTemplate extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'category' => NotificationCategory::class,
            'is_active' => 'boolean',
            'chars_en' => 'integer',
            'chars_bn' => 'integer',
            'segments_en' => 'integer',
            'segments_bn' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public static function for(string $key, NotificationChannel $channel): ?self
    {
        return self::query()->where('key', $key)->where('channel', $channel->value)->first();
    }

    /** @return list<string> */
    public function allowedVariables(): array
    {
        return TemplateCatalog::variables($this->key);
    }

    public function body(string $locale): string
    {
        return $locale === 'en' ? $this->body_en : $this->body_bn;
    }

    public function subject(string $locale): ?string
    {
        return $locale === 'en' ? $this->subject_en : $this->subject_bn;
    }

    public function preheader(string $locale): ?string
    {
        return $locale === 'en' ? $this->preheader_en : $this->preheader_bn;
    }

    /** @param  array<string, string>  $values */
    public function render(string $locale, array $values): string
    {
        return TemplateRenderer::render($this->body($locale), $values, $this->allowedVariables());
    }

    /** @param  array<string, string>  $values */
    public function renderSubject(string $locale, array $values): string
    {
        return TemplateRenderer::render((string) $this->subject($locale), $values, $this->allowedVariables());
    }

    /** @param  array<string, string>  $values */
    public function renderPreheader(string $locale, array $values): string
    {
        return TemplateRenderer::render((string) $this->preheader($locale), $values, $this->allowedVariables());
    }
}
