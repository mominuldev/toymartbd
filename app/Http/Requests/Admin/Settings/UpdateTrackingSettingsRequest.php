<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Tracking\DTOs\TrackingSettingsData;
use App\Domain\Tracking\Enums\PurchaseTrigger;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /settings/tracking` — Admin → Integrations → Tracking & pixels; needs
 * settings.manage_integrations (super_admin and manager — owner decision 2026-11-13).
 *
 * Non-secret fields are the whole document. A blank or absent `ga4_api_secret` /
 * `meta_access_token` KEEPS the saved secret (write-only, like Messaging); `clear_*`
 * removes it (env applies again, if set). Turning a destination ON needs its id and its
 * secret to be in place after the save, otherwise the switch would promise sends that the
 * dispatcher silently skips.
 */
final class UpdateTrackingSettingsRequest extends ApiFormRequest
{
    /** GA4 web data-stream measurement id, e.g. `G-TMB8X2K4QZ`. */
    public const string GA4_ID_PATTERN = '/^G-[A-Z0-9]{4,20}$/';

    /** Meta dataset (pixel) id: digits only. */
    public const string PIXEL_ID_PATTERN = '/^\d{5,20}$/';

    /** GTM web container id, e.g. `GTM-W7K3PL9`. */
    public const string GTM_ID_PATTERN = '/^GTM-[A-Z0-9]{4,12}$/';

    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Master switch for every server-side send (GA4 Measurement Protocol + Meta Conversions API). */
            'server_side_enabled' => ['required', 'boolean'],
            'ga4_enabled' => ['required', 'boolean'],
            /** GA4 measurement id, `G-` + 4–20 capitals/digits. */
            'ga4_measurement_id' => ['nullable', 'string', 'max:24', 'regex:'.self::GA4_ID_PATTERN],
            /** Write-only. Blank or absent keeps the saved secret. */
            'ga4_api_secret' => ['nullable', 'string', 'max:255'],
            'clear_ga4_api_secret' => ['sometimes', 'boolean'],
            'meta_enabled' => ['required', 'boolean'],
            /** Meta pixel (dataset) id, digits only. */
            'meta_pixel_id' => ['nullable', 'string', 'max:20', 'regex:'.self::PIXEL_ID_PATTERN],
            /** Write-only. Blank or absent keeps the saved token. */
            'meta_access_token' => ['nullable', 'string', 'max:1024'],
            'clear_meta_access_token' => ['sometimes', 'boolean'],
            /** Meta Events Manager "Test events" code. While set, server events go to Test events only. */
            'meta_test_event_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            /** GTM web container id, `GTM-` + 4–12 capitals/digits. */
            'gtm_container_id' => ['nullable', 'string', 'max:16', 'regex:'.self::GTM_ID_PATTERN],
            'purchase_trigger' => ['required', 'string', Rule::enum(PurchaseTrigger::class)],
            'consent_mode' => ['required', 'string', Rule::in(TrackingSettings::CONSENT_MODES)],
            /** Bump when the cookie/tracking notice changes so visitors are asked again. */
            'consent_policy_version' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Ids are pasted from provider consoles: tolerate case and stray spaces, never inner ones.
        foreach (['ga4_measurement_id', 'gtm_container_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->string($field)->toString()))]);
            }
        }
        foreach (['meta_pixel_id', 'meta_test_event_code', 'consent_policy_version'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->string($field)->toString())]);
            }
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (['ga4_api_secret', 'meta_access_token'] as $field) {
                if ($this->filled($field) && $this->boolean('clear_'.$field)) {
                    $validator->errors()->add($field, 'Enter a new value or clear it, not both.');
                }
            }

            $settings = app(TrackingSettings::class);
            $checks = [
                'ga4' => ['ga4_measurement_id', 'ga4_api_secret', TrackingSettings::GA4_API_SECRET],
                'meta' => ['meta_pixel_id', 'meta_access_token', TrackingSettings::META_ACCESS_TOKEN],
            ];
            foreach ($checks as $destination => [$idField, $secretField, $secretKey]) {
                if (! $this->boolean($destination.'_enabled')) {
                    continue;
                }
                if (! $this->filled($idField)) {
                    $validator->errors()->add($idField, 'Required to turn this destination on.');
                }
                // After the save the secret is: the new value, else the saved one (unless
                // cleared), else env.
                $env = config(TrackingSettings::FALLBACKS[$secretKey]);
                $usable = $this->filled($secretField)
                    || ($this->boolean('clear_'.$secretField) ? is_string($env) && $env !== '' : $settings->secretSource($secretKey) !== null);
                if (! $usable) {
                    $validator->errors()->add($secretField, 'Required to turn this destination on.');
                }
            }
        }];
    }

    public function toData(): TrackingSettingsData
    {
        $text = fn (string $field): ?string => $this->filled($field) ? trim($this->string($field)->toString()) : null;

        return new TrackingSettingsData(
            serverSideEnabled: $this->boolean('server_side_enabled'),
            ga4Enabled: $this->boolean('ga4_enabled'),
            ga4MeasurementId: $text('ga4_measurement_id'),
            metaEnabled: $this->boolean('meta_enabled'),
            metaPixelId: $text('meta_pixel_id'),
            metaTestEventCode: $text('meta_test_event_code'),
            gtmContainerId: $text('gtm_container_id'),
            purchaseTrigger: PurchaseTrigger::from($this->string('purchase_trigger')->toString()),
            consentMode: $this->string('consent_mode')->toString(),
            consentPolicyVersion: $text('consent_policy_version'),
            // Secrets are taken verbatim apart from surrounding whitespace (a paste artefact).
            ga4ApiSecret: $text('ga4_api_secret'),
            clearGa4ApiSecret: $this->boolean('clear_ga4_api_secret'),
            metaAccessToken: $text('meta_access_token'),
            clearMetaAccessToken: $this->boolean('clear_meta_access_token'),
        );
    }
}
