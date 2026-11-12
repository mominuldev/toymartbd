<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\DTOs\TemplateData;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\TemplateMeasurer;
use Illuminate\Support\Facades\DB;

/**
 * Saves an edited template and re-measures both locales server-side (P4-I18N-03), so the
 * stored segment counts always describe the stored bodies. The FormRequest has already
 * rejected any variable outside the template's allowlist.
 */
final readonly class UpdateNotificationTemplate
{
    public function __construct(private TemplateMeasurer $measurer) {}

    public function handle(NotificationTemplate $template, TemplateData $data, ?int $userId): NotificationTemplate
    {
        return DB::transaction(function () use ($template, $data, $userId): NotificationTemplate {
            $template = NotificationTemplate::query()->lockForUpdate()->findOrFail($template->id);

            $en = $this->measurer->measure($template->key, $template->channel, 'en', $data->bodyEn);
            $bn = $this->measurer->measure($template->key, $template->channel, 'bn', $data->bodyBn);
            $email = $template->channel === NotificationChannel::Email;

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
            ])->save();

            return $template;
        });
    }
}
