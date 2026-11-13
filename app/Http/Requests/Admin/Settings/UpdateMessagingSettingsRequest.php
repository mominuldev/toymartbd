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
     * REVE's callerID: a masked alphanumeric sender of at most 11 characters (the GSM limit),
     * or a numeric (non-masked) sender. NOT confirmed against a REVE account yet.
     */
    public const string SENDER_ID_PATTERN = '/^(?:[A-Za-z0-9][A-Za-z0-9 .\-]{0,10}|\+?\d{5,15})$/';

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
            'reve_sender_id' => ['nullable', 'string', 'max:15', 'regex:'.self::SENDER_ID_PATTERN],
            // Decimal string, taka per segment, ≥ 0 (no sign allowed), 2 places at most.
            'sms_cost_per_segment' => ['required', 'string', 'regex:/^\d{1,4}(?:\.\d{1,2})?$/'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'reve_api_key' => ['nullable', 'string', 'max:255'],
            'reve_secret_key' => ['nullable', 'string', 'max:255'],
            'clear_reve_api_key' => ['sometimes', 'boolean'],
            'clear_reve_secret_key' => ['sometimes', 'boolean'],
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
