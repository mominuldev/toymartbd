<?php

declare(strict_types=1);

use App\Domain\Catalog\Import\Enums\ProductImportStatus;
use App\Domain\Catalog\Import\Models\ProductImport;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\RoleMatrix;

/*
 * Bulk product import/export (P4-CAT-10): /api/v1/admin/products/export and
 * /api/v1/admin/product-imports. The queue runs synchronously in tests, so the
 * preview (and the commit) has finished when the POST returns.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    Storage::fake('local');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

/** @param  list<list<string>>  $rows */
function importCsv(array $rows, bool $bom = true): UploadedFile
{
    $handle = fopen('php://temp', 'w+');
    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '\\');
    }
    rewind($handle);
    $csv = ($bom ? "\xEF\xBB\xBF" : '').stream_get_contents($handle);

    return UploadedFile::fake()->createWithContent('products.csv', $csv);
}

function uploadImport(UploadedFile $file): TestResponse
{
    return test()->post('/api/v1/admin/product-imports', ['file' => $file], ['Accept' => 'application/json']);
}

/** @return list<array<string, string>> rows of a CSV body keyed by header */
function parseCsv(string $body): array
{
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue('CSV must start with a UTF-8 BOM');
    $lines = array_map(static fn (string $l): array => str_getcsv($l, escape: '\\'), preg_split('/\r?\n(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim(substr($body, 3))) ?: []);
    $header = array_shift($lines);

    return array_map(static fn (array $l): array => array_combine($header, $l), $lines);
}

it('previews a file without writing anything, then commits it', function (): void {
    $category = Category::factory()->create(['slug' => 'puzzles']);

    $response = uploadImport(importCsv([
        ['sku', 'name', 'name_bn', 'primary_category', 'price', 'status'],
        ['PZ-001', 'Jigsaw Puzzle 100', 'জিগস পাজল ১০০', 'puzzles', '450', 'active'],
        ['PZ-002', 'Jigsaw Puzzle 500', null, 'puzzles', '890.50', 'draft'],
    ]));

    $response->assertStatus(202);
    $id = $response->json('id');

    $this->getJson("/api/v1/admin/product-imports/{$id}")
        ->assertOk()
        ->assertJsonPath('status', 'previewed')
        ->assertJsonPath('total_rows', 2)
        ->assertJsonPath('processed_rows', 2)
        ->assertJsonPath('create_count', 2)
        ->assertJsonPath('update_count', 0)
        ->assertJsonPath('error_count', 0);

    expect(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);

    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(202);

    $this->getJson("/api/v1/admin/product-imports/{$id}")
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('create_count', 2);

    $variant = ProductVariant::query()->where('sku', 'PZ-001')->firstOrFail();
    expect($variant->price->toDecimal())->toBe('450.00')
        ->and($variant->product->name_bn)->toBe('জিগস পাজল ১০০')
        ->and($variant->product->status->value)->toBe('active')
        ->and($variant->product->categories->modelKeys())->toBe([$category->id])
        ->and(ProductVariant::query()->where('sku', 'PZ-002')->firstOrFail()->price->toDecimal())->toBe('890.50');

    // Committed once only.
    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(409);
    expect(Storage::disk('local')->allFiles('imports/products'))->toBe([]);
});

it('imports 1,000 rows', function (): void {
    Category::factory()->create(['slug' => 'blocks']);

    $rows = [['sku', 'name', 'name_bn', 'primary_category', 'price', 'weight_grams']];
    for ($i = 1; $i <= 1000; $i++) {
        $rows[] = [sprintf('BLK-%04d', $i), "Building Blocks Set {$i}", "বিল্ডিং ব্লক সেট {$i}", 'blocks', (string) (100 + $i).'.25', '500'];
    }

    $id = uploadImport(importCsv($rows))->assertStatus(202)->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")->assertJsonPath('create_count', 1000)->assertJsonPath('error_count', 0);

    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(202);

    $import = ProductImport::query()->findOrFail($id);
    expect($import->status)->toBe(ProductImportStatus::Completed)
        ->and($import->processed_rows)->toBe(1000)
        ->and($import->create_count)->toBe(1000)
        ->and(Product::query()->count())->toBe(1000)
        ->and(ProductVariant::query()->where('sku', 'BLK-0999')->firstOrFail()->price->toDecimal())->toBe('1099.25')
        ->and(Product::query()->where('name', 'Building Blocks Set 1000')->value('name_bn'))->toBe('বিল্ডিং ব্লক সেট 1000');
})->group('slow');

it('reports every malformed row with its row number, column and message — never skipping one silently', function (): void {
    Category::factory()->create(['slug' => 'dolls']);

    $id = uploadImport(importCsv([
        ['sku', 'name', 'primary_category', 'price', 'compare_at_price', 'gender'],
        ['DL-1', 'Rag Doll', 'dolls', '300', null, 'girls'],          // row 2: fine
        ['DL-2', 'Doll House', 'dolls', '12.345', null, null],         // row 3: bad money
        ['', 'No SKU Doll', 'dolls', '100', null, null],               // row 4: no SKU
        ['DL-4', 'Lost Doll', 'no-such-category', '100', null, null],  // row 5: unknown slug
        ['DL-1', 'Duplicate', 'dolls', '100', null, null],             // row 6: repeated SKU
        ['DL-6', null, 'dolls', '100', null, null],                    // row 7: name required
        ['DL-7', 'Pricey Doll', 'dolls', '500', '400', null],          // row 8: compare-at below price (domain rule)
        ['DL-8', 'Odd Doll', 'dolls', '100', null, 'robots'],          // row 9: bad enum
    ]))->assertStatus(202)->json('id');

    $preview = $this->getJson("/api/v1/admin/product-imports/{$id}")
        ->assertJsonPath('status', 'previewed')
        ->assertJsonPath('create_count', 1)
        ->assertJsonPath('error_count', 7);

    $errors = collect($preview->json('errors'));
    $at = static fn (int $row): array => $errors->where('row', $row)->pluck('column')->unique()->values()->all();

    expect($at(3))->toBe(['price'])
        ->and($at(4))->toBe(['sku'])
        ->and($at(5))->toBe(['primary_category'])
        ->and($at(6))->toBe(['sku'])
        ->and($at(7))->toBe(['name'])
        ->and($at(8))->toBe(['compare_at_price'])
        ->and($at(9))->toBe(['gender'])
        ->and($errors->firstWhere('row', 6)['message'])->toContain('row 2')
        ->and($errors->firstWhere('row', 3)['message'])->not->toContain('variants.0');

    // The downloadable report lists the same problems.
    $report = $this->get("/api/v1/admin/product-imports/{$id}/errors");
    $report->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $lines = parseCsv($report->baseResponse->getFile()->getContent());
    expect(collect($lines)->pluck('row')->unique()->values()->all())->toBe(['3', '4', '5', '6', '7', '8', '9'])
        ->and(array_keys($lines[0]))->toBe(['row', 'sku', 'column', 'message']);

    // Committing imports the valid row only; the invalid ones are reported again.
    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(202);
    $this->getJson("/api/v1/admin/product-imports/{$id}")->assertJsonPath('status', 'completed')->assertJsonPath('create_count', 1)->assertJsonPath('error_count', 7);
    expect(ProductVariant::query()->pluck('sku')->all())->toBe(['DL-1']);
});

it('refuses a file with an unknown column, or without a sku column, as a whole', function (): void {
    $id = uploadImport(importCsv([['sku', 'name', 'colour'], ['A-1', 'Ball', 'red']]))->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('errors.0.row', 0)
        ->assertJsonPath('errors.0.message', fn (string $m): bool => str_contains($m, 'colour'));

    $id = uploadImport(importCsv([['name', 'price'], ['Ball', '100']]))->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")->assertJsonPath('status', 'failed');

    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(409);
});

it('upserts by SKU: a known SKU updates its product and keeps every column the file leaves out', function (): void {
    $product = Product::factory()->priced('500.00')->create(['name' => 'Toy Car', 'name_bn' => 'খেলনা গাড়ি', 'short_description' => 'Fast', 'age_min_months' => 36]);
    $sku = $product->variants()->firstOrFail()->sku;

    $id = uploadImport(importCsv([['sku', 'price', 'name'], [$sku, '650', 'Toy Car Deluxe']]))->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")->assertJsonPath('update_count', 1)->assertJsonPath('create_count', 0);
    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(202);

    $product->refresh();
    expect($product->name)->toBe('Toy Car Deluxe')
        ->and($product->name_bn)->toBe('খেলনা গাড়ি')
        ->and($product->short_description)->toBe('Fast')
        ->and($product->age_min_months)->toBe(36)
        ->and($product->variants()->count())->toBe(1)
        ->and($product->variants()->firstOrFail()->price->toDecimal())->toBe('650.00');
});

it('authorises each row as the product endpoints do: a content editor cannot change a price through a file', function (): void {
    $product = Product::factory()->priced('500.00')->create();
    $sku = $product->variants()->firstOrFail()->sku;

    // The content editor has neither catalog.import nor catalog.update_price.
    $this->actingAs(User::factory()->withRole(Role::ContentEditor)->create()->refresh(), 'web');
    uploadImport(importCsv([['sku', 'price'], [$sku, '900']]))->assertForbidden();

    // A role with catalog.import but without catalog.view_cost cannot set cost through a file either.
    $role = SpatieRole::findOrCreate('importer', 'web');
    $role->syncPermissions(['catalog.view', 'catalog.import', 'catalog.create', 'catalog.update', 'catalog.update_price']);
    $importer = User::factory()->create();
    $importer->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    auth()->forgetGuards();
    $this->actingAs($importer->refresh(), 'web');
    $id = uploadImport(importCsv([['sku', 'cost_price'], [$sku, '100']]))->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")
        ->assertJsonPath('error_count', 1)
        ->assertJsonPath('errors.0.message', fn (string $m): bool => str_contains($m, 'catalog.view_cost'));

    // …and the same file with the cost column left out updates nothing it may not.
    $id = uploadImport(importCsv([['sku', 'name'], [$sku, 'Renamed']]))->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")->assertJsonPath('error_count', 0)->assertJsonPath('update_count', 1);
});

it('exports the list with its filters, money as decimal strings, and Bangla names that survive a round trip', function (): void {
    $brand = Brand::factory()->create(['slug' => 'lego']);
    $category = Category::factory()->create(['slug' => 'construction']);
    $lego = Product::factory()->priced('2450.00')->create([
        'name' => 'City Fire Station', 'name_bn' => 'সিটি ফায়ার স্টেশন', 'brand_id' => $brand->id,
        'primary_category_id' => $category->id, 'description' => '<p>শিশুদের জন্য <strong>নিরাপদ</strong></p>',
    ]);
    $lego->categories()->sync([$category->id]);
    Product::factory()->priced('99.00')->create(['name' => '=HYPERLINK("http://evil")']);

    $response = $this->get('/api/v1/admin/products/export?format=csv&filter[brand_id]='.$brand->id);
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $body = $response->baseResponse->getFile()->getContent();
    $rows = parseCsv($body);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name_bn'])->toBe('সিটি ফায়ার স্টেশন')
        ->and($rows[0]['price'])->toBe('2450.00')
        ->and($rows[0]['brand'])->toBe('lego')
        ->and($rows[0]['primary_category'])->toBe('construction')
        ->and($rows[0]['cost_price'])->toBe($lego->variants()->firstOrFail()->cost_price->toDecimal());

    // An unlisted filter is a 400; an unknown format too.
    $this->getJson('/api/v1/admin/products/export?filter[colour]=red')->assertStatus(400);
    $this->getJson('/api/v1/admin/products/export?format=pdf')->assertStatus(400);

    // Formula injection: a value that starts with = is neutralised in CSV.
    $all = $this->get('/api/v1/admin/products/export')->baseResponse->getFile()->getContent();
    expect($all)->toContain("'=HYPERLINK");

    // Round trip: edit the Bangla name in the exported file, import it back.
    $edited = str_replace('সিটি ফায়ার স্টেশন', 'সিটি ফায়ার স্টেশন (নতুন)', $body);
    $id = uploadImport(UploadedFile::fake()->createWithContent('products.csv', $edited))->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")->assertJsonPath('error_count', 0)->assertJsonPath('update_count', 1);
    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(202);

    $lego->refresh();
    expect($lego->name_bn)->toBe('সিটি ফায়ার স্টেশন (নতুন)')
        ->and($lego->description)->toContain('নিরাপদ')
        ->and($lego->variants()->firstOrFail()->price->toDecimal())->toBe('2450.00')
        ->and(Product::query()->count())->toBe(2);

    // The formula-looking name also round trips unchanged.
    $all = $this->get('/api/v1/admin/products/export')->baseResponse->getFile()->getContent();
    $id = uploadImport(UploadedFile::fake()->createWithContent('all.csv', $all))->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")->assertJsonPath('error_count', 0);
    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(202);
    expect(Product::query()->where('name', '=HYPERLINK("http://evil")')->exists())->toBeTrue();
});

it('round-trips XLSX with Bangla text', function (): void {
    $category = Category::factory()->create(['slug' => 'art']);
    $product = Product::factory()->priced('320.00')->create(['name' => 'Crayons', 'name_bn' => 'রং পেন্সিল', 'primary_category_id' => $category->id]);
    $product->categories()->sync([$category->id]);

    $response = $this->get('/api/v1/admin/products/export?format=xlsx');
    $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    file_put_contents($path, $response->baseResponse->getFile()->getContent());

    $reader = new XlsxReader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    $header = $rows[0];
    $first = array_combine($header, $rows[1]);
    expect($first['name_bn'])->toBe('রং পেন্সিল')->and($first['price'])->toBe('320.00');

    // A fresh XLSX with a numeric price cell creates a product.
    $new = tempnam(sys_get_temp_dir(), 'y').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($new);
    $writer->addRow(Row::fromValues(['sku', 'name', 'name_bn', 'primary_category', 'price']));
    $writer->addRow(Row::fromValues(['ART-9', 'Watercolours', 'জলরং', 'art', 99.5]));
    $writer->close();

    $id = uploadImport(new UploadedFile($new, 'new.xlsx', null, null, true))->assertStatus(202)->json('id');
    $this->getJson("/api/v1/admin/product-imports/{$id}")->assertJsonPath('create_count', 1)->assertJsonPath('error_count', 0);
    $this->postJson("/api/v1/admin/product-imports/{$id}/commit")->assertStatus(202);
    expect(ProductVariant::query()->where('sku', 'ART-9')->firstOrFail()->price->toDecimal())->toBe('99.50')
        ->and(Product::query()->where('name', 'Watercolours')->value('name_bn'))->toBe('জলরং');
});

it('creates only simple products and reports a variable one', function (): void {
    Category::factory()->create(['slug' => 'misc']);
    $id = uploadImport(importCsv([['sku', 'type', 'name', 'primary_category', 'price'], ['V-1', 'variable', 'Shirt', 'misc', '100']]))->json('id');

    $this->getJson("/api/v1/admin/product-imports/{$id}")
        ->assertJsonPath('error_count', 1)
        ->assertJsonPath('errors.0.column', 'type');
});

it('rejects a file that is not CSV or XLSX', function (): void {
    uploadImport(UploadedFile::fake()->create('products.pdf', 10, 'application/pdf'))->assertStatus(422);
});

it('guards the import and export endpoints by role', function (): void {
    Category::factory()->create(['slug' => 'matrix']);
    Product::factory()->priced('100.00')->create();

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->get('/api/v1/admin/products/export', ['Accept' => 'application/json']));
    RoleMatrix::assert($this, Permission::CatalogImport, fn () => uploadImport(importCsv([['sku', 'name', 'primary_category', 'price'], ['M-'.uniqid(), 'Matrix', 'matrix', '100']])), 202);

    $import = ProductImport::query()->latest('id')->firstOrFail();
    RoleMatrix::assert($this, Permission::CatalogImport, fn () => $this->getJson("/api/v1/admin/product-imports/{$import->id}"));
    RoleMatrix::assert($this, Permission::CatalogImport, fn () => $this->get("/api/v1/admin/product-imports/{$import->id}/errors", ['Accept' => 'application/json']));

    // Commit: allowed roles reach the action (202 first, then 409 once it is committed).
    foreach (Role::cases() as $role) {
        $allowed = RoleMatrix::allows($role, Permission::CatalogImport);
        $user = User::factory()->withRole($role)->create()->refresh();
        auth()->forgetGuards();
        $this->actingAs($user, 'web');
        $response = $this->postJson("/api/v1/admin/product-imports/{$import->id}/commit");
        $allowed ? expect($response->status())->toBeIn([202, 409]) : RoleMatrix::assertForbidden($response, $role->value);
    }
});
