<?php

declare(strict_types=1);

use App\Models\Enums\Permission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * P3-API-07 — "CI fails if the committed spec is stale".
 *
 * The canonical document is generated HERE, in the test environment (migrated SQLite),
 * because Scramble reads model column types from the database: generating against a
 * developer's MySQL could differ from CI. To regenerate the committed file:
 *
 *     composer openapi:export
 *     (= UPDATE_OPENAPI_SPEC=1 php vendor/bin/pest tests/Feature/OpenApi/OpenApiSpecTest.php)
 */

const OPENAPI_SPEC_PATH = 'docs/api/openapi.json';

function freshOpenApiSpec(): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'openapi');
    test()->artisan('scramble:export', ['--path' => $tmp])->assertSuccessful();
    $json = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $json;
}

/** @return array<string, mixed> */
function openApiSpec(): array
{
    /** @var array<string, mixed> */
    return json_decode(freshOpenApiSpec(), true, flags: JSON_THROW_ON_ERROR);
}

it('matches the committed docs/api/openapi.json', function (): void {
    $fresh = freshOpenApiSpec();
    $path = base_path(OPENAPI_SPEC_PATH);

    if (getenv('UPDATE_OPENAPI_SPEC') === '1') {
        file_put_contents($path, $fresh."\n");
    }

    expect(is_file($path))->toBeTrue('docs/api/openapi.json is missing — run `composer openapi:export`.');
    expect(rtrim((string) file_get_contents($path), "\n"))->toBe(
        $fresh,
        'docs/api/openapi.json is stale — run `composer openapi:export` and commit the diff.',
    );
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'The canonical spec is generated against the SQLite test schema.');

it('is OpenAPI 3.1 covering only /api/v1, grouped by audience', function (): void {
    $spec = openApiSpec();

    expect($spec['openapi'])->toStartWith('3.1.')
        ->and($spec['servers'])->toBe([['url' => '/api/v1', 'description' => 'This host']])
        ->and(array_column($spec['tags'], 'name'))->toBe(['Storefront', 'Admin', 'Webhooks', 'System'])
        ->and(array_keys($spec['paths']))->toContain('/storefront/ping', '/admin/ping', '/webhooks/ping', '/health')
        ->and(array_keys($spec['paths']))->not->toContain('/docs', '/docs.json');

    expect($spec['paths']['/storefront/ping']['get']['tags'])->toBe(['Storefront'])
        ->and($spec['paths']['/admin/ping']['get']['tags'])->toBe(['Admin'])
        ->and($spec['paths']['/health']['get']['tags'])->toBe(['System']);
});

it('documents errors as RFC 9457 problem+json', function (): void {
    $spec = openApiSpec();

    expect($spec['components']['schemas']['ProblemDetails']['required'])->toBe(['type', 'title', 'status', 'detail']);

    $errorContents = [];
    foreach ($spec['paths'] as $operations) {
        foreach ($operations as $operation) {
            foreach ($operation['responses'] ?? [] as $code => $response) {
                if ((int) $code >= 400 && isset($response['content'])) {
                    $errorContents[] = $response['content'];
                }
            }
        }
    }
    foreach ($spec['components']['responses'] ?? [] as $response) {
        if (isset($response['content'])) {
            $errorContents[] = $response['content'];
        }
    }

    expect($errorContents)->not->toBeEmpty();
    foreach ($errorContents as $content) {
        expect($content)->toBe(['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetails']]]);
    }
});

it('documents the Idempotency-Key header on idempotent routes only', function (): void {
    Route::middleware(['api', 'idempotent'])->post('api/v1/storefront/_test/openapi-idempotent', fn () => response()->json(['ok' => true], 201));

    $spec = openApiSpec();
    $operation = $spec['paths']['/storefront/_test/openapi-idempotent']['post'];

    expect($operation['parameters'])->toContain(['$ref' => '#/components/parameters/IdempotencyKey'])
        ->and(array_keys($operation['responses']))->toContain(400, 409, 422)
        ->and($spec['components']['parameters']['IdempotencyKey'])->toMatchArray(['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true])
        ->and($spec['paths']['/storefront/ping']['get'])->not->toHaveKey('parameters');
});

it('serves the UI and JSON at /api/docs in local only, or to staff with settings.view', function (): void {
    // Testing env: closed to guests and to staff without the permission.
    $this->get('/api/docs.json')->assertForbidden();
    $this->get('/api/docs')->assertForbidden();

    $this->actingAs(User::factory()->create())->get('/api/docs.json')->assertForbidden();

    $this->seed(RolesAndPermissionsSeeder::class);
    $staff = User::factory()->create();
    $staff->givePermissionTo(Permission::SettingsView->value);

    $this->actingAs($staff)->get('/api/docs.json')->assertOk()->assertJsonPath('info.title', 'Toy Mart BD API');
    $this->actingAs($staff)->get('/api/docs')->assertOk();
});

it('is open without auth when APP_ENV=local', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/api/docs.json')->assertOk()->assertJsonPath('info.title', 'Toy Mart BD API');
});
