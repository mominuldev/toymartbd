<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * What "Sync from Meta" found. Statuses changed went through the same Action as a manual change.
 *
 * @property array{matched: int, updated: int, unknown: int, missing: int, unknown_names: list<string>, missing_keys: list<string>} $resource
 */
#[SchemaName('WhatsAppTemplateSync')]
final class WhatsAppTemplateSyncResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var int Local WhatsApp templates whose provider name Meta has. */
            'matched' => $r['matched'],
            /** @var int Of those, how many changed status. */
            'updated' => $r['updated'],
            /** @var int Template names on Meta that no local template uses. */
            'unknown' => $r['unknown'],
            /** @var int Local templates with a provider name Meta does not have. */
            'missing' => $r['missing'],
            /** @var list<string> Up to 50 of the unknown Meta names. */
            'unknown_names' => $r['unknown_names'],
            /** @var list<string> Up to 50 local template keys missing on Meta. */
            'missing_keys' => $r['missing_keys'],
        ];
    }
}
