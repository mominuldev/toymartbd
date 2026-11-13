<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

/**
 * The 403 role matrix (CLAUDE.md rule 21, P3-API-06).
 *
 * Driven by Role::permissions() — the same matrix the seeder reads — so a test
 * can never drift from the roles: add a role or move a permission and every
 * matrix test re-derives who must get 2xx and who must get 403.
 *
 * Two ways to use it:
 *
 *     // one test, every role, one line
 *     it('guards GET /staff', function () {
 *         RoleMatrix::assert($this, Permission::StaffView, fn () => $this->getJson('/api/v1/admin/staff'));
 *     });
 *
 *     // one Pest case per role, for readable failures
 *     it('guards GET /staff', function (Role $role, bool $allowed) {
 *         RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/staff'));
 *     })->with(RoleMatrix::dataset(Permission::StaffView));
 *
 * Pass `null` as the permission for "any active staff member".
 *
 * Every route a matrix test reaches is recorded (RoleMatrixCoverage), and
 * tests/Arch/AdminRouteAuthorisationTest.php fails when an admin route has
 * no matrix test — see that file for how to add one.
 */
final class RoleMatrix
{
    public static function allows(Role $role, ?Permission $permission): bool
    {
        return $permission === null || in_array($permission, $role->permissions(), true);
    }

    /**
     * @return array<string, array{Role, bool}>
     */
    public static function dataset(?Permission $permission): array
    {
        $rows = [];

        foreach (Role::cases() as $role) {
            $allowed = self::allows($role, $permission);
            $rows[$role->value.($allowed ? ' is allowed' : ' is forbidden')] = [$role, $allowed];
        }

        return $rows;
    }

    /**
     * Sends the request once per role and asserts each outcome.
     *
     * @param  Closure(): TestResponse  $request
     */
    public static function assert(TestCase $test, ?Permission $permission, Closure $request, int $allowedStatus = 200): void
    {
        foreach (Role::cases() as $role) {
            self::assertFor($test, $role, self::allows($role, $permission), $request, $allowedStatus);
        }
    }

    /**
     * @param  Closure(): TestResponse  $request
     */
    public static function assertFor(TestCase $test, Role $role, bool $allowed, Closure $request, int $allowedStatus = 200): void
    {
        self::seedRoles();

        // refresh(): load DB defaults (is_active) exactly as a real request would.
        $user = User::factory()->withRole($role)->create()->refresh();

        // Guards cache the resolved user; start each role from a clean slate.
        auth()->forgetGuards();
        $test->actingAs($user, 'web');

        $response = $request();

        RoleMatrixCoverage::record($response);

        if ($allowed) {
            $response->assertStatus($allowedStatus);

            return;
        }

        self::assertForbidden($response, "{$role->value} must be forbidden");
    }

    public static function assertForbidden(TestResponse $response, string $message = ''): void
    {
        RoleMatrixCoverage::record($response);

        $status = $response->getStatusCode();

        \PHPUnit\Framework\Assert::assertSame(403, $status, trim("{$message} (got {$status})"));
        $response->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://toymartbd.com/problems/forbidden')
            ->assertJsonPath('status', 403);
    }

    private static function seedRoles(): void
    {
        if (! RoleModel::query()->exists()) {
            (new RolesAndPermissionsSeeder)->run();
        }
    }
}
