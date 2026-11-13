<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Support\CourierCredentialFields;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

/**
 * `PUT /settings/couriers/{provider}/credentials` — needs settings.manage_integrations.
 * `credentials` maps a key from the provider's `credential_fields` to a new value; an absent
 * or blank value KEEPS the stored one. `clear` lists keys to remove. `webhook_secret` /
 * `clear_webhook_secret` work the same way. Nothing is ever echoed back (rule 20).
 */
final class UpdateCourierCredentialsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $provider = $this->provider();

        return $provider !== null && ($this->user()?->can('updateCredentials', $provider) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** @var array<string, string|null> Key (one of the provider's `credential_fields`) → new value. Blank or absent keeps the saved value. */
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
            /** Keys to remove. */
            'clear' => ['sometimes', 'array'],
            'clear.*' => ['string', 'max:40'],
            /** The shared secret the courier sends with each webhook. Blank keeps it. */
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'clear_webhook_secret' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $fields = $this->provider() !== null ? CourierCredentialFields::for($this->provider()) : [];
            foreach (array_keys((array) $this->input('credentials', [])) as $key) {
                if (! in_array($key, $fields, true)) {
                    $validator->errors()->add('credentials.'.$key, 'This courier does not use this credential.');
                }
            }
            foreach ($this->clearKeys() as $key) {
                if (! in_array($key, $fields, true)) {
                    $validator->errors()->add('clear', 'This courier does not use this credential.');
                }
            }
            foreach ($this->values() as $key => $_) {
                if (in_array($key, $this->clearKeys(), true)) {
                    $validator->errors()->add('credentials.'.$key, 'Enter a new value or clear it, not both.');
                }
            }
            if ($this->filled('webhook_secret') && $this->boolean('clear_webhook_secret')) {
                $validator->errors()->add('webhook_secret', 'Enter a new value or clear it, not both.');
            }
        }];
    }

    /** @return array<string, string> trimmed, blanks dropped */
    public function values(): array
    {
        $values = [];
        foreach ((array) $this->input('credentials', []) as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[(string) $key] = trim($value);
            }
        }

        return $values;
    }

    /** @return list<string> */
    public function clearKeys(): array
    {
        return array_values(array_map(strval(...), (array) $this->input('clear', [])));
    }

    public function webhookSecret(): ?string
    {
        return $this->filled('webhook_secret') ? trim($this->string('webhook_secret')->toString()) : null;
    }

    public function provider(): ?CourierProvider
    {
        $provider = $this->route('provider');

        return $provider instanceof CourierProvider ? $provider : null;
    }
}
