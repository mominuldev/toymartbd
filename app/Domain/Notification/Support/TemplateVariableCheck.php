<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationTemplate;

/**
 * The server's verdict on the variables in a template body (P7 Message templates):
 *  - **unknown** — a `{name}` not on the template's allowlist. Refused on save.
 *  - **missing** — a WhatsApp system template must keep every positional parameter Meta
 *    approved ({{1}}, {{2}}… = TemplateCatalog::whatsAppParameters()); a body that drops
 *    one would no longer match the approved template. Refused on save.
 */
final class TemplateVariableCheck
{
    /** @return list<string> */
    public static function unknown(NotificationTemplate $template, string $text): array
    {
        return TemplateRenderer::unknownPlaceholders($text, $template->allowedVariables());
    }

    /** @return list<string> */
    public static function missing(NotificationTemplate $template, string $body): array
    {
        if ($template->channel !== NotificationChannel::WhatsApp || $template->isCustom()) {
            return [];
        }

        return array_values(array_diff(TemplateCatalog::whatsAppParameters($template->key), TemplateRenderer::placeholders($body)));
    }
}
