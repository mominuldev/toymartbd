<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * The fixed sample customer invoice behind Settings › Invoices, receipts & labels › A4 invoice
 * "Open full size" (Figma 528:81351): an UNSAVED {@see Invoice} that renders through the real
 * template, with today's company details and logo, so the owner sees exactly what their
 * settings print. Never persisted (an issued invoice is immutable and numbered gap-free).
 *
 * Every figure is a fixed fixture string, the same ones the admin's on-screen preview shows —
 * nothing here adds anything up (rule 1). The phone and address are invented.
 */
final class SampleInvoice
{
    public const string NUMBER = 'INV-26-000126';

    public const string ORDER = 'TMB-260126';

    public function __construct(private readonly CompanyDetails $company) {}

    public function make(): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill([
            'type' => InvoiceType::Sales,
            'kind' => InvoiceKind::Original,
            'number' => self::NUMBER,
            'reference' => self::ORDER,
            'issued_by' => $this->company->snapshot(),
            'issued_to' => [
                'name' => 'Rahela Khatun',
                'phone' => '+8801712345678',
                'email' => 'rahela@example.com',
                'address' => 'House 12, Road 5, Dhanmondi',
                'area' => 'Dhanmondi',
                'upazila' => 'Dhanmondi',
                'district' => 'Dhaka',
                'division' => 'Dhaka',
                'landmark' => 'Opposite Dhanmondi Lake gate 2 · ধানমন্ডি লেক গেট 2-এর বিপরীতে',
                'preferred_language' => 'bn',
                'placed_at' => '2026-11-16T10:24:00+06:00',
                'coupon_code' => 'WINTER60',
                'note' => null,
            ],
            'lines' => [
                ['name' => 'Remote control racing car', 'name_bn' => 'রিমোট কন্ট্রোল রেসিং গাড়ি', 'variant' => 'Red · 1:16', 'sku' => 'TMB-RC-01', 'quantity' => 1, 'unit_price' => '2450.00', 'discount' => '0.00', 'line_total' => '2450.00'],
                ['name' => 'Building blocks, 500 pieces', 'name_bn' => 'বিল্ডিং ব্লক, 500 টুকরো', 'variant' => 'Classic colours', 'sku' => 'TMB-BB-500', 'quantity' => 2, 'unit_price' => '1450.00', 'discount' => '0.00', 'line_total' => '2900.00'],
                ['name' => 'Wooden alphabet puzzle', 'name_bn' => 'কাঠের বর্ণমালা পাজল', 'variant' => 'বাংলা + English', 'sku' => 'TMB-WP-12', 'quantity' => 1, 'unit_price' => '850.00', 'discount' => '0.00', 'line_total' => '850.00'],
            ],
            'currency' => 'BDT',
            'subtotal' => '6200.00',
            'discount' => '60.00',
            'shipping' => '120.00',
            'tax' => '0.00',
            'total' => '6260.00',
            'paid' => '0.00',
            'due' => '6260.00',
            'payment_method' => 'cod',
            'amount_in_words' => 'Taka six thousand two hundred sixty only',
            'amount_in_words_bn' => 'ছয় হাজার দুইশত ষাট টাকা মাত্র',
            'issued_at' => CarbonImmutable::parse('2026-11-16T10:30:00+06:00'),
        ]);

        return $invoice;
    }
}
