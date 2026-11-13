<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Content\Models\Testimonial;
use App\Domain\Content\Services\PublishedTestimonials;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Testimonials (Phase 7): admin CRUD and reorder, the storefront read API and the PHP read
 * service the homepage testimonials block uses.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
});

/** @param  array<string, mixed>  $overrides */
function scTestimonial(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nusrat J.',
        'name_bn' => 'নুসরাত জে.',
        'location' => 'Dhanmondi, Dhaka',
        'location_bn' => 'ধানমন্ডি, ঢাকা',
        'quote' => 'The puzzle arrived next day.',
        'quote_bn' => 'পাজলটি পরের দিনই পৌঁছেছে।',
        'rating' => 5,
        'is_published' => true,
    ], $overrides);
}

it('creates testimonials in order and validates the rating', function (): void {
    $this->postJson('/api/v1/admin/content/testimonials', scTestimonial())->assertCreated()->assertJsonPath('position', 1);
    $this->postJson('/api/v1/admin/content/testimonials', scTestimonial(['name' => 'Rafi']))->assertCreated()->assertJsonPath('position', 2);
    $this->postJson('/api/v1/admin/content/testimonials', scTestimonial(['rating' => 6]))->assertUnprocessable()->assertJsonPath('errors.0.field', 'rating');
});

it('serves only published testimonials, in display order, both locales', function (): void {
    $media = Media::factory()->create();
    $a = $this->postJson('/api/v1/admin/content/testimonials', scTestimonial(['media_id' => $media->id]))->json('id');
    $b = $this->postJson('/api/v1/admin/content/testimonials', scTestimonial(['name' => 'Rafi', 'name_bn' => 'রাফি']))->json('id');
    $this->postJson('/api/v1/admin/content/testimonials', scTestimonial(['name' => 'Hidden', 'is_published' => false]))->assertCreated();
    $hidden = Testimonial::query()->where('name', 'Hidden')->value('id');

    $this->postJson('/api/v1/admin/content/testimonials/reorder', ['ids' => [$b, $a]])->assertUnprocessable();
    $this->postJson('/api/v1/admin/content/testimonials/reorder', ['ids' => [$b, $hidden, $a]])->assertOk()->assertJsonPath('data.*.id', [$b, $hidden, $a]);

    $token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/storefront/testimonials')
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Rafi', 'Nusrat J.'])
        ->assertJsonPath('data.0.name_bn', 'রাফি')
        ->assertJsonPath('data.1.quote_bn', 'পাজলটি পরের দিনই পৌঁছেছে।')
        ->assertJsonPath('data.1.photo.alt_bn', 'কাঠের পাজল, সামনের দিক')
        ->assertJsonPath('data.0.photo', null);

    $this->withToken($token)->getJson('/api/v1/storefront/testimonials?limit=1')->assertJsonCount(1, 'data');
    expect(app(PublishedTestimonials::class)->list()->pluck('id')->all())->toBe([$b, $a]);
});

it('updates, deletes and purges content:testimonials', function (): void {
    $id = $this->postJson('/api/v1/admin/content/testimonials', scTestimonial())->json('id');
    Queue::fake();

    $this->putJson("/api/v1/admin/content/testimonials/{$id}", scTestimonial(['quote' => 'Great shop.']))->assertOk()->assertJsonPath('quote', 'Great shop.');
    $this->deleteJson("/api/v1/admin/content/testimonials/{$id}")->assertNoContent();

    Queue::assertPushed(RevalidateStorefrontTags::class, 2);
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['content:testimonials']);
});
