<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in customer sets or changes their password (owner decision 2026-11-20).
 *
 * - No password yet ("Set your password", required after an SMS sign-in): the session itself is
 *   the proof — it was opened with a fresh SMS code.
 * - Changing one: the current password is required; a customer who forgot it uses "Forgot
 *   password" (a fresh SMS code) instead.
 *
 * Every other session is signed out; the one making the change stays signed in.
 */
final readonly class ChangeCustomerPassword
{
    public function handle(Customer $customer, ?string $currentPassword, string $password, ?int $keepTokenId): Customer
    {
        if ($customer->hasPassword() && ($currentPassword === null || ! Hash::check($currentPassword, (string) $customer->password))) {
            throw ValidationException::withMessages(['current_password' => (string) __('account.password.current_invalid')]);
        }

        return DB::transaction(function () use ($customer, $password, $keepTokenId): Customer {
            $customer->password = $password;
            $customer->password_set_at = Carbon::now();
            $customer->save();

            $customer->tokens()->when($keepTokenId !== null, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();

            return $customer;
        });
    }
}
