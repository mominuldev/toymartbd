<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Pos\Actions\OpenRegisterSession;
use App\Domain\Pos\Models\PosSession;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** Shared fixtures for the POS tests: a retail branch with its own stock, a cashier, an open drawer. */
final class PosKit
{
    public const string PHONE = '01819552310';

    public static function cashier(Role $role = Role::OrderOperator): User
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->withRole($role)->create()->refresh();
        test()->actingAs($user, 'web');

        return $user;
    }

    public static function branch(string $code = 'ctg', StockLocationType $type = StockLocationType::Retail): StockLocation
    {
        return StockLocation::query()->create([
            'code' => $code,
            'name' => 'Chattogram · Agrabad',
            'name_bn' => 'চট্টগ্রাম · আগ্রাবাদ',
            'type' => $type,
            'address_line' => 'Shop 12, Agrabad Commercial Area',
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    /** A sellable variant with `$atBranch` on hand at the branch and `$atDefault` at the default warehouse. */
    public static function variant(StockLocation $branch, int $atBranch, string $price = '1850.00', string $cost = '1100.00', int $atDefault = 0, ?string $barcode = null): ProductVariant
    {
        $variant = ProductVariant::factory()->create(['price' => $price, 'cost_price' => $cost, 'barcode' => $barcode]);
        DB::transaction(function () use ($variant, $branch, $atBranch, $atDefault): void {
            if ($atBranch > 0) {
                app(StockLedger::class)->record([new StockMovementLine($variant->id, StockMovementType::OpeningBalance, $atBranch)], $branch->id);
            }
            if ($atDefault > 0) {
                app(StockLedger::class)->record([new StockMovementLine($variant->id, StockMovementType::OpeningBalance, $atDefault)], StockLocation::defaultId());
            }
        });

        return $variant;
    }

    public static function open(StockLocation $branch, User $cashier, string $float = '5000.00', string $register = 'Register 1'): PosSession
    {
        return app(OpenRegisterSession::class)->handle($branch->id, $register, Money::of($float), $cashier->id);
    }

    public static function level(int $variantId, int $locationId): StockLevel
    {
        return StockLevel::query()->where('variant_id', $variantId)->where('location_id', $locationId)->sole();
    }

    /**
     * @param  array<int, int>  $lines
     * @param  list<array{method: string, amount: string, reference?: string}>  $tenders
     * @param  array<string, mixed>  $extra
     */
    public static function charge(PosSession $session, array $lines, array $tenders, string $expected, array $extra = [], ?string $key = null): TestResponse
    {
        return test()->withHeader('Idempotency-Key', $key ?? 'pos-'.bin2hex(random_bytes(8)))
            ->postJson("/api/v1/admin/pos/sessions/{$session->id}/sales", [
                'lines' => self::lines($lines),
                'tenders' => $tenders,
                'expected_total' => $expected,
                ...$extra,
            ]);
    }

    /**
     * @param  array<int, int>  $lines
     * @return list<array{variant_id: int, quantity: int}>
     */
    public static function lines(array $lines): array
    {
        return array_map(static fn (int $id, int $qty): array => ['variant_id' => $id, 'quantity' => $qty], array_keys($lines), $lines);
    }
}
