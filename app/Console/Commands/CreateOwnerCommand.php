<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Staff\Actions\CreateStaff;
use App\Domain\Staff\DTOs\NewStaffData;
use App\Http\Requests\Admin\Staff\StoreStaffRequest;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an owner (super_admin) login on a fresh server (STAGING.md §4, DEPLOYMENT.md).
 *
 * `DatabaseSeeder` creates no account outside local/testing — a seeded login with a known
 * password would be a back door — so the first owner is made here. The password is only
 * ever read from a hidden prompt, never from an option, so it cannot land in shell history
 * or the process list. It runs through the same CreateStaff action as the admin's Invite
 * staff, so the role grant is audited (`role_assigned`, actor = none: the console).
 *
 * An existing email is refused, never promoted: changing someone's role belongs in
 * Staff & roles, where it is audited against the person who did it.
 */
#[Signature('toymart:owner:create {--email= : Sign-in email} {--name= : Display name}')]
#[Description('Create an owner (super_admin) admin login; the password is prompted, hidden.')]
final class CreateOwnerCommand extends Command
{
    public function handle(CreateStaff $createStaff): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('Run this in an interactive terminal: the password is only read from a hidden prompt.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) ($this->option('email') ?? $this->ask('Email'))));
        $name = trim((string) ($this->option('name') ?? $this->ask('Name')));
        $password = (string) $this->secret('Password (min '.StoreStaffRequest::PASSWORD_MIN.' characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
                'name' => ['required', 'string', 'min:2', 'max:120'],
                'password' => ['required', 'string', 'max:128', 'confirmed', Password::min(StoreStaffRequest::PASSWORD_MIN)],
            ],
            ['email.unique' => 'An account with this email already exists. Change its role in Admin → Staff & roles instead.'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $existingOwners = User::role(Role::SuperAdmin->value)->count();

        $user = $createStaff->handle(
            new NewStaffData(name: $name, email: $email, role: Role::SuperAdmin, password: $password),
            actorId: null,
        );

        $this->components->info("Owner account created for {$user->email}. Sign in at ".url('/admin').'.');

        if ($existingOwners > 0) {
            $this->components->warn('There are now '.($existingOwners + 1).' owner accounts with full access.');
        }

        return self::SUCCESS;
    }
}
