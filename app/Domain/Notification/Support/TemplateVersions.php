<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Models\NotificationTemplateVersion;

/**
 * Appends to `notification_template_versions` (P7). Called only from inside an Action's
 * transaction, with the template row already locked — so the next version number is
 * race-free without a lock of its own.
 */
final class TemplateVersions
{
    /** The copy fields a version captures; a change to any of them is a new version. */
    public const array FIELDS = ['subject_en', 'subject_bn', 'preheader_en', 'preheader_bn', 'body_en', 'body_bn'];

    /** Records the template's current copy as the next version. */
    public static function append(NotificationTemplate $template, ?int $userId, ?int $restoredFrom = null): NotificationTemplateVersion
    {
        $next = (int) NotificationTemplateVersion::query()->where('notification_template_id', $template->id)->max('version') + 1;

        $copy = [];
        foreach (self::FIELDS as $field) {
            $copy[$field] = $template->getAttribute($field);
        }

        return NotificationTemplateVersion::query()->create([
            'notification_template_id' => $template->id,
            'version' => $next,
            ...$copy,
            'restored_from' => $restoredFrom,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * Before the first change to a template that has no history yet, keep the copy it is
     * about to replace (the catalog default) as version 1, so it can be restored.
     */
    public static function ensureBaseline(NotificationTemplate $template): void
    {
        if (! NotificationTemplateVersion::query()->where('notification_template_id', $template->id)->exists()) {
            self::append($template, $template->updated_by);
        }
    }
}
