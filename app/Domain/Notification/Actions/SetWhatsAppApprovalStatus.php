<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Exceptions\TemplateLibraryViolation;
use App\Domain\Notification\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Records where a WhatsApp template stands with Meta (P7): draft → submitted → approved,
 * set by staff after they act in Meta's WhatsApp Manager. No Meta API is called — there is
 * no WhatsApp Business account yet — so this is a note staff keep, not a verified state.
 */
final class SetWhatsAppApprovalStatus
{
    public function handle(NotificationTemplate $template, WhatsAppApprovalStatus $status, ?int $userId): NotificationTemplate
    {
        if ($template->channel !== NotificationChannel::WhatsApp) {
            throw TemplateLibraryViolation::notWhatsApp();
        }

        return DB::transaction(function () use ($template, $status, $userId): NotificationTemplate {
            $template = NotificationTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $template->whatsapp_status = $status;
            $template->updated_by = $userId;
            $template->save();

            return $template;
        });
    }
}
