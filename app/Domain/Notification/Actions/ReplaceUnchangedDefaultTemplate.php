<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\TemplateMeasurer;
use App\Domain\Notification\Support\TemplateCatalog;
use App\Domain\Notification\Support\TemplateVersions;
use Illuminate\Support\Facades\DB;

/**
 * Moves a system template to its new catalog copy when the catalog changes what the message is
 * for (P8 batch 3: `customer.birthday` became the reminder before a CHILD's birthday) — but only
 * while the row still holds the previous default word for word. An admin's edit is never
 * overwritten (it is reported instead). The replaced copy is kept as a version; re-measured.
 */
final readonly class ReplaceUnchangedDefaultTemplate
{
    public function __construct(private TemplateMeasurer $measurer) {}

    /** @return bool true when the row was replaced */
    public function handle(string $key, NotificationChannel $channel, string $previousEn, string $previousBn): bool
    {
        $copy = TemplateCatalog::find($key)['channels'][$channel->value] ?? null;
        if ($copy === null) {
            return false;
        }

        return DB::transaction(function () use ($key, $channel, $previousEn, $previousBn, $copy): bool {
            $row = NotificationTemplate::query()->where('key', $key)->where('channel', $channel->value)->lockForUpdate()->first();
            if ($row === null || $row->body_en !== $previousEn || $row->body_bn !== $previousBn) {
                return false;
            }

            TemplateVersions::ensureBaseline($row);
            $en = $this->measurer->measure($key, $channel, 'en', $copy['body_en']);
            $bn = $this->measurer->measure($key, $channel, 'bn', $copy['body_bn']);
            $row->fill([
                'body_en' => $copy['body_en'],
                'body_bn' => $copy['body_bn'],
                'provider_template' => $copy['provider_template'] ?? $row->provider_template,
                'chars_en' => $en['chars'],
                'chars_bn' => $bn['chars'],
                'segments_en' => $en['segments'],
                'segments_bn' => $bn['segments'],
                'encoding_en' => $en['encoding'],
                'encoding_bn' => $bn['encoding'],
            ]);
            $row->save();
            TemplateVersions::append($row, null);

            return true;
        });
    }
}
