<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\TemplateKind;
use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Domain\Notification\Support\TemplateRenderer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property TemplateKind $kind
 * @property string|null $name_en
 * @property string|null $name_bn
 * @property list<string>|null $custom_variables
 * @property WhatsAppApprovalStatus|null $whatsapp_status
 * @property Carbon|null $archived_at
 * @property int|null $created_by
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
            'kind' => TemplateKind::class,
            'custom_variables' => 'array',
            'whatsapp_status' => WhatsAppApprovalStatus::class,
            'archived_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public static function for(string $key, NotificationChannel $channel): ?self
    {
        return self::query()->where('key', $key)->where('channel', $channel->value)->first();
    }

    /**
     * The variables a body may name: the catalog's for a system template, the template's
     * own subset of the generic set for a custom one (Message templates library, P7).
     *
     * @return list<string>
     */
    public function allowedVariables(): array
    {
        if ($this->isCustom()) {
            return array_values(array_filter($this->custom_variables ?? [], 'is_string'));
        }

        return TemplateCatalog::variables($this->key);
    }

    /** Staff-made (marketing / manual). A missing `kind` (pre-library row) is a system row. */
    public function isCustom(): bool
    {
        return $this->kind instanceof TemplateKind && $this->kind->isCustom();
    }

    /** Null for SMS and email; a WhatsApp row never recorded reads as draft. */
    public function whatsAppStatus(): ?WhatsAppApprovalStatus
    {
        if ($this->channel !== NotificationChannel::WhatsApp) {
            return null;
        }

        return $this->whatsapp_status ?? WhatsAppApprovalStatus::Draft;
    }

    /** @return HasMany<NotificationTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(NotificationTemplateVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
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
