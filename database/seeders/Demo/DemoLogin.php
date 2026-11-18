<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo logins and the "may demo data be seeded here?" switch.
 *
 * Demo data — known logins, fake orders, inbox — is seeded in `local` and `testing`, or on a
 * deployed host only through `php artisan toymart:demo:seed` (owner request 2026-11-18: the
 * live server seeded like a developer machine), which turns `demo.live_seed` on for its own
 * process and sets the password the person typed into a hidden prompt. A seeded login with
 * the factory password on a deployed host would be a back door, so outside local/testing
 * there is no default password.
 *
 * No Faker: a deploy installs Composer with --no-dev, so UserFactory is unusable there.
 */
final class DemoLogin
{
    public static function enabled(): bool
    {
        return app()->environment('local', 'testing') || (bool) config('demo.live_seed');
    }

    /**
     * @return array{name: string, email: string, email_verified_at: \Illuminate\Support\Carbon, password: string, remember_token: string}
     */
    public static function attributes(string $name, string $email): array
    {
        $password = config('demo.password');

        return [
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make(is_string($password) && $password !== '' ? $password : 'password'),
            'remember_token' => Str::random(10),
        ];
    }
}
