<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\DTOs\CustomTemplateData;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\TemplateMeasurer;
use App\Domain\Notification\Support\TemplateVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a staff-made template (P7 Message templates) — one row per chosen channel, all
 * under one generated `custom.*` key so they group like a catalog event. The key is ASCII
 * and never changes. `category` is the opt-out category the kind maps to (marketing →
 * marketing, manual → order_updates); nothing sends these yet.
 */
final readonly class CreateCustomTemplate
{
    public function __construct(private TemplateMeasurer $measurer) {}

    /** @return list<NotificationTemplate> */
    public function handle(CustomTemplateData $data, ?int $userId): array
    {
        return DB::transaction(function () use ($data, $userId): array {
            $key = $this->uniqueKey($data->nameEn);
            $rows = [];

            foreach ($data->channels as $channel) {
                $en = $this->measurer->measure($key, $channel, 'en', $data->bodyEn, $data->variables);
                $bn = $this->measurer->measure($key, $channel, 'bn', $data->bodyBn, $data->variables);
                $email = $channel === NotificationChannel::Email;

                // forceFill: the guardable-column list is cached per process, and migrations
                // that ran before this table gained `kind`/`name_*` would make create() drop them.
                $template = new NotificationTemplate;
                $template->forceFill([
                    'key' => $key,
                    'channel' => $channel,
                    'category' => $data->kind->optOutCategory(),
                    'kind' => $data->kind,
                    'name_en' => $data->nameEn,
                    'name_bn' => $data->nameBn,
                    'custom_variables' => $data->variables,
                    'subject_en' => $email ? $data->subjectEn : null,
                    'subject_bn' => $email ? $data->subjectBn : null,
                    'body_en' => $data->bodyEn,
                    'body_bn' => $data->bodyBn,
                    'whatsapp_status' => $channel === NotificationChannel::WhatsApp ? WhatsAppApprovalStatus::Draft : null,
                    'is_active' => true,
                    'chars_en' => $en['chars'],
                    'chars_bn' => $bn['chars'],
                    'segments_en' => $en['segments'],
                    'segments_bn' => $bn['segments'],
                    'encoding_en' => $en['encoding'],
                    'encoding_bn' => $bn['encoding'],
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ])->save();
                TemplateVersions::append($template, $userId);
                $rows[] = $template;
            }

            return $rows;
        });
    }

    private function uniqueKey(string $name): string
    {
        $base = 'custom.'.(Str::substr(Str::slug($name, '_', 'bn'), 0, 48) ?: 'template');
        $key = $base;
        $n = 2;
        while (NotificationTemplate::query()->where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }
}
