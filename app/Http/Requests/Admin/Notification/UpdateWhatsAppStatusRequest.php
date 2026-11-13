<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Notification;

use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /notifications/templates/{template}/whatsapp-status` (P7) — staff record where a
 * WhatsApp template stands with Meta; needs settings.update. No Meta API is called.
 */
final class UpdateWhatsAppStatusRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof NotificationTemplate && ($this->user()?->can('update', $template) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** @var 'draft'|'submitted'|'approved' */
            'status' => ['required', 'string', Rule::in(WhatsAppApprovalStatus::values())],
        ];
    }

    public function status(): WhatsAppApprovalStatus
    {
        return WhatsAppApprovalStatus::from($this->string('status')->toString());
    }
}
