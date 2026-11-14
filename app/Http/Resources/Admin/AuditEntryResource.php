<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use Illuminate\Http\Request;

/**
 * One activity-log row (Figma B · 39). `actor` is null for a system change. `kind` picks the
 * sentence the screen shows (`audit.kind.*`); `changes` are `field: before → after` chips.
 * `ip` is masked server-side and `device` is a short "Chrome · macOS": the full IP and the raw
 * User-Agent are never returned. `day` is the Dhaka calendar date the row falls on (rule 11),
 * which the feed groups by.
 *
 * @property AuditEntry $resource
 */
final class AuditEntryResource extends ApiResource
{
    /**
     * @return array{id: int, action: string, kind: string, area: string, actor: array{id: int, name: string}|null, subject: array{type: string, id: int|null, label: string|null}|null, changes: list<array{field: string, before: string|null, after: string|null}>, created_at: string, day: string, ip: string|null, device: string|null}
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;
        $audit = $entry->audit;
        $actor = $audit->user;

        return [
            'id' => $audit->id,
            /** @var string `App\Models\Enums\AuditAction` */
            'action' => $audit->action->value,
            'kind' => $entry->kind(),
            'area' => $entry->area(),
            'actor' => $actor === null ? null : ['id' => (int) $actor->getKey(), 'name' => $actor->name],
            'subject' => $entry->subject,
            'changes' => $entry->changes(),
            'created_at' => $audit->created_at->toIso8601String(),
            'day' => DhakaDate::dayOf($audit->created_at),
            'ip' => $entry->maskedIp(),
            'device' => $entry->device(),
        ];
    }
}
