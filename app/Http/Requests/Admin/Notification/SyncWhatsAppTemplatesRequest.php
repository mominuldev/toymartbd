<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /notifications/templates/whatsapp/sync` — read every template's status from the WhatsApp
 * Business Account and record it; needs settings.update, like setting the status by hand
 * (`PUT /notifications/templates/{template}/whatsapp-status`). No body.
 */
final class SyncWhatsAppTemplatesRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', NotificationTemplate::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
