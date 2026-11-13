<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\DTOs\TemplateData;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Models\NotificationTemplateVersion;
use App\Domain\Notification\Support\TemplateRenderer;
use App\Domain\Notification\Support\TemplateVersions;
use Illuminate\Validation\ValidationException;

/**
 * Restores an earlier version of a template's copy (P7). Saved through
 * {@see UpdateNotificationTemplate} — its transaction is this use case's boundary — so it
 * is re-measured and versioned like any edit: the history only grows, and the new version
 * records which one it came from. A version naming a variable no longer on the allowlist
 * is refused (422).
 */
final readonly class RestoreTemplateVersion
{
    public function __construct(private UpdateNotificationTemplate $update) {}

    public function handle(NotificationTemplate $template, NotificationTemplateVersion $version, ?int $userId): NotificationTemplate
    {
        $allowed = $template->allowedVariables();
        foreach (TemplateVersions::FIELDS as $field) {
            $unknown = TemplateRenderer::unknownPlaceholders((string) $version->getAttribute($field), $allowed);
            if ($unknown !== []) {
                throw ValidationException::withMessages([$field => 'Unknown variable(s): {'.implode('}, {', $unknown).'}.']);
            }
        }

        return $this->update->handle($template, new TemplateData(
            bodyEn: $version->body_en,
            bodyBn: $version->body_bn,
            isActive: $template->is_active,
            subjectEn: $version->subject_en,
            subjectBn: $version->subject_bn,
            preheaderEn: $version->preheader_en,
            preheaderBn: $version->preheader_bn,
        ), $userId, $version->version);
    }
}
