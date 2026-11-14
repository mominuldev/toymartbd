<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Integrations\IntegrationHub;
use App\Http\Resources\Admin\IntegrationResource;
use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin → Settings → Integrations hub (Figma B · 37 `241:50294`): every outside service with
 * a status derived from stored state. Needs settings.manage_integrations, like the credential
 * screens it links to. Read-only; nothing is cached and no secret is returned.
 */
final class IntegrationsController extends ApiController
{
    /** `GET /integrations` — `{ data: Integration[] }`, grouped by category in display order. */
    public function index(Request $request, IntegrationHub $hub): AnonymousResourceCollection
    {
        $this->authorize(Permission::SettingsManageIntegrations->value);
        $user = $request->user();

        return IntegrationResource::collection($hub->all($user instanceof User ? $user : null));
    }
}
