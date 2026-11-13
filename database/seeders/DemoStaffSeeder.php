<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One demo login per staff role (local/testing only), so every admin screen can be seen
 * through each role's permissions and the segregation-of-duties flows have two real
 * people: the accountant enters an expense, the manager approves it.
 *
 * The super admin is DatabaseSeeder's `owner@toymart.test`; this adds the other six as
 * `<role>@toymart.test` with the factory password ("password", the same as the owner).
 * Names are invented, Bangla beside English, since `users.name` is one column.
 *
 * Idempotent on email: an existing user keeps its name and password; only its role is
 * (re)assigned.
 */
class DemoStaffSeeder extends Seeder
{
    /**
     * role value → [email, display name]
     *
     * @var array<string, array{string, string}>
     */
    public const array STAFF = [
        'manager' => ['manager@toymart.test', 'Farhana Akter · ফারহানা আক্তার'],
        'order_operator' => ['order_operator@toymart.test', 'Sabbir Hossain · সাব্বির হোসেন'],
        'inventory_staff' => ['inventory_staff@toymart.test', 'Mizanur Rahman · মিজানুর রহমান'],
        'accountant' => ['accountant@toymart.test', 'Nusrat Jahan · নুসরাত জাহান'],
        'content_editor' => ['content_editor@toymart.test', 'Tanvir Ahmed · তানভীর আহমেদ'],
        'viewer' => ['viewer@toymart.test', 'Sharmin Sultana · শারমিন সুলতানা'],
    ];

    /**
     * role value → email, for other seeders and tests.
     *
     * @var array<string, string>
     */
    public const array EMAILS = [
        'manager' => 'manager@toymart.test',
        'order_operator' => 'order_operator@toymart.test',
        'inventory_staff' => 'inventory_staff@toymart.test',
        'accountant' => 'accountant@toymart.test',
        'content_editor' => 'content_editor@toymart.test',
        'viewer' => 'viewer@toymart.test',
    ];

    public function run(): void
    {
        // A seeded login with a known password anywhere else is a back door.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        foreach (self::STAFF as $role => [$email, $name]) {
            User::query()->firstOrCreate(
                ['email' => $email],
                User::factory()->raw(['name' => $name, 'email' => $email]),
            )->syncRoles([Role::from($role)->value]);
        }
    }

    /** The demo user for a role, or null when the staff seeder has not run. */
    public static function user(Role $role): ?User
    {
        $email = self::EMAILS[$role->value] ?? null;

        return $email === null ? null : User::query()->where('email', $email)->first();
    }
}
