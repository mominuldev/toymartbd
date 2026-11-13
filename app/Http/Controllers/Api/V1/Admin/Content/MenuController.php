<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Domain\Content\Actions\SaveMenu;
use App\Domain\Content\Enums\MenuLinkType;
use App\Domain\Content\Enums\MenuLocation;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Services\MenuLinks;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Content\SaveMenuRequest;
use App\Http\Resources\Admin\MenuLinkTargetResource;
use App\Http\Resources\Admin\MenuResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Menu builder (Online store → Navigation, Figma B · 27 241:45525): header mega menu, footer
 * columns, mobile menu. A save replaces the location's whole tree.
 */
final class MenuController extends ApiController
{
    /** `GET /content/menus` — all three locations; needs content.view. */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Menu::class);

        return MenuResource::collection(array_map(
            static fn (MenuLocation $l): Menu => Menu::query()->with('items')->where('location', $l->value)->first() ?? new Menu(['location' => $l]),
            MenuLocation::cases(),
        ));
    }

    /** `GET /content/menus/{location}` — needs content.view. */
    public function show(MenuLocation $location): MenuResource
    {
        $this->authorize('viewAny', Menu::class);

        return MenuResource::forLocation($location);
    }

    /** `PUT /content/menus/{location}` — replaces the tree; needs content.manage. */
    public function update(SaveMenuRequest $request, MenuLocation $location, SaveMenu $save): MenuResource
    {
        $save->handle($location, $request->isPublished(), $request->items(), $request->user()?->id);

        return MenuResource::forLocation($location);
    }

    /** `GET /content/menu-targets` — link picker search; needs content.view. */
    #[QueryParameter('type', 'What to link to.', type: "'category'|'brand'|'page'|'post'|'product'", required: true)]
    #[QueryParameter('search', 'Contains match on name/title (either locale) or slug.', type: 'string')]
    #[OpenApiResponse(400, 'Missing or unknown `type` (`bad-request`)')]
    public function targets(Request $request, MenuLinks $links): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Menu::class);

        $type = MenuLinkType::tryFrom((string) $request->query('type'));
        if ($type === null || ! $type->needsTarget()) {
            throw new BadRequestHttpException('type must be one of: category, brand, page, post, product.');
        }

        $search = $request->query('search');

        return MenuLinkTargetResource::collection($links->search($type, is_string($search) ? trim($search) : ''));
    }
}
