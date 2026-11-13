<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;

/**
 * `PUT /notifications/automations` — Settings → Automations scheduling (P8); needs
 * settings.update. Whether each automation runs is its templates' on/off switch.
 */
final class UpdateAutomationSettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Days after delivery before the review request goes out (1–30). */
            'review_delay_days' => ['required', 'integer', 'min:1', 'max:30'],
            /** Dhaka quiet hours start, HH:MM (24 h). Nothing automated is sent inside the window. */
            'quiet_from' => ['required', 'string', 'date_format:H:i'],
            /** Dhaka quiet hours end, HH:MM (24 h); may be earlier than the start (wraps midnight). */
            'quiet_to' => ['required', 'string', 'date_format:H:i', 'different:quiet_from'],
        ];
    }

    public function delayDays(): int
    {
        return (int) $this->input('review_delay_days');
    }

    public function quietFrom(): string
    {
        return (string) $this->input('quiet_from');
    }

    public function quietTo(): string
    {
        return (string) $this->input('quiet_to');
    }
}
