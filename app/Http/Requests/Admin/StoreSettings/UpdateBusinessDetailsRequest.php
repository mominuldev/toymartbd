<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\StoreSettings;

use App\Domain\Invoicing\DTOs\BusinessDetailsData;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use App\Support\PhoneNumber;
use Closure;

/**
 * `PUT /settings/business` — Store settings › Business; needs settings.update. The whole
 * section: a null or empty field removes the saved value. Name, trade licence, BIN and
 * hotline are the same rows `PUT /settings/invoice` writes, so the rules match
 * UpdateInvoiceSettingsRequest's for those fields.
 */
final class UpdateBusinessDetailsRequest extends ApiFormRequest
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
            'trade_licence' => ['nullable', 'string', 'max:64'],
            /** Business Identification Number (NBR), 9 or 13 digits; not otherwise checked. */
            'bin' => ['nullable', 'string', 'max:32', 'regex:/^[0-9-]{5,32}$/'],
            /** As printed, e.g. `09610-123456` — a 09xxx hotline is not a mobile, so rule 30 does not apply. */
            'hotline' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{5,32}$/'],
            /** A Bangladeshi mobile, 013–019 only (rule 30); stored and returned as E.164. */
            'whatsapp' => ['nullable', 'string', 'max:32', self::mobileRule()],
        ];
    }

    public function toData(): BusinessDetailsData
    {
        $values = [];
        foreach (CompanyDetails::BUSINESS_FIELDS as $field) {
            $value = $this->input($field);
            $values[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $whatsapp = $this->input('whatsapp');
        $phone = is_string($whatsapp) && trim($whatsapp) !== '' ? PhoneNumber::parse($whatsapp) : null;

        return new BusinessDetailsData($values, $phone?->e164());
    }

    private static function mobileRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && (! is_string($value) || ! PhoneNumber::isValid($value))) {
                $fail((string) __('store_settings.whatsapp_invalid'));
            }
        };
    }
}
