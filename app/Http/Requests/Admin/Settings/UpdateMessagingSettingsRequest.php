<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Notification\DTOs\MessagingSettingsData;
use App\Domain\Notification\Services\MessagingSettings;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /settings/messaging` — Admin → Integrations → Messaging; needs
 * settings.manage_integrations. Non-secret fields are the whole document. A blank or
 * absent `reve_api_key` / `reve_secret_key` KEEPS the saved secret; `clear_reve_api_key`
 * / `clear_reve_secret_key` remove it (env applies again, if set). Choosing `reve` needs
 * a base URL, a sender ID and both secrets to be in place after the save.
 */
final class UpdateMessagingSettingsRequest extends ApiFormRequest
{
    /**
     * REVE's callerID. The owner's sender is NON-MASKING (2026-11-13): a number, never a brand
     * name — `880XXXXXXXXXX`, `01XXXXXXXXX` or a provider short code, 5–15 digits with an
     * optional leading `+`. Letters are refused. Not checked against rule 30: a non-masking
     * sender is often an 09-series number, not a 013–019 mobile.
     */
    public const string SENDER_ID_PATTERN = '/^\+?\d{5,15}$/';

    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sms_driver' => ['required', 'string', Rule::in(MessagingSettings::SMS_DRIVERS)],
            'reve_base_url' => ['nullable', 'string', 'max:255', 'url:https'],
            /** The REVE non-masking number: digits only (5–15), optional leading `+`. */
            'reve_sender_id' => ['nullable', 'string', 'max:16', 'regex:'.self::SENDER_ID_PATTERN],
            // Decimal string, taka per segment, ≥ 0 (no sign allowed), 2 places at most.
            'sms_cost_per_segment' => ['required', 'string', 'regex:/^\d{1,4}(?:\.\d{1,2})?$/'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'reve_api_key' => ['nullable', 'string', 'max:255'],
            'reve_secret_key' => ['nullable', 'string', 'max:255'],
            'clear_reve_api_key' => ['sometimes', 'boolean'],
            'clear_reve_secret_key' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reve_sender_id.regex' => (string) __('notifications.sender_id_numeric'),
            'reve_sender_id.max' => (string) __('notifications.sender_id_numeric'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (['reve_api_key', 'reve_secret_key'] as $field) {
                if ($this->filled($field) && $this->boolean('clear_'.$field)) {
                    $validator->errors()->add($field, 'Enter a new value or clear it, not both.');
                }
            }

            if ($this->input('sms_driver') !== 'reve') {
                return;
            }

            foreach (['reve_base_url', 'reve_sender_id'] as $field) {
                if (! $this->filled($field)) {
                    $validator->errors()->add($field, 'Required when the SMS provider is REVE.');
                }
            }

            $settings = app(MessagingSettings::class);
            foreach (['reve_api_key' => MessagingSettings::REVE_API_KEY, 'reve_secret_key' => MessagingSettings::REVE_SECRET_KEY] as $field => $key) {
                // After the save the secret is: the new value, else the saved one (unless
                // cleared), else env.
                $env = config(MessagingSettings::FALLBACKS[$key]);
                $usable = $this->filled($field)
                    || ($this->boolean('clear_'.$field) ? is_string($env) && $env !== '' : $settings->secretSource($key) !== null);
                if (! $usable) {
                    $validator->errors()->add($field, 'Required when the SMS provider is REVE.');
                }
            }
        }];
    }

    public function toData(): MessagingSettingsData
    {
        $text = fn (string $field): ?string => $this->filled($field) ? trim($this->string($field)->toString()) : null;

        return new MessagingSettingsData(
            smsDriver: $this->string('sms_driver')->toString(),
            reveBaseUrl: $text('reve_base_url'),
            reveSenderId: $text('reve_sender_id'),
            costPerSegment: $this->string('sms_cost_per_segment')->toString(),
            whatsappEnabled: $this->boolean('whatsapp_enabled'),
            // Secrets are taken verbatim apart from surrounding whitespace (a paste artefact).
            reveApiKey: $text('reve_api_key'),
            reveSecretKey: $text('reve_secret_key'),
            clearReveApiKey: $this->boolean('clear_reve_api_key'),
            clearReveSecretKey: $this->boolean('clear_reve_secret_key'),
        );
    }
}
