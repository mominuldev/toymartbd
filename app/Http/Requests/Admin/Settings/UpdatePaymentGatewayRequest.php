<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `PUT /settings/payment-gateways/{code}` — needs settings.manage_integrations. Every member is
 * optional; send only what changes.
 *
 *     {"is_active": true, "mode": "live",
 *      "credentials": {"live": {"store_id": "…", "store_password": "…"}},
 *      "clear": {"sandbox": ["store_password"]}}
 *
 * Keys are write-only (rule 20). The clear convention matches the courier credentials:
 * - `credentials.{sandbox|live}.{key}` sets a new value; an absent, blank or null value KEEPS
 *   the stored one (so a form can send its secret inputs empty);
 * - `clear.{sandbox|live}` lists keys to REMOVE. A key both set and cleared is a 422.
 * `{key}` is one of the gateway's fields (`GET /settings/payment-gateways` lists them); any
 * other key is a 422. Switching to `live` without a complete live set, or switching the gateway
 * on without a complete set for its mode, is a 422 (checked under the row lock by the Action).
 */
final class UpdatePaymentGatewayRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->can('update', PaymentProvider::class) ?? false)) {
            return false;
        }
        $this->gateway(); // 404 for a code that is not a managed gateway

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $modes = [GatewayCredentials::SANDBOX, GatewayCredentials::LIVE];

        return [
            /** Switch the gateway on or off. On needs a complete key set for its mode. */
            'is_active' => ['sometimes', 'boolean'],
            /** Which key set the gateway uses. `live` needs a complete live set. */
            'mode' => ['sometimes', 'string', Rule::in($modes)],
            /** @var array{sandbox?: array<string, string|null>, live?: array<string, string|null>} Per mode: field key → new value. Blank or absent keeps the saved value. */
            'credentials' => ['sometimes', 'array:'.implode(',', $modes)],
            'credentials.sandbox' => ['sometimes', 'array'],
            'credentials.sandbox.*' => ['nullable', 'string', 'max:500'],
            'credentials.live' => ['sometimes', 'array'],
            'credentials.live.*' => ['nullable', 'string', 'max:500'],
            /** @var array{sandbox?: list<string>, live?: list<string>} Per mode: field keys to remove. */
            'clear' => ['sometimes', 'array:'.implode(',', $modes)],
            'clear.sandbox' => ['sometimes', 'array'],
            'clear.sandbox.*' => ['string', 'max:40'],
            'clear.live' => ['sometimes', 'array'],
            'clear.live.*' => ['string', 'max:40'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $allowed = array_column(GatewayCredentials::fields($this->gateway()), 'key');
            foreach ([GatewayCredentials::SANDBOX, GatewayCredentials::LIVE] as $mode) {
                foreach (array_keys((array) $this->input('credentials.'.$mode, [])) as $key) {
                    if (! in_array($key, $allowed, true)) {
                        $validator->errors()->add("credentials.{$mode}.{$key}", 'This gateway does not use this key.');
                    }
                }
                foreach ($this->clearKeys()[$mode] ?? [] as $key) {
                    if (! in_array($key, $allowed, true)) {
                        $validator->errors()->add("clear.{$mode}", 'This gateway does not use this key.');
                    } elseif (isset($this->values()[$mode][$key])) {
                        $validator->errors()->add("credentials.{$mode}.{$key}", 'Enter a new value or clear it, not both.');
                    }
                }
            }
        }];
    }

    public function gateway(): PaymentMethod
    {
        $method = PaymentMethod::tryFrom((string) $this->route('code'));
        if ($method === null || ! in_array($method, GatewayCredentials::methods(), true)) {
            throw new NotFoundHttpException;
        }

        return $method;
    }

    public function active(): ?bool
    {
        return $this->has('is_active') ? $this->boolean('is_active') : null;
    }

    public function mode(): ?string
    {
        return $this->filled('mode') ? $this->string('mode')->toString() : null;
    }

    /** @return array<string, array<string, string>> mode => key => value, trimmed, blanks dropped */
    public function values(): array
    {
        $out = [];
        foreach ([GatewayCredentials::SANDBOX, GatewayCredentials::LIVE] as $mode) {
            foreach ((array) $this->input('credentials.'.$mode, []) as $key => $value) {
                if (is_string($value) && trim($value) !== '') {
                    $out[$mode][(string) $key] = trim($value);
                }
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> mode => keys */
    public function clearKeys(): array
    {
        $out = [];
        foreach ([GatewayCredentials::SANDBOX, GatewayCredentials::LIVE] as $mode) {
            $keys = array_values(array_map(strval(...), (array) $this->input('clear.'.$mode, [])));
            if ($keys !== []) {
                $out[$mode] = $keys;
            }
        }

        return $out;
    }
}
