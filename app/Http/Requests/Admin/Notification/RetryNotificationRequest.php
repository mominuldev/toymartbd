<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Models\NotificationLog;
use App\Http\Requests\ApiFormRequest;

/** `POST /notifications/logs/{log}/retry` — no body; needs settings.update. */
final class RetryNotificationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $log = $this->route('log');

        return $log instanceof NotificationLog && ($this->user()?->can('retry', $log) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
