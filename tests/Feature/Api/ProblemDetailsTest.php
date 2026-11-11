<?php

declare(strict_types=1);

use App\Http\Problems\ProblemType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Fixtures\StoreWidgetRequest;

beforeEach(function (): void {
    Route::middleware('api')->prefix('api/v1/storefront/_test')->group(function (): void {
        Route::post('/widgets', fn (StoreWidgetRequest $request) => response()->json($request->validated(), 201));
        Route::get('/private', fn () => ['ok' => true])->middleware('auth:sanctum');
        Route::get('/policy', function (): void {
            Gate::define('test-deny', fn (?User $user): bool => false);
            Gate::authorize('test-deny');
        });
        Route::get('/deny-with-reason', fn () => throw new AuthorizationException('Only owners may refund.'));
        Route::get('/missing-model', fn () => User::query()->findOrFail(999_999));
        Route::get('/conflict', fn () => abort(409, 'Order is already shipped.'));
        Route::get('/boom', fn () => throw new RuntimeException('SQLSTATE secret-dsn password=hunter2'));
        Route::get('/deliberate', fn () => throw new HttpResponseException(response()->json(['custom' => true], 418)));
    });
});

function assertProblem(TestResponse $response, ProblemType $type, int $status): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://toymartbd.com/problems/'.$type->value)
        ->assertJsonPath('title', $type->title())
        ->assertJsonPath('status', $status);

    expect($response->json('detail'))->toBeString()->not->toBe('');
}

it('renders a failed FormRequest as a validation-error problem with a field/message list', function (): void {
    $response = $this->postJson('/api/v1/storefront/_test/widgets', ['name' => str_repeat('x', 21)]);

    assertProblem($response, ProblemType::ValidationError, 422);
    $response->assertJsonCount(2, 'errors')
        ->assertJsonPath('errors.0.field', 'name')
        ->assertJsonPath('errors.1.field', 'qty');
    expect($response->json('errors.0.message'))->toBeString();
});

it('passes valid input through an ApiFormRequest', function (): void {
    $this->postJson('/api/v1/storefront/_test/widgets', ['name' => 'Lego', 'qty' => 2])
        ->assertCreated()
        ->assertExactJson(['name' => 'Lego', 'qty' => 2]);
});

it('renders a failed FormRequest authorisation as forbidden', function (): void {
    $response = $this->postJson('/api/v1/storefront/_test/widgets', ['name' => 'Lego', 'qty' => 2], ['X-Test-Deny' => '1']);

    assertProblem($response, ProblemType::Forbidden, 403);
});

it('renders a missing credential as unauthenticated', function (): void {
    assertProblem($this->getJson('/api/v1/storefront/_test/private'), ProblemType::Unauthenticated, 401);
});

it('renders problems even when the client does not ask for JSON', function (): void {
    $response = $this->get('/api/v1/storefront/_test/private', ['Accept' => 'text/html']);

    assertProblem($response, ProblemType::Unauthenticated, 401);
});

it('renders a denied Gate as forbidden', function (): void {
    assertProblem($this->getJson('/api/v1/storefront/_test/policy'), ProblemType::Forbidden, 403);
});

it('keeps a deliberate authorisation message as the detail', function (): void {
    $response = $this->getJson('/api/v1/storefront/_test/deny-with-reason');

    assertProblem($response, ProblemType::Forbidden, 403);
    $response->assertJsonPath('detail', 'Only owners may refund.');
});

it('renders an unknown route as not-found', function (): void {
    assertProblem($this->getJson('/api/v1/storefront/does-not-exist'), ProblemType::NotFound, 404);
});

it('renders a missing model as not-found without naming the model', function (): void {
    $response = $this->getJson('/api/v1/storefront/_test/missing-model');

    assertProblem($response, ProblemType::NotFound, 404);
    expect($response->getContent())->not->toContain('User')->not->toContain('999999');
});

it('renders a wrong verb as method-not-allowed with an Allow header', function (): void {
    $response = $this->postJson('/api/v1/storefront/ping');

    assertProblem($response, ProblemType::MethodNotAllowed, 405);
    expect($response->headers->get('Allow'))->toContain('GET');
});

it('maps other HTTP statuses to their own type', function (): void {
    $response = $this->getJson('/api/v1/storefront/_test/conflict');

    assertProblem($response, ProblemType::Conflict, 409);
    $response->assertJsonPath('detail', 'Order is already shipped.');
});

it('renders an unexpected exception as server-error without leaking it when debug is off', function (): void {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/storefront/_test/boom');

    assertProblem($response, ProblemType::ServerError, 500);
    $response->assertJsonMissingPath('debug')->assertJsonMissingPath('errors');
    expect($response->getContent())
        ->not->toContain('hunter2')
        ->not->toContain('SQLSTATE')
        ->not->toContain('RuntimeException')
        ->not->toContain('trace');
});

it('adds debug context to server errors only when debug is on', function (): void {
    config(['app.debug' => true]);

    $this->getJson('/api/v1/storefront/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('type', ProblemType::ServerError->uri())
        ->assertJsonPath('debug.exception', RuntimeException::class);
});

it('leaves a deliberate HttpResponseException untouched', function (): void {
    $this->getJson('/api/v1/storefront/_test/deliberate')
        ->assertStatus(418)
        ->assertExactJson(['custom' => true]);
});

it('does not turn web routes into problems', function (): void {
    Route::get('/_test/web-boom', fn () => abort(409));

    $response = $this->getJson('/_test/web-boom');

    $response->assertStatus(409);
    expect($response->headers->get('Content-Type'))->not->toContain('problem+json');
});
