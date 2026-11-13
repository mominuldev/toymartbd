<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Exceptions\TemplateLibraryViolation;
use App\Domain\Notification\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Archives (or brings back) a staff-made template (P7). Every channel row of the key moves
 * together. A system template is bound to an order event and is never archived — it is
 * switched off instead. Nothing is ever deleted.
 */
final class ArchiveNotificationTemplate
{
    /** @return list<NotificationTemplate> */
    public function handle(NotificationTemplate $template, bool $archive, ?int $userId): array
    {
        if (! $template->isCustom()) {
            throw TemplateLibraryViolation::systemTemplate();
        }

        return DB::transaction(function () use ($template, $archive, $userId): array {
            $rows = NotificationTemplate::query()->where('key', $template->key)->orderBy('channel')->lockForUpdate()->get();

            foreach ($rows as $row) {
                $row->archived_at = $archive ? ($row->archived_at ?? now()) : null;
                $row->is_active = $archive ? false : $row->is_active;
                $row->updated_by = $userId;
                $row->save();
            }

            return $rows->all();
        });
    }
}
