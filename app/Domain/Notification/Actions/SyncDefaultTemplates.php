<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\TemplateMeasurer;
use App\Domain\Notification\Support\TemplateCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Inserts every catalog template that has no row yet, measured. Never overwrites an
 * existing row — an admin's edit wins. Idempotent; run by migration and by
 * `php artisan toymart:notifications:sync-templates`.
 */
final readonly class SyncDefaultTemplates
{
    public function __construct(private TemplateMeasurer $measurer) {}

    /** @return int rows inserted */
    public function handle(): int
    {
        return DB::transaction(function (): int {
            $inserted = 0;

            foreach (TemplateCatalog::all() as $key => $definition) {
                foreach ($definition['channels'] as $channelValue => $copy) {
                    $channel = NotificationChannel::from($channelValue);

                    if (NotificationTemplate::for($key, $channel) !== null) {
                        continue;
                    }

                    $en = $this->measurer->measure($key, $channel, 'en', $copy['body_en']);
                    $bn = $this->measurer->measure($key, $channel, 'bn', $copy['body_bn']);

                    NotificationTemplate::query()->create([
                        'key' => $key,
                        'channel' => $channel,
                        'category' => $definition['category'],
                        'subject_en' => $copy['subject_en'] ?? null,
                        'subject_bn' => $copy['subject_bn'] ?? null,
                        'preheader_en' => $copy['preheader_en'] ?? null,
                        'preheader_bn' => $copy['preheader_bn'] ?? null,
                        'body_en' => $copy['body_en'],
                        'body_bn' => $copy['body_bn'],
                        'provider_template' => $copy['provider_template'] ?? null,
                        'is_active' => true,
                        'chars_en' => $en['chars'],
                        'chars_bn' => $bn['chars'],
                        'segments_en' => $en['segments'],
                        'segments_bn' => $bn['segments'],
                        'encoding_en' => $en['encoding'],
                        'encoding_bn' => $bn['encoding'],
                    ]);
                    $inserted++;
                }
            }

            return $inserted;
        });
    }
}
