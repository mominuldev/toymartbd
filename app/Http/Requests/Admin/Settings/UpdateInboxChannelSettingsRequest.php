<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Inbox\DTOs\InboxChannelCredentials;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /settings/inbox-channels` — the WhatsApp and Messenger inbox credentials on Admin →
 * Integrations → Messaging; needs settings.manage_integrations. Driver and account id are the
 * whole document (a blank id removes the saved one, so env applies again). A blank or absent
 * secret KEEPS the saved one; `clear_{field}` removes it. Choosing the Meta driver (`cloud` /
 * `messenger`) needs the account id and the send token to be in place after the save.
 */
final class UpdateInboxChannelSettingsRequest extends ApiFormRequest
{
    /** Meta's phone number id and Page id are numeric strings. */
    private const string ACCOUNT_ID_PATTERN = '/^\d{1,32}$/';

    /** Form field → [channel, InboxChannelSettings name], for every secret. */
    private const array SECRET_FIELDS = [
        'whatsapp_access_token' => ['whatsapp', 'access_token'],
        'whatsapp_app_secret' => ['whatsapp', 'app_secret'],
        'whatsapp_verify_token' => ['whatsapp', 'verify_token'],
        'messenger_page_access_token' => ['messenger', 'page_access_token'],
        'messenger_app_secret' => ['messenger', 'app_secret'],
        'messenger_verify_token' => ['messenger', 'verify_token'],
    ];

    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** `log` sends nothing; `cloud` is the WhatsApp Cloud API driver (unverified). */
            'whatsapp_driver' => ['required', 'string', Rule::in(InboxChannelSettings::DRIVERS['whatsapp'])],
            /** WhatsApp Manager → API setup → "Phone number ID". Digits only. */
            'whatsapp_phone_number_id' => ['nullable', 'string', 'regex:'.self::ACCOUNT_ID_PATTERN],
            'whatsapp_access_token' => ['nullable', 'string', 'max:2048'],
            'whatsapp_app_secret' => ['nullable', 'string', 'max:255'],
            'whatsapp_verify_token' => ['nullable', 'string', 'max:255'],
            'clear_whatsapp_access_token' => ['sometimes', 'boolean'],
            'clear_whatsapp_app_secret' => ['sometimes', 'boolean'],
            'clear_whatsapp_verify_token' => ['sometimes', 'boolean'],
            /** `log` sends nothing; `messenger` is the Messenger Send API driver (unverified). */
            'messenger_driver' => ['required', 'string', Rule::in(InboxChannelSettings::DRIVERS['messenger'])],
            /** The Facebook Page id. Digits only. */
            'messenger_page_id' => ['nullable', 'string', 'regex:'.self::ACCOUNT_ID_PATTERN],
            'messenger_page_access_token' => ['nullable', 'string', 'max:2048'],
            'messenger_app_secret' => ['nullable', 'string', 'max:255'],
            'messenger_verify_token' => ['nullable', 'string', 'max:255'],
            'clear_messenger_page_access_token' => ['sometimes', 'boolean'],
            'clear_messenger_app_secret' => ['sometimes', 'boolean'],
            'clear_messenger_verify_token' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'whatsapp_phone_number_id.regex' => 'Digits only — the Phone number ID from WhatsApp Manager.',
            'messenger_page_id.regex' => 'Digits only — the Facebook Page ID.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (array_keys(self::SECRET_FIELDS) as $field) {
                if ($this->filled($field) && $this->boolean('clear_'.$field)) {
                    $validator->errors()->add($field, 'Enter a new value or clear it, not both.');
                }
            }

            $settings = app(InboxChannelSettings::class);
            foreach ([InboxChannel::WhatsApp, InboxChannel::Messenger] as $channel) {
                $c = $channel->value;
                $metaDriver = InboxChannelSettings::DRIVERS[$c][1];
                if ($this->input($c.'_driver') !== $metaDriver) {
                    continue;
                }

                // After the save the account id is the submitted one, else env.
                $idField = $c.'_'.InboxChannelSettings::ACCOUNT_ID[$c];
                if (! $this->filled($idField) && $settings->fallback($channel, InboxChannelSettings::ACCOUNT_ID[$c]) === null) {
                    $validator->errors()->add($idField, 'Required to send through Meta.');
                }

                // After the save the token is: the new value, else the saved one (unless
                // cleared), else env.
                $tokenName = InboxChannelSettings::SEND_TOKEN[$c];
                $tokenField = $c.'_'.$tokenName;
                $usable = $this->filled($tokenField)
                    || ($this->boolean('clear_'.$tokenField)
                        ? $settings->fallback($channel, $tokenName) !== null
                        : $settings->source($channel, $tokenName) !== null);
                if (! $usable) {
                    $validator->errors()->add($tokenField, 'Required to send through Meta.');
                }
            }
        }];
    }

    /** @return list<InboxChannelCredentials> */
    public function toData(): array
    {
        $text = fn (string $field): ?string => $this->filled($field) ? trim($this->string($field)->toString()) : null;

        $out = [];
        foreach ([InboxChannel::WhatsApp, InboxChannel::Messenger] as $channel) {
            $c = $channel->value;
            $secrets = [];
            $clear = [];
            foreach (self::SECRET_FIELDS as $field => [$fieldChannel, $name]) {
                if ($fieldChannel !== $c) {
                    continue;
                }
                if ($this->boolean('clear_'.$field)) {
                    $clear[] = $name;
                } elseif (($value = $text($field)) !== null) {
                    // Taken verbatim apart from surrounding whitespace (a paste artefact).
                    $secrets[$name] = $value;
                }
            }

            $out[] = new InboxChannelCredentials(
                channel: $channel,
                driver: $this->string($c.'_driver')->toString(),
                accountId: $text($c.'_'.InboxChannelSettings::ACCOUNT_ID[$c]),
                secrets: $secrets,
                clear: $clear,
            );
        }

        return $out;
    }
}
