<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveLocale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    $probe = fn (Request $request) => response()->json([
        'locale' => App::getLocale(),
        'carbon' => Carbon::getLocale(),
        'message' => Validator::make($request->all(), ['phone' => 'required'])->errors()->first('phone'),
        'title' => __('app.problems.validation'),
    ]);

    Route::middleware(ResolveLocale::class)->get('/api/v1/storefront/_locale-probe', $probe);
    Route::middleware(ResolveLocale::class)->get('/api/v1/admin/_locale-probe', $probe);
});

// Symfony's Request::create() injects `Accept-Language: en-us,en;q=0.5`, so the
// "no signal" cases blank it explicitly.
const NO_ACCEPT_LANGUAGE = ['Accept-Language' => ''];

it('defaults to the configured app locale with no signal', function (): void {
    $this->getJson('/api/v1/storefront/_locale-probe', NO_ACCEPT_LANGUAGE)
        ->assertOk()
        ->assertJsonPath('locale', 'en')
        ->assertHeader('Content-Language', 'en');
});

it('uses the configured default when it is bn', function (): void {
    config(['app.locale' => 'bn']);

    $this->getJson('/api/v1/storefront/_locale-probe', NO_ACCEPT_LANGUAGE)->assertJsonPath('locale', 'bn');
});

it('resolves from the ?locale= query parameter', function (): void {
    $this->getJson('/api/v1/storefront/_locale-probe?locale=bn')
        ->assertJsonPath('locale', 'bn')
        ->assertJsonPath('carbon', 'bn')
        ->assertHeader('Content-Language', 'bn');
});

it('lets ?locale= win over Accept-Language', function (): void {
    $this->getJson('/api/v1/storefront/_locale-probe?locale=en', ['Accept-Language' => 'bn-BD'])
        ->assertJsonPath('locale', 'en');
});

it('maps Accept-Language tags to supported locales', function (string $header, string $expected): void {
    $this->getJson('/api/v1/storefront/_locale-probe', ['Accept-Language' => $header])
        ->assertJsonPath('locale', $expected)
        ->assertHeader('Content-Language', $expected);
})->with([
    'bn-BD' => ['bn-BD', 'bn'],
    'bn' => ['bn', 'bn'],
    'en-US' => ['en-US', 'en'],
    'q-values prefer bn' => ['en-US;q=0.4, bn-BD;q=0.9, fr;q=1', 'bn'],
    'q-values prefer en' => ['bn;q=0.3, en-GB;q=0.8', 'en'],
    'q=0 excludes bn' => ['bn;q=0, en;q=0.1', 'en'],
    'unsupported only' => ['fr-FR, de;q=0.9', 'en'],
    'garbage' => [';;,,q=', 'en'],
]);

it('falls back to en for an unknown ?locale= without erroring', function (string $value): void {
    $this->getJson('/api/v1/storefront/_locale-probe?locale='.urlencode($value), NO_ACCEPT_LANGUAGE)
        ->assertOk()
        ->assertJsonPath('locale', 'en')
        ->assertHeader('Content-Language', 'en');
})->with(['xx', 'fr', '', '../../etc', 'ঢাকা', 'english']);

it('treats an array-valued ?locale[] as absent', function (): void {
    $this->getJson('/api/v1/storefront/_locale-probe?locale[]=bn', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('locale', 'en');
});

it('falls back to en when the configured default is unsupported', function (): void {
    config(['app.locale' => 'fr']);

    $this->getJson('/api/v1/storefront/_locale-probe', NO_ACCEPT_LANGUAGE)->assertOk()->assertJsonPath('locale', 'en');
});

it('uses the staff preference on the admin API over Accept-Language', function (): void {
    $user = User::factory()->make();
    $user->setAttribute('locale', 'bn');

    $this->actingAs($user)
        ->getJson('/api/v1/admin/_locale-probe', ['Accept-Language' => 'en-US'])
        ->assertJsonPath('locale', 'bn');
});

it('still lets ?locale= override the staff preference', function (): void {
    $user = User::factory()->make();
    $user->setAttribute('locale', 'bn');

    $this->actingAs($user)
        ->getJson('/api/v1/admin/_locale-probe?locale=en')
        ->assertJsonPath('locale', 'en');
});

it('ignores the staff preference outside the admin API', function (): void {
    $user = User::factory()->make();
    $user->setAttribute('locale', 'bn');

    $this->actingAs($user)
        ->getJson('/api/v1/storefront/_locale-probe', ['Accept-Language' => 'en'])
        ->assertJsonPath('locale', 'en');
});

it('copes with a staff user that has no locale attribute', function (): void {
    $this->actingAs(User::factory()->make())
        ->getJson('/api/v1/admin/_locale-probe', ['Accept-Language' => 'bn'])
        ->assertOk()
        ->assertJsonPath('locale', 'bn');
});

it('returns validation messages and problem titles in Bangla for bn', function (): void {
    $this->getJson('/api/v1/storefront/_locale-probe?locale=bn')
        ->assertJsonPath('message', 'ফোন নম্বর দিতে হবে।')
        ->assertJsonPath('title', 'তথ্য যাচাই সফল হয়নি');

    $this->getJson('/api/v1/storefront/_locale-probe?locale=en')
        ->assertJsonPath('message', 'The phone number field is required.')
        ->assertJsonPath('title', 'Validation failed');
});
