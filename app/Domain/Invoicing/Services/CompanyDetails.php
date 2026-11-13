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

    /** Shown when the owner has not set a name yet (the brand, not a placeholder). */
    public const array DEFAULT_NAMES = ['name_en' => 'Toy Mart BD', 'name_bn' => 'টয় মার্ট বিডি'];

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
