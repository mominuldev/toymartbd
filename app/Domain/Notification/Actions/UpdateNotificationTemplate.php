<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\DTOs\TemplateData;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Exceptions\TemplateLibraryViolation;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\TemplateMeasurer;
use App\Domain\Notification\Support\TemplateVersions;
use Illuminate\Support\Facades\DB;

/**
 * Saves an edited template and re-measures both locales server-side (P4-I18N-03), so the
 * stored segment counts always describe the stored bodies. The FormRequest has already
 * rejected any variable outside the template's allowlist.
 *
 * Every change to the copy is versioned (append-only `notification_template_versions`,
 * P7); the first change also keeps the copy it replaced. Editing the body of a submitted
 * or approved WhatsApp template drops it back to draft — Meta must approve it again.
 */
final readonly class UpdateNotificationTemplate
{
    public function __construct(private TemplateMeasurer $measurer) {}

    /** `$restoredFrom` — the version number being restored, recorded on the new version (P7). */
    public function handle(NotificationTemplate $template, TemplateData $data, ?int $userId, ?int $restoredFrom = null): NotificationTemplate
    {
        return DB::transaction(function () use ($template, $data, $userId, $restoredFrom): NotificationTemplate {
            $template = NotificationTemplate::query()->lockForUpdate()->findOrFail($template->id);

            if ($template->archived_at !== null) {
                throw TemplateLibraryViolation::archived();
            }

            $allowed = $template->isCustom() ? $template->allowedVariables() : null;
            $en = $this->measurer->measure($template->key, $template->channel, 'en', $data->bodyEn, $allowed);
            $bn = $this->measurer->measure($template->key, $template->channel, 'bn', $data->bodyBn, $allowed);
            $email = $template->channel === NotificationChannel::Email;

            // Version history (P7): keep the copy being replaced if nothing was kept yet.
            $copyBefore = $template->only(TemplateVersions::FIELDS);
            $nextCopy = [
                'body_en' => $data->bodyEn,
                'body_bn' => $data->bodyBn,
                'subject_en' => $email ? $data->subjectEn : null,
                'subject_bn' => $email ? $data->subjectBn : null,
                'preheader_en' => $email ? $data->preheaderEn : null,
                'preheader_bn' => $email ? $data->preheaderBn : null,
            ];
            $copyChanged = array_diff_assoc(array_map('strval', $nextCopy), array_map('strval', $copyBefore)) !== [];
            if ($copyChanged) {
                TemplateVersions::ensureBaseline($template);
            }

            $template->fill([
                'body_en' => $data->bodyEn,
                'body_bn' => $data->bodyBn,
                'is_active' => $data->isActive,
                'subject_en' => $email ? $data->subjectEn : null,
                'subject_bn' => $email ? $data->subjectBn : null,
                'preheader_en' => $email ? $data->preheaderEn : null,
                'preheader_bn' => $email ? $data->preheaderBn : null,
                'chars_en' => $en['chars'],
                'chars_bn' => $bn['chars'],
                'segments_en' => $en['segments'],
                'segments_bn' => $bn['segments'],
                'encoding_en' => $en['encoding'],
                'encoding_bn' => $bn['encoding'],
                'updated_by' => $userId,
            ]);

            if ($template->isCustom()) {
                $template->name_en = $data->nameEn ?? $template->name_en;
                $template->name_bn = $data->nameBn ?? $template->name_bn;
            }

            // The copy Meta approved is no longer the stored copy: back to draft until resubmitted.
            $bodyChanged = $template->isDirty('body_en') || $template->isDirty('body_bn');
            if ($bodyChanged && in_array($template->whatsAppStatus(), [WhatsAppApprovalStatus::Submitted, WhatsAppApprovalStatus::Approved], true)) {
                $template->whatsapp_status = WhatsAppApprovalStatus::Draft;
            }

            $template->save();

            if ($copyChanged) {
                TemplateVersions::append($template, $userId, $restoredFrom);
            }

            return $template;
        });
    }
}
