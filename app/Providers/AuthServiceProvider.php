<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Enums\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Every model has a Policy (CLAUDE.md rule 21). Registered explicitly rather
     * than left to auto-discovery, so a missing one is visible in review.
     *
     * @var array<class-string, class-string>
     */
    private const array POLICIES = [
        User::class => UserPolicy::class,
    ];

    /**
     * super_admin — and only super_admin — passes every gate and policy check
     * (SYSTEM-ARCHITECTURE security table). Returning null for everyone else defers
     * to spatie/laravel-permission and the model policies.
     * Deactivated staff never get this far: EnsureActiveStaffSession refuses them
     * on every admin route.
     */
    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Gate::before(static function (mixed $user): ?bool {
            return $user instanceof User && $user->hasRole(Role::SuperAdmin) ? true : null;
        });
    }
}
