<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A notification template (P4-NOT-03, P4-I18N-03). `chars_*` / `segments_*` /
 * `encoding_*` are measured server-side from the rendered sample on save; `segments_*`
 * is null for WhatsApp and email. `variables` is the allowlist a body may use.
 *
 * @property NotificationTemplate $resource
 */
#[SchemaName('NotificationTemplate')]
final class NotificationTemplateResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $t = $this->resource;

        return [
            'id' => $t->id,
            'key' => $t->key,
            /** @var 'sms'|'whatsapp'|'email' */
            'channel' => $t->channel->value,
            /** @var 'security'|'order_updates'|'marketing' */
            'category' => $t->category->value,
            'can_opt_out' => $t->category->canOptOut(),
            'subject_en' => $t->subject_en,
            'subject_bn' => $t->subject_bn,
            'preheader_en' => $t->preheader_en,
            'preheader_bn' => $t->preheader_bn,
            'body_en' => $t->body_en,
            'body_bn' => $t->body_bn,
            'provider_template' => $t->provider_template,
            'is_active' => $t->is_active,
            /** @var list<string> */
            'variables' => TemplateCatalog::variables($t->key),
            'chars_en' => $t->chars_en,
            'chars_bn' => $t->chars_bn,
            'segments_en' => $t->segments_en,
            'segments_bn' => $t->segments_bn,
            'encoding_en' => $t->encoding_en,
            'encoding_bn' => $t->encoding_bn,
            /** @var string|null */
            'updated_at' => $t->updated_at === null ? null : DateTimeImmutable::createFromInterface($t->updated_at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
        ];
    }
}
