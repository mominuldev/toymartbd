<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /notifications/templates/{template}/preview` — measure an unsaved body (the
 * editor's live character/segment/cost counter). Writes nothing; needs settings.view.
 */
final class PreviewNotificationTemplateRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof NotificationTemplate && ($this->user()?->can('view', $template) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body_en' => ['required', 'string', 'max:'.UpdateNotificationTemplateRequest::BODY_MAX],
            'body_bn' => ['required', 'string', 'max:'.UpdateNotificationTemplateRequest::BODY_MAX],
        ];
    }
}
