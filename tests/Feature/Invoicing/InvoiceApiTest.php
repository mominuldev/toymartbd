<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Invoicing\Actions\IssuePurchaseInvoice;
use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Orders\Support;
use Tests\Feature\Purchasing\PurchasingKit;
use Tests\Support\PdfText;
use Tests\Support\RoleMatrix;

/*
 * P8 invoicing API: the list (allowlisted filters, search by number / order), one invoice
 * (PII masked), reprint, the supplier invoice of a PO, the invoice-details settings, and the
 * 403 matrix for every endpoint (rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

function salesInvoice(array $order = []): Invoice
{
    $o = Order::factory()->create($order);
    OrderItem::factory()->create(['order_id' => $o->id]);

    return app(IssueSalesInvoice::class)->handle($o);
}

it('issues a PINV supplier invoice for a placed PO, refuses a draft, and prints it', function (): void {
    Support::actingAs(Role::InventoryStaff);
    $variant = ProductVariant::factory()->create(['sku' => 'TMB-BLK-01']);
    $supplier = Supplier::factory()->create(['name' => 'Dhaka Plush House', 'payment_terms_days' => 15]);
    $po = PurchasingKit::purchase([[$variant, 12, '99.5000']], $supplier);
    $draft = PurchasingKit::purchase([[$variant, 1, '10']], $supplier, ordered: false);
    $year = now()->setTimezone('Asia/Dhaka')->format('y');

    $response = $this->get("/api/v1/admin/purchases/{$po->id}/invoice")->assertOk();
    $this->getJson("/api/v1/admin/purchases/{$draft->id}/invoice")->assertStatus(409);
    $invoice = Invoice::query()->where('purchase_id', $po->id)->sole();
    $text = PdfText::extract((string) $response->getContent());

    expect($invoice->number)->toBe("PINV-{$year}-000001")
        ->and($invoice->total->toDecimal())->toBe($po->refresh()->total->toDecimal())
        ->and($invoice->due_at?->toDateString())->toBe('2026-11-27')
        ->and($text)->toContain("PINV-{$year}-000001")->toContain((string) $po->number)->toContain('Dhaka Plush House')->toContain('TMB-BLK-01')->toContain('Purchase order')
        // A customer invoice uses its own series.
        ->and(salesInvoice()->number)->toBe("INV-{$year}-000001")
        ->and(app(IssuePurchaseInvoice::class)->handle($po)->id)->toBe($invoice->id);
});

it('lists invoices newest first with allowlisted filters and search by number or order', function (): void {
    Support::actingAs(Role::Manager);
    $a = salesInvoice(['shipping_name' => 'Nusrat Jahan']);
    $b = salesInvoice(['shipping_name' => 'রাহেলা খাতুন']);

    $all = $this->getJson('/api/v1/admin/invoices')->assertOk()->json('data');
    expect(array_column($all, 'number'))->toBe([$b->number, $a->number])
        ->and($all[0])->toHaveKeys(['type', 'reference', 'issued_to_name', 'total', 'due', 'placeholders', 'can_print'])
        ->and($all[0]['total'])->toBe($b->total->toDecimal())
        ->and($all[0])->not->toHaveKey('issued_to');

    expect(array_column($this->getJson('/api/v1/admin/invoices?filter[search]='.urlencode($a->number))->assertOk()->json('data'), 'id'))->toBe([$a->id])
        ->and(array_column($this->getJson('/api/v1/admin/invoices?filter[search]='.urlencode($b->reference))->assertOk()->json('data'), 'id'))->toBe([$b->id])
        ->and(array_column($this->getJson('/api/v1/admin/invoices?filter[search]='.urlencode('রাহেলা'))->assertOk()->json('data'), 'id'))->toBe([$b->id])
        ->and($this->getJson('/api/v1/admin/invoices?filter[type]=purchase')->assertOk()->json('data'))->toBe([])
        ->and(count($this->getJson('/api/v1/admin/invoices?sort=number')->assertOk()->json('data')))->toBe(2);

    $today = now()->setTimezone('Asia/Dhaka')->toDateString();
    expect(count($this->getJson("/api/v1/admin/invoices?filter[issued_from]={$today}&filter[issued_to]={$today}")->assertOk()->json('data')))->toBe(2);

    $this->getJson('/api/v1/admin/invoices?filter[status]=paid')->assertStatus(400);
    $this->getJson('/api/v1/admin/invoices?filter[type]=refund')->assertStatus(400);
    $this->getJson('/api/v1/admin/invoices?filter[issued_from]=13-11-2026')->assertStatus(400);
    $this->getJson('/api/v1/admin/invoices?sort=total')->assertStatus(400);
});

it('masks the customer phone and address on one invoice without customers.view_pii', function (): void {
    $invoice = salesInvoice(['shipping_phone' => '+8801712345678', 'shipping_address' => 'House 7, Road 3']);

    Support::actingAs(Role::Viewer);
    $masked = $this->getJson("/api/v1/admin/invoices/{$invoice->id}")->assertOk()->json();
    expect($masked['issued_to']['phone'])->not->toContain('12345678')
        ->and($masked['issued_to'])->not->toHaveKey('address')
        ->and($masked['can_print'])->toBeFalse()
        ->and($masked['lines'])->toHaveCount(1);

    auth()->forgetGuards();
    Support::actingAs(Role::OrderOperator);
    expect($this->getJson("/api/v1/admin/invoices/{$invoice->id}")->assertOk()->json('issued_to.address'))->toBe('House 7, Road 3');
});

it('saves and reads the invoice company details', function (): void {
    Support::actingAs(Role::Manager);
    $this->getJson('/api/v1/admin/settings/invoice')->assertOk()
        ->assertJsonPath('name_en', null)
        ->assertJsonPath('placeholders', ['name_en', 'name_bn', 'address_en', 'address_bn', 'hotline', 'trade_licence', 'bin']);

    $this->putJson('/api/v1/admin/settings/invoice', ['name_en' => 'Toy Mart BD', 'name_bn' => 'টয় মার্ট বিডি', 'bin' => 'not-a-bin!'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'bin');
    $this->putJson('/api/v1/admin/settings/invoice', ['name_en' => 'Toy Mart BD', 'name_bn' => 'টয় মার্ট বিডি', 'bin' => '000111222-0101', 'logo_media_id' => 999999])->assertUnprocessable()->assertJsonPath('errors.0.field', 'logo_media_id');
    $this->putJson('/api/v1/admin/settings/invoice', ['name_en' => 'Toy Mart BD', 'name_bn' => 'টয় মার্ট বিডি', 'bin' => '000111222-0101'])
        ->assertOk()->assertJsonPath('bin', '000111222-0101')->assertJsonPath('name_bn', 'টয় মার্ট বিডি');
});

describe('role matrix', function (): void {
    it('guards the invoice list and detail by module (orders.view or purchasing.view + catalog.view_cost)', function (Role $role): void {
        $invoice = salesInvoice();
        $sees = RoleMatrix::allows($role, Permission::OrdersView)
            || (RoleMatrix::allows($role, Permission::PurchasingView) && RoleMatrix::allows($role, Permission::CatalogViewCost));
        RoleMatrix::assertFor($this, $role, $sees, fn () => $this->getJson('/api/v1/admin/invoices'));
        RoleMatrix::assertFor($this, $role, RoleMatrix::allows($role, Permission::OrdersView), fn () => $this->getJson("/api/v1/admin/invoices/{$invoice->id}"));
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('guards a customer invoice reprint with orders.view + customers.view_pii', function (Role $role): void {
        $invoice = salesInvoice();
        $allowed = RoleMatrix::allows($role, Permission::OrdersView) && RoleMatrix::allows($role, Permission::CustomersViewPii);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get("/api/v1/admin/invoices/{$invoice->id}/pdf", ['Accept' => 'application/json']));
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('guards a supplier invoice with purchasing.view + catalog.view_cost', function (Role $role): void {
        $po = PurchasingKit::purchase([[ProductVariant::factory()->create(), 2, '10']]);
        $allowed = RoleMatrix::allows($role, Permission::PurchasingView) && RoleMatrix::allows($role, Permission::CatalogViewCost);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get("/api/v1/admin/purchases/{$po->id}/invoice", ['Accept' => 'application/json']));
        $invoice = app(IssuePurchaseInvoice::class)->handle($po);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->get("/api/v1/admin/invoices/{$invoice->id}/pdf", ['Accept' => 'application/json']));
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('guards invoice settings: read settings.view, write settings.update', function (): void {
        RoleMatrix::assert($this, Permission::SettingsView, fn () => $this->getJson('/api/v1/admin/settings/invoice'));
        RoleMatrix::assert($this, Permission::SettingsUpdate, fn () => $this->putJson('/api/v1/admin/settings/invoice', ['name_en' => 'Toy Mart BD']));
    });

    it('refuses a guest with 401', function (): void {
        $this->getJson('/api/v1/admin/invoices')->assertUnauthorized();
        $this->getJson('/api/v1/admin/settings/invoice')->assertUnauthorized();
        $this->getJson('/api/v1/admin/purchases/1/invoice')->assertUnauthorized();
    });
});
