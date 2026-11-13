<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\TemplateKind;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\SmsTariff;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeInterface;
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
            /** @var 'system'|'marketing'|'manual' */
            'kind' => ($t->kind ?? TemplateKind::System)->value,
            'is_custom' => $t->isCustom(),
            'name_en' => $t->name_en,
            'name_bn' => $t->name_bn,
            /** @var 'draft'|'submitted'|'approved'|null */
            'whatsapp_status' => $t->whatsAppStatus()?->value,
            /** @var list<string> */
            'variables' => $t->allowedVariables(),
            /** @var list<string> Positional parameters a WhatsApp system body must keep (Meta-approved). */
            'required_variables' => $t->channel === NotificationChannel::WhatsApp && ! $t->isCustom() ? TemplateCatalog::whatsAppParameters($t->key) : [],
            'chars_en' => $t->chars_en,
            'chars_bn' => $t->chars_bn,
            'segments_en' => $t->segments_en,
            'segments_bn' => $t->segments_bn,
            'encoding_en' => $t->encoding_en,
            'encoding_bn' => $t->encoding_bn,
            /** @var string|null Cost of one SMS send at the configured per-segment rate, server-computed; null for WhatsApp and email. */
            'cost_en' => $this->smsCost($request, $t->segments_en),
            /** @var string|null */
            'cost_bn' => $this->smsCost($request, $t->segments_bn),
            /** @var string|null */
            'updated_by_name' => $t->updated_by === null ? null : $t->editor?->name,
            /** @var string|null */
            'archived_at' => self::iso($t->archived_at),
            /** @var string|null */
            'updated_at' => self::iso($t->updated_at),
        ];
    }

    private function smsCost(Request $request, ?int $segments): ?string
    {
        if ($this->resource->channel !== NotificationChannel::Sms || $segments === null) {
            return null;
        }

        // One settings read per request, not per row (MessagingSettings never caches).
        $rate = $request->attributes->get('notification_templates.sms_rate');
        if (! $rate instanceof Money) {
            $rate = app(SmsTariff::class)->perSegment();
            $request->attributes->set('notification_templates.sms_rate', $rate);
        }

        return $rate->times($segments)->toDecimal();
    }

    private static function iso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
