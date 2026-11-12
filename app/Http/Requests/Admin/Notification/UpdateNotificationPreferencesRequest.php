<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationPreference;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /notifications/customers/{customer}/preferences` — one or more (channel, category)
 * switches; needs customers.update. `security` is not accepted: OTP cannot be opted out of.
 */
final class UpdateNotificationPreferencesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', NotificationPreference::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:20'],
            'preferences.*.channel' => ['required', Rule::enum(NotificationChannel::class)],
            'preferences.*.category' => ['required', Rule::in(array_map(static fn (NotificationCategory $c): string => $c->value, NotificationCategory::optional()))],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }

    /** @return list<array{channel: NotificationChannel, category: NotificationCategory, enabled: bool}> */
    public function preferences(): array
    {
        $rows = [];
        foreach ((array) $this->input('preferences', []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'channel' => NotificationChannel::from((string) $row['channel']),
                'category' => NotificationCategory::from((string) $row['category']),
                'enabled' => filter_var($row['enabled'], FILTER_VALIDATE_BOOL),
            ];
        }

        return $rows;
    }
}
