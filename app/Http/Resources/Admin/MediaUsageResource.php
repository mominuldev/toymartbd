<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Media\DTOs\MediaUsage;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One place a media file is used (docs/api/media-library.md `MediaUsage`). Structured,
 * so the admin composes the localised label.
 *
 * @property MediaUsage $resource
 */
#[SchemaName('MediaUsage')]
final class MediaUsageResource extends ApiResource
{
    /**
     * @return array{type: string, subject_type: string, subject_id: int, title: string, title_bn: string|null, position: int|null, is_primary: bool|null, admin_path: string}
     */
    public function toArray(Request $request): array
    {
        return self::shape($this->resource);
    }

    /**
     * @return array{type: string, subject_type: string, subject_id: int, title: string, title_bn: string|null, position: int|null, is_primary: bool|null, admin_path: string}
     */
    public static function shape(MediaUsage $usage): array
    {
        return [
            'type' => $usage->type,
            'subject_type' => $usage->subjectType,
            'subject_id' => $usage->subjectId,
            'title' => $usage->title,
            'title_bn' => $usage->titleBn,
            'position' => $usage->position,
            'is_primary' => $usage->isPrimary,
            'admin_path' => $usage->adminPath,
        ];
    }
}
