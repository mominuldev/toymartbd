<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Invoicing\Services\PrintSettings;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `GET /settings/print/invoice-preview` — the sample invoice PDF; needs settings.view. Every
 * query parameter is optional and overrides the saved invoice setting of the same name, so the
 * page can preview an unsaved draft. Toggles are `1` or `0`. Any other parameter is a 422.
 */
final class InvoicePreviewRequest extends ApiFormRequest
{
    private const array TOGGLE = ['0', '1'];

    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsView->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $schema = PrintSettings::SCHEMA['invoice'];

        return [
            /** `a4` or `a5`. */
            'paper' => ['sometimes', 'string', Rule::in($schema['paper'])],
            /** `en`, `bn` or `both`. */
            'language' => ['sometimes', 'string', Rule::in($schema['language'])],
            /** `brand` or `mono`. */
            'colour' => ['sometimes', 'string', Rule::in($schema['colour'])],
            /** `1` or `0`. */
            'show_bin' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
            'show_barcode' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
            'show_phone' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
            'show_email' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
            'show_sku' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
            'show_options' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
            'show_payment_stamp' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
            'show_track_qr' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
            'show_signatures' => ['sometimes', 'string', Rule::in(self::TOGGLE)],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys($this->query->all()) as $key) {
                    if (! array_key_exists((string) $key, PrintSettings::SCHEMA['invoice'])) {
                        $validator->errors()->add((string) $key, 'This parameter is not accepted.');
                    }
                }
            },
        ];
    }

    /**
     * The draft values sent, typed like the stored settings.
     *
     * @return array<string, string|bool>
     */
    public function overrides(): array
    {
        $out = [];
        foreach (PrintSettings::SCHEMA['invoice'] as $field => $rule) {
            $value = $this->query($field);
            if (is_string($value)) {
                $out[$field] = $rule === 'bool' ? $value === '1' : $value;
            }
        }

        return $out;
    }
}
