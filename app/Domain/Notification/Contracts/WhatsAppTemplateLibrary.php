<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\DTOs\ApprovedWhatsAppTemplate;

/**
 * Read-only view of the WhatsApp templates staff may send by hand — the seam the Inbox uses to
 * restart a conversation whose 24-hour customer-service window has closed (Meta: outside the
 * window only an approved template may be sent). The Notification domain owns the template
 * library; the Inbox only reads it through this contract (deptrac: Inbox → NotificationContracts).
 *
 * Only templates that are on the WhatsApp channel, marked APPROVED by staff, active, not
 * archived, carry a provider (Meta) template name, and are **utility** — category
 * `order_updates`. Never `security` (OTP/authentication, rules 32b/39) and never `marketing`.
 */
interface WhatsAppTemplateLibrary
{
    /**
     * Every sendable template in one language.
     *
     * @param  'en'|'bn'  $locale
     * @return list<ApprovedWhatsAppTemplate>
     */
    public function approved(string $locale): array;

    /** One sendable template by its Meta name and Meta language code (`en`, `bn`); null when not sendable now. */
    public function find(string $providerTemplate, string $language): ?ApprovedWhatsAppTemplate;
}
