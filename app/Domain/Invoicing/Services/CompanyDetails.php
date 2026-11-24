<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * The business identity printed on invoices (PRD N-13), edited at Admin → Settings →
 * Invoice details and stored in `settings` (group `store`, keys `invoice.company.*`).
 *
 * {@see self::snapshot()} is what IssueSalesInvoice / IssuePurchaseInvoice copy onto the
 * invoice row (`issued_by`) — a reprint reads the snapshot, never this class, so changing a
 * value here only affects invoices issued afterwards.
 *
 * Until the owner fills them in, the values are PLACEHOLDERS (UI-UX-DESIGN-SPEC §10): the
 * shop name falls back to the brand name and every other field to null; `placeholders`
 * lists the keys still unset so the PDF can mark them and the screen can warn.
 *
 * Not cached: a settings read per issued invoice is nothing next to rendering a PDF.
 */
final class CompanyDetails
{
    public const string PREFIX = 'invoice.company.';

    /** Field → whether it is required before the owner's details count as complete. */
    public const array FIELDS = [
        'name_en' => true,
        'name_bn' => true,
        'address_en' => true,
        'address_bn' => true,
        'hotline' => true,
        'email' => false,
        'website' => false,
        'trade_licence' => true,
        'bin' => true,
        'logo_media_id' => false,
        'footer_en' => false,
        'footer_bn' => false,
    ];

    /**
     * The subset of FIELDS that Store settings › Business edits (Figma B · 38 241:50827).
     * Same keys, same rows: Business and Invoice details are two views of one value, never
     * two copies of it.
     */
    public const array BUSINESS_FIELDS = ['name_en', 'name_bn', 'trade_licence', 'bin', 'hotline'];

    /**
     * The customer-facing WhatsApp number (E.164), edited in Store settings › Business. A key of
     * its own rather than one of FIELDS: the invoice-details save replaces every FIELDS key, and
     * it does not carry this one.
     */
    public const string WHATSAPP_KEY = 'store.whatsapp';

    /**
     * The Facebook Page's public username or numeric id, as used in `https://m.me/{value}` (the
     * storefront's Messenger chat link). Edited in Store settings › Business; a key of its own for
     * the same reason as WHATSAPP_KEY.
     */
    public const string MESSENGER_KEY = 'store.messenger';

    /** Shown when the owner has not set a name yet (the brand, not a placeholder). */
    public const array DEFAULT_NAMES = ['name_en' => 'Smart Junior', 'name_bn' => 'Smart Junior'];

    /**
     * Saved values only (null when unset), for the settings screen.
     *
     * @return array<string, string|int|null>
     */
    public function saved(): array
    {
        $rows = Setting::query()
            ->where('group', SettingGroup::Store->value)
            ->whereIn('key', array_map(static fn (string $f): string => self::PREFIX.$f, array_keys(self::FIELDS)))
            ->get()
            ->keyBy('key');

        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $raw = $rows->get(self::PREFIX.$field)?->value;
            $values[$field] = match (true) {
                $field === 'logo_media_id' => is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : null,
                is_string($raw) && trim($raw) !== '' => $raw,
                default => null,
            };
        }

        return $values;
    }

    /** The saved WhatsApp number (E.164), or null. */
    public function whatsapp(): ?string
    {
        $raw = Setting::get(self::WHATSAPP_KEY);

        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /** The saved Messenger Page username or id, or null. */
    public function messenger(): ?string
    {
        $raw = Setting::get(self::MESSENGER_KEY);

        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /**
     * What an invoice copies at issue: every field (names defaulted to the brand), plus
     * `logo` — a reference to the logo rendition file current at issue, or null (P9,
     * {@see InvoiceLogo}) — and `placeholders` — the required fields still unset, printed as
     * "[placeholder]".
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $saved = $this->saved();
        $placeholders = [];
        foreach (self::FIELDS as $field => $required) {
            if ($required && $saved[$field] === null) {
                $placeholders[] = $field;
            }
        }
        foreach (self::DEFAULT_NAMES as $field => $name) {
            $saved[$field] ??= $name;
        }
        $logo = InvoiceLogo::reference(is_int($saved['logo_media_id']) ? $saved['logo_media_id'] : null);

        return $saved + ['logo' => $logo, 'placeholders' => $placeholders];
    }
}
