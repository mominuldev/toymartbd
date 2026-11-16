<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\StoreSettings;

use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;

/**
 * `PUT /settings/branch-mode` — Store settings › Branches & warehouses › Branch setup; needs
 * settings.update. `multi_branch: false` runs the store as a single shop (refused while other
 * branches are active), `true` unlocks adding branches.
 */
final class UpdateBranchModeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'multi_branch' => ['required', 'boolean'],
        ];
    }

    public function multiBranch(): bool
    {
        return $this->boolean('multi_branch');
    }
}
