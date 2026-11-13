<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Tracking\Models\TrackingEvent;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;

/**
 * `POST /settings/tracking/test` — validate one synthetic event against a destination with
 * the SAVED settings (GA4's validation endpoint / Meta's test_event_code), and report what
 * the provider said; needs settings.manage_integrations.
 */
final class SendTestTrackingEventRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsManageIntegrations->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** @var 'ga4'|'meta' */
            'destination' => ['required', 'string', Rule::in(TrackingEvent::DESTINATIONS)],
        ];
    }

    public function destination(): string
    {
        return $this->string('destination')->toString();
    }
}
