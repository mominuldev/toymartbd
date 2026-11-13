<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\Enums\ReviewStatus;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Domain\Catalog\Reviews\Services\RatingAggregates;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RoleMatrix;

/*
 * Reviews (P4-CAT-12): submission (storefront account), moderation (admin) and the
 * product page read. `is_verified_purchase` is derived from a delivered order line of
 * the reviewer's own order; the product's rating counts approved reviews only.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

    $this->product = Product::factory()->priced('1200.00')->create(['name' => 'Wooden Train', 'name_bn' => 'কাঠের ট্রেন']);
    $this->customer = Customer::factory()->create(['name' => 'নুসরাত জাহান']);
});

function deliveredLine(Customer $customer, Product $product, OrderStatus $status = OrderStatus::Delivered): OrderItem
{
    $order = Order::factory()->status($status)->create(['customer_id' => $customer->id]);

    return OrderItem::factory()->create([
        'order_id' => $order->id,
        'variant_id' => $product->variants()->firstOrFail()->id,
        'product_id' => $product->id,
    ]);
}

/** The stored average as DECIMAL(3,2) text (SQLite hands back a number). */
function ratingAvg(Product $product): ?string
{
    return $product->rating_avg === null ? null : number_format((float) $product->rating_avg, 2, '.', '');
}

function submitReview(Customer $customer, array $payload): Illuminate\Testing\TestResponse
{
    Sanctum::actingAs($customer, ['customer'], 'customer');

    return test()->postJson('/api/v1/storefront/account/reviews', $payload);
}

function storefrontRead(string $uri): Illuminate\Testing\TestResponse
{
    $token = User::factory()->create()->createToken('storefront', ['storefront:read'])->plainTextToken;
    auth()->forgetGuards();

    return test()->withToken($token)->getJson($uri);
}

it('stores a review as pending and unverified when the customer has no delivered purchase', function (): void {
    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 5, 'title' => 'দারুণ', 'body' => 'আমার ছেলে খুব পছন্দ করেছে, মান ভালো।'])
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('is_verified_purchase', false)
        ->assertJsonPath('reviewer_name', 'নুসরাত জ.');

    expect($this->product->refresh()->rating_count)->toBe(0)->and($this->product->rating_avg)->toBeNull();
});

it('never lets an unverified review claim verified status', function (): void {
    // Sending the flag is refused outright.
    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 5, 'body' => 'Best toy ever, trust me.', 'is_verified_purchase' => true])
        ->assertStatus(422);

    // Someone else's delivered line cannot be borrowed…
    $other = Customer::factory()->create();
    $othersLine = deliveredLine($other, $this->product);
    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 5, 'body' => 'Best toy ever, trust me.', 'order_item_id' => $othersLine->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'order_item_id');

    // …nor an own line that was never delivered…
    $undelivered = deliveredLine($this->customer, $this->product, OrderStatus::Shipped);
    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 5, 'body' => 'Best toy ever, trust me.', 'order_item_id' => $undelivered->id])
        ->assertStatus(422);

    // …nor an own delivered line of a different product.
    $otherProduct = Product::factory()->priced('300.00')->create();
    $wrongProduct = deliveredLine($this->customer, $otherProduct);
    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 5, 'body' => 'Best toy ever, trust me.', 'order_item_id' => $wrongProduct->id])
        ->assertStatus(422);

    expect(Review::query()->count())->toBe(0);

    // Without a delivered purchase the review is stored, unverified.
    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 5, 'body' => 'Best toy ever, trust me.'])
        ->assertCreated()->assertJsonPath('is_verified_purchase', false);
    expect(Review::query()->sole()->order_item_id)->toBeNull();
});

it('marks a review verified from the customer\'s own delivered order line', function (): void {
    $line = deliveredLine($this->customer, $this->product, OrderStatus::Completed);

    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 4, 'body' => 'Sturdy and well finished.', 'order_item_id' => $line->id])
        ->assertCreated()->assertJsonPath('is_verified_purchase', true);

    expect(Review::query()->sole()->order_item_id)->toBe($line->id);
});

it('links a delivered line automatically when none is given, and refuses a second review of the same product', function (): void {
    $line = deliveredLine($this->customer, $this->product);

    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 4, 'body' => 'Sturdy and well finished.'])
        ->assertCreated()->assertJsonPath('is_verified_purchase', true);
    expect(Review::query()->sole()->order_item_id)->toBe($line->id);

    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 1, 'body' => 'Changed my mind about it.'])
        ->assertStatus(409);
});

it('does not verify a line that was returned in full', function (): void {
    $line = deliveredLine($this->customer, $this->product, OrderStatus::PartiallyReturned);
    $line->forceFill(['quantity_returned' => $line->quantity])->saveQuietly();

    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 2, 'body' => 'Returned it, not for us.'])
        ->assertCreated()->assertJsonPath('is_verified_purchase', false);
});

it('needs a signed-in customer and a live product', function (): void {
    $this->postJson('/api/v1/storefront/account/reviews', ['product_id' => $this->product->id, 'rating' => 5, 'body' => 'Lovely toy for kids.'])
        ->assertUnauthorized();

    $draft = Product::factory()->draft()->create();
    submitReview($this->customer, ['product_id' => $draft->id, 'rating' => 5, 'body' => 'Lovely toy for kids.'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'product_id');

    submitReview($this->customer, ['product_id' => $this->product->id, 'rating' => 6, 'body' => 'Lovely toy for kids.'])
        ->assertStatus(422);
});

it('recomputes the rating aggregates on approve and reject', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');

    $five = Review::factory()->rating(5)->create(['product_id' => $this->product->id]);
    $four = Review::factory()->rating(4)->create(['product_id' => $this->product->id]);
    $two = Review::factory()->rating(2)->create(['product_id' => $this->product->id]);

    $this->postJson("/api/v1/admin/reviews/{$five->id}/approve")->assertOk()->assertJsonPath('status', 'approved');
    expect($this->product->refresh()->rating_count)->toBe(1)->and(ratingAvg($this->product))->toBe('5.00');

    $this->postJson("/api/v1/admin/reviews/{$four->id}/approve")->assertOk();
    $this->postJson("/api/v1/admin/reviews/{$two->id}/approve")->assertOk();
    expect($this->product->refresh()->rating_count)->toBe(3)->and(ratingAvg($this->product))->toBe('3.67');

    // Approving again changes nothing.
    $this->postJson("/api/v1/admin/reviews/{$two->id}/approve")->assertOk();
    expect($this->product->refresh()->rating_count)->toBe(3);

    // Rejecting an approved review takes it out of the aggregate.
    $this->postJson("/api/v1/admin/reviews/{$two->id}/reject")->assertOk()->assertJsonPath('status', 'rejected');
    expect($this->product->refresh()->rating_count)->toBe(2)->and(ratingAvg($this->product))->toBe('4.50');

    $this->postJson("/api/v1/admin/reviews/{$five->id}/reject")->assertOk();
    $this->postJson("/api/v1/admin/reviews/{$four->id}/reject")->assertOk();
    expect($this->product->refresh()->rating_count)->toBe(0)->and($this->product->rating_avg)->toBeNull();

    // A pending review never counts.
    Review::factory()->rating(1)->create(['product_id' => $this->product->id]);
    app(RatingAggregates::class)->recompute($this->product->id);
    expect($this->product->refresh()->rating_count)->toBe(0);

    // The moderator is recorded.
    expect(Review::query()->findOrFail($five->id)->moderated_by)->not->toBeNull();
});

it('rounds the average half-up to two places', function (): void {
    expect(RatingAggregates::average(14, 3))->toBe('4.67')
        ->and(RatingAggregates::average(13, 3))->toBe('4.33')
        ->and(RatingAggregates::average(9, 2))->toBe('4.50')
        ->and(RatingAggregates::average(5, 1))->toBe('5.00');
});

it('lists the moderation queue with status counts, filters and replies', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');

    Review::factory()->count(2)->create(['product_id' => $this->product->id]);
    $approved = Review::factory()->approved()->create(['product_id' => $this->product->id, 'is_verified_purchase' => true, 'body' => 'খুব সুন্দর খেলনা']);

    $this->getJson('/api/v1/admin/reviews?filter[status]=pending')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.counts', ['pending' => 2, 'approved' => 1, 'rejected' => 0]);

    $this->getJson('/api/v1/admin/reviews?filter[verified]=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'খুব সুন্দর খেলনা');
    $this->getJson('/api/v1/admin/reviews?filter[colour]=red')->assertStatus(400);
    $this->getJson('/api/v1/admin/reviews?filter[status]=spam')->assertStatus(400);

    $this->putJson("/api/v1/admin/reviews/{$approved->id}/reply", ['reply' => 'ধন্যবাদ!'])->assertOk()->assertJsonPath('admin_reply', 'ধন্যবাদ!');
    $this->putJson("/api/v1/admin/reviews/{$approved->id}/reply", ['reply' => ''])->assertOk()->assertJsonPath('admin_reply', null);

    // The customer's phone is masked (rule 20).
    $phone = $this->getJson("/api/v1/admin/reviews/{$approved->id}")->assertOk()->json('customer.phone');
    expect($phone)->not->toBe($approved->customer?->phone);
});

it('serves approved reviews and a rating summary to the product page, cursor-paginated', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $moderator = User::factory()->withRole(Role::Manager)->create();

    $reviews = Review::factory()->count(12)->create(['product_id' => $this->product->id, 'rating' => 4]);
    Review::factory()->create(['product_id' => $this->product->id, 'title' => 'Pending one']);
    foreach ($reviews as $review) {
        app(App\Domain\Catalog\Reviews\Actions\ModerateReview::class)->handle($review, ReviewStatus::Approved, $moderator->id);
    }

    $first = storefrontRead("/api/v1/storefront/products/{$this->product->slug}/reviews")
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.summary.rating_avg', '4.00')
        ->assertJsonPath('meta.summary.rating_count', 12)
        ->assertJsonPath('meta.summary.distribution.4', 12)
        ->assertJsonMissingPath('data.0.customer_id');

    expect(collect($first->json('data'))->pluck('title'))->not->toContain('Pending one');

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->toBeString();
    storefrontRead("/api/v1/storefront/products/{$this->product->slug}/reviews?cursor={$cursor}")->assertOk()->assertJsonCount(2, 'data');
    // Rule 16: an unlisted parameter is a 400, not silently ignored.
    storefrontRead("/api/v1/storefront/products/{$this->product->slug}/reviews?filter[rating]=5")->assertStatus(400);
    storefrontRead("/api/v1/storefront/products/{$this->product->slug}/reviews?sort=rating")->assertStatus(400);

    storefrontRead('/api/v1/storefront/products/no-such-toy/reviews')->assertNotFound();
});

it('guards the admin review endpoints by role', function (): void {
    $review = Review::factory()->create(['product_id' => $this->product->id]);

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson('/api/v1/admin/reviews'));
    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson("/api/v1/admin/reviews/{$review->id}"));
    RoleMatrix::assert($this, Permission::ReviewsModerate, fn () => $this->postJson("/api/v1/admin/reviews/{$review->id}/approve"));
    RoleMatrix::assert($this, Permission::ReviewsModerate, fn () => $this->postJson("/api/v1/admin/reviews/{$review->id}/reject"));
    RoleMatrix::assert($this, Permission::ReviewsModerate, fn () => $this->putJson("/api/v1/admin/reviews/{$review->id}/reply", ['reply' => 'Thanks']));
});
