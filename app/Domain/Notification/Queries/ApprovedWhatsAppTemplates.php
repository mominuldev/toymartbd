<?php

declare(strict_types=1);

namespace App\Domain\Notification\Queries;

use App\Domain\Notification\Contracts\WhatsAppTemplateLibrary;
use App\Domain\Notification\DTOs\ApprovedWhatsAppTemplate;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\CustomTemplateVariables;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Domain\Notification\Support\TemplateRenderer;
use Illuminate\Database\Eloquent\Builder;

/**
 * {@see WhatsAppTemplateLibrary} over `notification_templates`. Approval is what staff
 * recorded in Message templates (no Meta API call — no WhatsApp Business account exists),
 * so "approved" means "staff marked it approved", and an edited body drops back to draft.
 *
 * Positional parameters: a system template's come from the catalog (`whatsapp_parameters`,
 * which must match what Meta approved); a custom template's are its body's variables in
 * order of appearance. Any other variable in the body is a fixed store value (hotline,
 * store URL, store name) filled in here; anything else is left as written.
 */
final class ApprovedWhatsAppTemplates implements WhatsAppTemplateLibrary
{
    /** Store-wide values a template body may carry outside its positional parameters. */
    private const array FIXED = ['hotline', 'store_url', 'store_name'];

    public function approved(string $locale): array
    {
        $locale = $locale === 'en' ? 'en' : 'bn';

        return $this->sendable()->orderBy('key')->get()
            ->map(fn (NotificationTemplate $t): ApprovedWhatsAppTemplate => $this->present($t, $locale))
            ->values()
            ->all();
    }

    public function find(string $providerTemplate, string $language): ?ApprovedWhatsAppTemplate
    {
        $locale = match ($language) {
            'en' => 'en',
            'bn' => 'bn',
            default => null,
        };
        if ($locale === null || $providerTemplate === '') {
            return null;
        }

        $template = $this->sendable()->where('provider_template', $providerTemplate)->orderBy('id')->first();

        return $template === null ? null : $this->present($template, $locale);
    }

    /** @return Builder<NotificationTemplate> */
    private function sendable(): Builder
    {
        return NotificationTemplate::query()
            ->where('channel', NotificationChannel::WhatsApp->value)
            ->where('whatsapp_status', WhatsAppApprovalStatus::Approved->value)
            // Utility only: never security (OTP, rule 32b) and never marketing.
            ->where('category', NotificationCategory::OrderUpdates->value)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereNotNull('provider_template')
            ->where('provider_template', '!=', '');
    }

    /** @param  'en'|'bn'  $locale */
    private function present(NotificationTemplate $t, string $locale): ApprovedWhatsAppTemplate
    {
        $body = $t->body($locale);
        $parameters = $t->isCustom()
            ? array_values(array_filter(TemplateRenderer::placeholders($body), static fn (string $v): bool => ! in_array($v, self::FIXED, true)))
            : TemplateCatalog::whatsAppParameters($t->key);

        $samples = CustomTemplateVariables::sampleValues($locale);
        $values = [];
        foreach ($parameters as $i => $name) {
            $values[$name] = '{{'.($i + 1).'}}';
        }
        foreach (self::FIXED as $name) {
            $values[$name] ??= $samples[$name] ?? '';
        }

        $name = $locale === 'en' ? $t->name_en : $t->name_bn;

        return new ApprovedWhatsAppTemplate(
            key: $t->key,
            providerTemplate: (string) $t->provider_template,
            language: ApprovedWhatsAppTemplate::languageFor($locale),
            locale: $locale,
            name: is_string($name) && $name !== '' ? $name : null,
            body: TemplateRenderer::render($body, $values, array_keys($values)),
            parameters: $parameters,
            examples: array_map(static fn (string $p): string => $samples[$p] ?? '', $parameters),
        );
    }
}
