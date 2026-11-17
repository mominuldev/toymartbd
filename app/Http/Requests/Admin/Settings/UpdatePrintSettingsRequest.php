<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Invoicing\Services\PrintSettings;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /settings/print` — Settings › Invoices, receipts & labels; needs settings.update. Send one
 * or more whole documents (`invoice`, `receipt`, `labels`); each replaces what is stored for it.
 */
final class UpdatePrintSettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The A4 customer invoice. */
            'invoice' => ['sometimes', 'array:'.implode(',', array_keys(PrintSettings::SCHEMA['invoice']))],
            /** `a4` or `a5`. */
            'invoice.paper' => ['required_with:invoice', 'string', Rule::in(PrintSettings::SCHEMA['invoice']['paper'])],
            /** `en`, `bn` or `both` (English with Bangla beneath). */
            'invoice.language' => ['required_with:invoice', 'string', Rule::in(PrintSettings::SCHEMA['invoice']['language'])],
            /** `brand` or `mono` (black and white). */
            'invoice.colour' => ['required_with:invoice', 'string', Rule::in(PrintSettings::SCHEMA['invoice']['colour'])],
            'invoice.show_bin' => ['required_with:invoice', 'boolean:strict'],
            'invoice.show_barcode' => ['required_with:invoice', 'boolean:strict'],
            'invoice.show_phone' => ['required_with:invoice', 'boolean:strict'],
            'invoice.show_email' => ['required_with:invoice', 'boolean:strict'],
            'invoice.show_sku' => ['required_with:invoice', 'boolean:strict'],
            'invoice.show_options' => ['required_with:invoice', 'boolean:strict'],
            'invoice.show_payment_stamp' => ['required_with:invoice', 'boolean:strict'],
            'invoice.show_track_qr' => ['required_with:invoice', 'boolean:strict'],
            'invoice.show_signatures' => ['required_with:invoice', 'boolean:strict'],

            /** The POS counter receipt. */
            'receipt' => ['sometimes', 'array:'.implode(',', array_keys(PrintSettings::SCHEMA['receipt']))],
            /** Roll width in mm: `80` or `58`. */
            'receipt.paper' => ['required_with:receipt', 'string', Rule::in(PrintSettings::SCHEMA['receipt']['paper'])],
            /** `en`, `bn`, or `ask` (the cashier picks per sale). */
            'receipt.language' => ['required_with:receipt', 'string', Rule::in(PrintSettings::SCHEMA['receipt']['language'])],
            /** `normal` or `large`. */
            'receipt.text_size' => ['required_with:receipt', 'string', Rule::in(PrintSettings::SCHEMA['receipt']['text_size'])],
            'receipt.auto_print' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_logo' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_branch' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_bin' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_cashier' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_customer_name' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_sku' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_barcode' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_whatsapp_qr' => ['required_with:receipt', 'boolean:strict'],
            'receipt.show_cut_line' => ['required_with:receipt', 'boolean:strict'],
            /** Message at the bottom, English. Empty uses the standard thank-you. */
            'receipt.footer_en' => ['sometimes', 'nullable', 'string', 'max:'.PrintSettings::FOOTER_MAX],
            /** Message at the bottom, Bangla. */
            'receipt.footer_bn' => ['sometimes', 'nullable', 'string', 'max:'.PrintSettings::FOOTER_MAX],

            /** Product barcode labels. */
            'labels' => ['sometimes', 'array:'.implode(',', array_keys(PrintSettings::SCHEMA['labels']))],
            /** The label stock loaded in the printer. */
            'labels.template' => ['required_with:labels', 'string', Rule::in(PrintSettings::SCHEMA['labels']['template'])],
            'labels.show_name' => ['required_with:labels', 'boolean:strict'],
            'labels.show_options' => ['required_with:labels', 'boolean:strict'],
            'labels.show_price' => ['required_with:labels', 'boolean:strict'],
            'labels.show_store' => ['required_with:labels', 'boolean:strict'],
            'labels.show_digits' => ['required_with:labels', 'boolean:strict'],
            'labels.generate_missing' => ['required_with:labels', 'boolean:strict'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys($this->all()) as $key) {
                    if (! array_key_exists((string) $key, PrintSettings::SCHEMA)) {
                        $validator->errors()->add((string) $key, 'This field is not accepted.');
                    }
                }
                if ($this->input('invoice') === null && $this->input('receipt') === null && $this->input('labels') === null) {
                    $validator->errors()->add('invoice', 'Send at least one of invoice, receipt or labels.');
                }
            },
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function documents(): array
    {
        $out = [];
        foreach (array_keys(PrintSettings::SCHEMA) as $doc) {
            $values = $this->validated($doc);
            if (is_array($values)) {
                $out[$doc] = $values;
            }
        }

        return $out;
    }
}
