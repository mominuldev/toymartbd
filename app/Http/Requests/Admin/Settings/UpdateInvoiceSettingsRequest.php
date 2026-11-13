<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Invoicing\DTOs\InvoiceSettingsData;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;

/**
 * `PUT /settings/invoice` — Admin → Settings → Invoice details; needs settings.update. The
 * whole document: a null or empty field removes the saved value (a required one then prints
 * "[placeholder]"). Affects invoices issued after the save only (each keeps its snapshot).
 */
final class UpdateInvoiceSettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name_en' => ['nullable', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:120'],
            'address_en' => ['nullable', 'string', 'max:300'],
            'address_bn' => ['nullable', 'string', 'max:300'],
            /** As printed, e.g. `09600 123456`. */
            'hotline' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{5,32}$/'],
            'email' => ['nullable', 'string', 'email', 'max:191'],
            'website' => ['nullable', 'string', 'max:191'],
            'trade_licence' => ['nullable', 'string', 'max:64'],
            /** Business Identification Number (NBR), 9 or 13 digits; not otherwise checked. */
            'bin' => ['nullable', 'string', 'max:32', 'regex:/^[0-9-]{5,32}$/'],
            /**
             * A media library image whose renditions are ready. Drawn on invoices, supplementary
             * invoices and credit notes issued from now on; each keeps the logo file it was issued with.
             */
            'logo_media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where('kind', MediaKind::Image->value)->where('processing_status', ImageProcessingStatus::Ready->value)],
            'footer_en' => ['nullable', 'string', 'max:200'],
            'footer_bn' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function toData(): InvoiceSettingsData
    {
        $values = [];
        foreach (array_keys(CompanyDetails::FIELDS) as $field) {
            $value = $this->input($field);
            $values[$field] = match (true) {
                $field === 'logo_media_id' => $value === null ? null : (int) $value,
                is_string($value) && trim($value) !== '' => trim($value),
                default => null,
            };
        }

        return new InvoiceSettingsData($values);
    }
}
