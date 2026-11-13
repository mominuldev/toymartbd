<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront\Content;

use App\Domain\Content\Models\Menu;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StorefrontMenusResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/** Published menus by location. Cache tag `content:menus`. */
final class MenuController extends ApiController
{
    /** `GET /menus` — `{header, footer, mobile}`; a location without a published menu is null. */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function index(): StorefrontMenusResource
    {
        return new StorefrontMenusResource(Menu::query()->with('items')->where('is_published', true)->get());
    }
}
