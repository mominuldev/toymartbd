<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\WhatsAppApprovalStatus;
use App\Domain\Notification\Exceptions\WhatsAppTemplateSyncFailed;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\WhatsAppTemplateDirectory;

/**
 * "Sync from Meta" in Message templates: reads the WhatsApp Business Account's templates
 * ({@see WhatsAppTemplateDirectory}, before any transaction — rule 4) and records each local
 * WhatsApp template's Meta status through {@see SetWhatsAppApprovalStatus} — the same Action
 * (and the same `updated_by` trail) as setting it by hand.
 *
 * Matching: our `provider_template` = Meta's `name`, per language — `en`, and Bengali as `bn`
 * (Meta's supported-languages list) or `bn_BD` (the code the order notifications send with
 * today). A template needs BOTH languages: the combined status is the less sendable of the two
 * ({@see WhatsAppApprovalStatus::sendability()}), and a language Meta does not have reads as
 * `draft`. A Meta status we do not recognise leaves the template untouched.
 *
 * Counts: `matched` (local templates found on Meta), `updated` (status changed), `unknown`
 * (Meta template names no local template uses), `missing` (local templates with a provider name
 * Meta does not have). Templates without a provider name are not counted.
 */
final readonly class SyncWhatsAppTemplateStatuses
{
    /** Meta language codes per our locale. */
    private const array LANGUAGES = ['en' => ['en'], 'bn' => ['bn', 'bn_BD']];

    public function __construct(private WhatsAppTemplateDirectory $directory, private SetWhatsAppApprovalStatus $set) {}

    /**
     * @return array{matched: int, updated: int, unknown: int, missing: int, unknown_names: list<string>, missing_keys: list<string>}
     *
     * @throws WhatsAppTemplateSyncFailed
     */
    public function handle(?int $userId): array
    {
        /** @var array<string, array<string, string>> $onMeta name → language → status */
        $onMeta = [];
        foreach ($this->directory->all() as $row) {
            $onMeta[$row['name']][$row['language']] = $row['status'];
        }

        $templates = NotificationTemplate::query()
            ->where('channel', NotificationChannel::WhatsApp->value)
            ->whereNotNull('provider_template')
            ->where('provider_template', '!=', '')
            ->orderBy('id')
            ->get();

        $matched = 0;
        $updated = 0;
        $used = [];
        $missing = [];
        foreach ($templates as $template) {
            $name = (string) $template->provider_template;
            $languages = $onMeta[$name] ?? null;
            if ($languages === null) {
                $missing[] = $template->key;

                continue;
            }
            $matched++;
            $used[$name] = true;

            $status = $this->combined($languages);
            if ($status === null || $status === ($template->whatsAppStatus() ?? WhatsAppApprovalStatus::Draft)) {
                continue;
            }
            $this->set->handle($template, $status, $userId);
            $updated++;
        }

        $unknown = array_values(array_diff(array_keys($onMeta), array_keys($used)));
        sort($unknown);

        return [
            'matched' => $matched,
            'updated' => $updated,
            'unknown' => count($unknown),
            'missing' => count($missing),
            'unknown_names' => array_slice($unknown, 0, 50),
            'missing_keys' => array_slice($missing, 0, 50),
        ];
    }

    /** @param  array<string, string>  $languages  Meta language code → Meta status */
    private function combined(array $languages): ?WhatsAppApprovalStatus
    {
        $worst = null;
        foreach (self::LANGUAGES as $codes) {
            $meta = null;
            foreach ($codes as $code) {
                $meta ??= $languages[$code] ?? null;
            }
            $status = $meta === null ? WhatsAppApprovalStatus::Draft : WhatsAppApprovalStatus::fromMeta($meta);
            if ($status === null) {
                return null;
            }
            if ($worst === null || $status->sendability() < $worst->sendability()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
