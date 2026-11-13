<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Notification\Models\NotificationTemplateVersion;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One saved copy of a template (P7, append-only history). `restored_from` is the version
 * number this one was restored from, or null for an ordinary edit.
 *
 * @property NotificationTemplateVersion $resource
 */
#[SchemaName('NotificationTemplateVersion')]
final class NotificationTemplateVersionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $v = $this->resource;

        return [
            'id' => $v->id,
            'version' => $v->version,
            'subject_en' => $v->subject_en,
            'subject_bn' => $v->subject_bn,
            'preheader_en' => $v->preheader_en,
            'preheader_bn' => $v->preheader_bn,
            'body_en' => $v->body_en,
            'body_bn' => $v->body_bn,
            'restored_from' => $v->restored_from,
            /** @var string|null */
            'created_by_name' => $v->created_by === null ? null : $v->author?->name,
            /** @var string|null */
            'created_at' => $v->created_at === null ? null : DateTimeImmutable::createFromInterface($v->created_at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
        ];
    }
}
