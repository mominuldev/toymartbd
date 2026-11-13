<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /notifications/templates/{template}/archive` and `/unarchive` (P7) — no body;
 * needs settings.update. A system template is refused by the Action (409).
 */
final class ArchiveNotificationTemplateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof NotificationTemplate && ($this->user()?->can('archive', $template) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
