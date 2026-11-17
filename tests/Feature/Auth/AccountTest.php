<?php

declare(strict_types=1);

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * My account (B · 40): the signed-in staff member's own name, email and password. Every
 * role may change their own; nobody can change someone else's here.
 */

const ACCOUNT_PROFILE = '/api/v1/admin/auth/profile';
const ACCOUNT_PASSWORD = '/api/v1/admin/auth/password';
const ACCOUNT_SECRET = 'correct-horse-battery';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withHeader('Referer', config('app.url').'/admin');
});

function accountStaff(Role $role = Role::OrderOperator, string $email = 'shila@toymartbd.test'): User
{
    return User::factory()->withRole($role)->create(['name' => 'Shila Akter', 'email' => $email, 'password' => ACCOUNT_SECRET])->refresh();
}

it('lets any role rename themselves without a password', function (Role $role): void {
    $user = accountStaff($role);
    $this->actingAs($user, 'web');

    $this->putJson(ACCOUNT_PROFILE, ['name' => '  শিলা আক্তার ', 'email' => 'shila@toymartbd.test'])
        ->assertOk()
        ->assertJsonPath('name', 'শিলা আক্তার')
        ->assertJsonPath('email', 'shila@toymartbd.test');

    expect(AuditSensitive::query()->where('action', AuditAction::CredentialChanged)->count())->toBe(0);
})->with(['operator' => [Role::OrderOperator], 'viewer' => [Role::Viewer], 'owner' => [Role::SuperAdmin]]);

it('needs the current password to change the sign-in email, and audits it without the value', function (): void {
    $user = accountStaff();
    $this->actingAs($user, 'web');

    $this->putJson(ACCOUNT_PROFILE, ['name' => 'Shila Akter', 'email' => 'new@toymartbd.test'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'current_password');
    $this->putJson(ACCOUNT_PROFILE, ['name' => 'Shila Akter', 'email' => 'new@toymartbd.test', 'current_password' => 'wrong-password-1'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'current_password');

    $this->putJson(ACCOUNT_PROFILE, ['name' => 'Shila Akter', 'email' => 'New@ToyMartBD.test', 'current_password' => ACCOUNT_SECRET])
        ->assertOk()->assertJsonPath('email', 'new@toymartbd.test');

    $audit = AuditSensitive::query()->where('action', AuditAction::CredentialChanged)->sole();
    expect($audit->user_id)->toBe($user->id)
        ->and($audit->model_id)->toBe($user->id)
        ->and(json_encode($audit->after))->not->toContain('new@toymartbd.test');
});

it('refuses an email another staff member already uses', function (): void {
    accountStaff(Role::Viewer, 'taken@toymartbd.test');
    $user = accountStaff();
    $this->actingAs($user, 'web');

    $this->putJson(ACCOUNT_PROFILE, ['name' => 'Shila Akter', 'email' => 'taken@toymartbd.test', 'current_password' => ACCOUNT_SECRET])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'email');
});

it('changes your own password after checking the current one, revokes tokens and audits it', function (): void {
    $user = accountStaff();
    $user->createToken('old');
    $this->actingAs($user, 'web');

    $this->putJson(ACCOUNT_PASSWORD, ['current_password' => 'nope-nope-nope', 'password' => 'a-brand-new-secret', 'password_confirmation' => 'a-brand-new-secret'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'current_password');
    $this->putJson(ACCOUNT_PASSWORD, ['current_password' => ACCOUNT_SECRET, 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'password');
    $this->putJson(ACCOUNT_PASSWORD, ['current_password' => ACCOUNT_SECRET, 'password' => 'a-brand-new-secret', 'password_confirmation' => 'something-else-1'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'password');

    $this->putJson(ACCOUNT_PASSWORD, ['current_password' => ACCOUNT_SECRET, 'password' => 'a-brand-new-secret', 'password_confirmation' => 'a-brand-new-secret'])
        ->assertNoContent();

    $fresh = $user->fresh();
    expect(Hash::check('a-brand-new-secret', (string) $fresh?->password))->toBeTrue()
        ->and($fresh?->tokens()->count())->toBe(0)
        ->and(AuditSensitive::query()->where('action', AuditAction::CredentialChanged)->sole()->after)->toBe(['scope' => 'own_password', 'password' => 'changed']);
});

it('refuses both endpoints to a guest', function (): void {
    $this->putJson(ACCOUNT_PROFILE, ['name' => 'X', 'email' => 'x@toymartbd.test'])->assertUnauthorized();
    $this->putJson(ACCOUNT_PASSWORD, ['current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y'])->assertUnauthorized();
});

it('re-encodes an uploaded photo into a 256 px square, replaces the old one, and removes it', function (): void {
    Illuminate\Support\Facades\Storage::fake(App\Domain\Catalog\Services\MediaUrls::disk());
    $disk = Illuminate\Support\Facades\Storage::disk(App\Domain\Catalog\Services\MediaUrls::disk());
    $user = accountStaff();
    $this->actingAs($user, 'web');

    $first = $this->post('/api/v1/admin/auth/avatar', ['avatar' => Illuminate\Http\UploadedFile::fake()->image('me.jpg', 900, 600)], ['Accept' => 'application/json'])
        ->assertOk();
    $path = (string) $user->fresh()?->avatar_path;
    expect($first->json('avatar_url'))->toEndWith($path)
        ->and($path)->toStartWith("avatars/{$user->id}/")->toEndWith('.png');
    $disk->assertExists($path);
    expect(getimagesizefromstring((string) $disk->get($path)))->toMatchArray([0 => 256, 1 => 256]);

    $this->post('/api/v1/admin/auth/avatar', ['avatar' => Illuminate\Http\UploadedFile::fake()->image('again.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    $disk->assertMissing($path);

    $this->deleteJson('/api/v1/admin/auth/avatar')->assertOk()->assertJsonPath('avatar_url', null);
    expect($user->fresh()?->avatar_path)->toBeNull()
        ->and($disk->allFiles("avatars/{$user->id}"))->toBe([]);
});

it('refuses a file that is not a photo', function (): void {
    $user = accountStaff();
    $this->actingAs($user, 'web');

    $this->post('/api/v1/admin/auth/avatar', ['avatar' => Illuminate\Http\UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'avatar');
    $this->post('/api/v1/admin/auth/avatar', ['avatar' => Illuminate\Http\UploadedFile::fake()->image('huge.jpg')->size(6000)], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'avatar');
});

it('uses an image from the media library as the photo, leaving the library file in place', function (): void {
    Illuminate\Support\Facades\Storage::fake(App\Domain\Catalog\Services\MediaUrls::disk());
    Illuminate\Support\Facades\Storage::fake(App\Domain\Catalog\Services\MediaUrls::originalsDisk());
    $media = App\Domain\Catalog\Media\Models\Media::factory()->create();
    $originals = Illuminate\Support\Facades\Storage::disk(App\Domain\Catalog\Services\MediaUrls::originalsDisk());
    $originals->put($media->originalPath(), Tests\Support\ImageFixtures::bytes('webp', 800, 500));
    $user = accountStaff();
    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/admin/auth/avatar', ['media_id' => $media->id])->assertOk();

    $path = (string) $user->fresh()?->avatar_path;
    $disk = Illuminate\Support\Facades\Storage::disk(App\Domain\Catalog\Services\MediaUrls::disk());
    expect(getimagesizefromstring((string) $disk->get($path)))->toMatchArray([0 => 256, 1 => 256]);
    $originals->assertExists($media->originalPath());

    $this->postJson('/api/v1/admin/auth/avatar', ['media_id' => 999999])->assertStatus(422)->assertJsonPath('errors.0.field', 'media_id');
    $this->postJson('/api/v1/admin/auth/avatar', [])->assertStatus(422)->assertJsonPath('errors.0.field', 'avatar');
});
