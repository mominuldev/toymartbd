<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Content;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A short-lived signed preview token for the homepage draft and the storefront URL that opens
 * it (`url` is null when STOREFRONT_URL is not configured). The storefront's preview route
 * handler checks the token with the API before it turns draft mode on; the token never grants
 * anything but a read of this placement's draft.
 *
 * @property array{token: string, expires_at: string, url: string|null} $resource
 */
#[SchemaName('CmsPreviewToken')]
final class CmsPreviewTokenResource extends ApiResource
{
    /** @return array{token: string, expires_at: string, url: string|null} */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
