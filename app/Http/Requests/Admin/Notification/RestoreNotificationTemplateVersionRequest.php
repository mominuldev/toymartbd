<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /notifications/templates/{template}/versions/{version}/restore` (P7) — no body;
 * needs settings.update. Restoring writes a new version; nothing is overwritten.
 */
final class RestoreNotificationTemplateVersionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof NotificationTemplate && ($this->user()?->can('update', $template) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
