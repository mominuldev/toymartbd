<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base API resource (docs/SYSTEM-ARCHITECTURE.md §5 "Standard envelope").
 *
 * - A single resource is returned bare: `{ "id": 1, ... }`.
 * - `Resource::collection($paginator)` returns `{ data, links, meta }`.
 *   A non-paginated collection returns `{ data }`.
 *
 * Wrapping is switched off here, for ApiResource and its subclasses only, rather
 * than through the global JsonResource::withoutWrapping(): collections are
 * AnonymousResourceCollection instances, which read JsonResource::$wrap and so
 * keep the `data` key.
 */
abstract class ApiResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;
}
