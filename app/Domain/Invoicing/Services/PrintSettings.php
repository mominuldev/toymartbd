<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * What the printed documents show (owner request 2026-11-17, Figma "Invoices, receipts &
 * labels" 528:81351 / 529:81638 / 529:82666): the A4 customer invoice, the POS counter receipt
 * and product barcode labels. One `settings` row per document (group `store`, keys
 * `print.invoice`, `print.receipt`, `print.labels`), each a JSON object merged over
 * {@see self::DEFAULTS}, so a key added later starts at its default without a migration.
 *
 * Store identity (name, address, hotline, BIN, logo, invoice footer) is not here: it stays in
 * {@see CompanyDetails}, edited on the same screen. These are only layout choices; none of them
 * changes a figure (rule 1). Reprints of an issued invoice use these at render time — they are
 * presentation, not part of the invoice's snapshot.
 */
final class PrintSettings
{
    public const string PREFIX = 'print.';

    /** Document → field → allowed values (a list) or `bool` / `text` for a toggle / free text. */
    public const array SCHEMA = [
        'invoice' => [
            'paper' => ['a4', 'a5'],
            'language' => ['en', 'bn', 'both'],
            'colour' => ['brand', 'mono'],
            'show_bin' => 'bool',
            'show_barcode' => 'bool',
            'show_phone' => 'bool',
            'show_email' => 'bool',
            'show_sku' => 'bool',
            'show_options' => 'bool',
            'show_payment_stamp' => 'bool',
            'show_track_qr' => 'bool',
            'show_signatures' => 'bool',
        ],
        'receipt' => [
            'paper' => ['80', '58'],
            'language' => ['en', 'bn', 'ask'],
            'text_size' => ['normal', 'large'],
            'auto_print' => 'bool',
            'show_logo' => 'bool',
            'show_branch' => 'bool',
            'show_bin' => 'bool',
            'show_cashier' => 'bool',
            'show_customer_name' => 'bool',
            'show_sku' => 'bool',
            'show_barcode' => 'bool',
            'show_whatsapp_qr' => 'bool',
            'show_cut_line' => 'bool',
            'footer_en' => 'text',
            'footer_bn' => 'text',
        ],
        'labels' => [
            'template' => ['roll_38x25', 'roll_50x25', 'a4_65', 'a4_24'],
            'show_name' => 'bool',
            'show_options' => 'bool',
            'show_price' => 'bool',
            'show_store' => 'bool',
            'show_digits' => 'bool',
            'generate_missing' => 'bool',
        ],
    ];

    /** The Figma defaults — what prints until the owner saves anything. */
    public const array DEFAULTS = [
        'invoice' => [
            'paper' => 'a4', 'language' => 'en', 'colour' => 'brand',
            'show_bin' => true, 'show_barcode' => true, 'show_phone' => true, 'show_email' => false,
            'show_sku' => true, 'show_options' => true, 'show_payment_stamp' => true,
            'show_track_qr' => true, 'show_signatures' => true,
        ],
        'receipt' => [
            'paper' => '80', 'language' => 'ask', 'text_size' => 'normal', 'auto_print' => true,
            'show_logo' => true, 'show_branch' => true, 'show_bin' => true, 'show_cashier' => true,
            'show_customer_name' => false, 'show_sku' => false, 'show_barcode' => true,
            'show_whatsapp_qr' => true, 'show_cut_line' => true, 'footer_en' => null, 'footer_bn' => null,
        ],
        'labels' => [
            'template' => 'roll_50x25', 'show_name' => true, 'show_options' => true, 'show_price' => true,
            'show_store' => true, 'show_digits' => true, 'generate_missing' => true,
        ],
    ];

    /** Longest receipt footer, characters (a 58 mm roll fits about 32 per line). */
    public const int FOOTER_MAX = 160;

    /** @return array<string, string|bool|null> */
    public function invoice(): array
    {
        return $this->document('invoice');
    }

    /** @return array<string, string|bool|null> */
    public function receipt(): array
    {
        return $this->document('receipt');
    }

    /** @return array<string, string|bool|null> */
    public function labels(): array
    {
        return $this->document('labels');
    }

    /** @return array{invoice: array<string, string|bool|null>, receipt: array<string, string|bool|null>, labels: array<string, string|bool|null>} */
    public function all(): array
    {
        return ['invoice' => $this->invoice(), 'receipt' => $this->receipt(), 'labels' => $this->labels()];
    }

    /**
     * Documents the owner has saved at least once.
     *
     * @return list<string>
     */
    public function saved(): array
    {
        $keys = array_map(static fn (string $d): string => self::PREFIX.$d, array_keys(self::SCHEMA));

        return array_values(array_map(
            static fn (string $k): string => substr($k, strlen(self::PREFIX)),
            Setting::query()->where('group', SettingGroup::Store->value)->whereKey($keys)->pluck('key')->all(),
        ));
    }

    /** @return array<string, string|bool|null> */
    private function document(string $doc): array
    {
        $stored = Setting::get(self::PREFIX.$doc);

        return self::clean($doc, is_array($stored) ? $stored : []);
    }

    /**
     * Defaults overlaid with the stored values that still fit the schema — a value a later
     * release dropped from an allow-list falls back to its default rather than printing junk.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, string|bool|null>
     */
    public static function clean(string $doc, array $values): array
    {
        $out = [];
        foreach (self::SCHEMA[$doc] as $field => $rule) {
            $default = self::DEFAULTS[$doc][$field];
            $value = $values[$field] ?? null;
            $out[$field] = match (true) {
                $rule === 'bool' => is_bool($value) ? $value : $default,
                $rule === 'text' => is_string($value) && trim($value) !== '' ? trim($value) : null,
                default => is_string($value) && in_array($value, $rule, true) ? $value : $default,
            };
        }

        return $out;
    }
}
