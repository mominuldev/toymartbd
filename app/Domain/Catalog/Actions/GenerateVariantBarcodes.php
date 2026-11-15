<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\Ean13;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Give variants that have no barcode an in-store EAN-13 (owner request 2026-11-16): the GS1
 * restricted-circulation prefix (`config/catalog.php` → `barcode.prefix`, default `20`) + a
 * zero-padded running number + the check digit.
 *
 * The running number is the `barcode_sequences` row for the prefix, locked FOR UPDATE first
 * in the transaction, so concurrent generators queue on it and never hand out the same number.
 * A candidate that already sits in product_variants.barcode (a code a shop typed in, or one
 * imported) is skipped. A variant that already has a barcode is never touched: the variants
 * are re-read under lock with `barcode IS NULL`, so one typed in meanwhile is kept.
 *
 * Not written to `audit_sensitive`: that trail holds price and cost changes only, and a
 * barcode is neither. ProductChanged fires after commit for each product (its GTIN is part of
 * the storefront's structured data).
 */
final readonly class GenerateVariantBarcodes
{
    /** Candidates checked against existing barcodes per query. */
    private const int BATCH = 200;

    /** Transaction attempts: a deadlock against a concurrent product save is retried. */
    private const int ATTEMPTS = 3;

    /**
     * @param  list<int>  $productIds
     * @param  list<int>|null  $variantIds  only these variants of those products (any status);
     *                                      null = every active variant without a barcode
     * @return Collection<int, ProductVariant> the variants that got a barcode, by id
     */
    public function handle(array $productIds, ?array $variantIds = null): Collection
    {
        $prefix = self::prefix();
        // The counter row is created before the transaction that locks it, and only when a plain
        // read misses it: an INSERT IGNORE inside the transaction takes a shared lock that two
        // holders deadlock upgrading to FOR UPDATE (DATABASE-DESIGN §4.8, P9 F-2).
        if (! DB::table('barcode_sequences')->where('prefix', $prefix)->exists()) {
            $now = Carbon::now();
            DB::table('barcode_sequences')->insertOrIgnore(['prefix' => $prefix, 'next_number' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }

        try {
            return DB::transaction(function () use ($prefix, $productIds, $variantIds): Collection {
                /** @var object{id: int, next_number: int|string} $sequence */
                $sequence = DB::table('barcode_sequences')->where('prefix', $prefix)->lockForUpdate()->first();

                $variants = ProductVariant::query()
                    ->whereIn('product_id', $productIds)
                    ->when($variantIds !== null, fn ($query) => $query->whereKey($variantIds ?? []), fn ($query) => $query->active())
                    ->whereNull('barcode')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($variants->isEmpty()) {
                    return $variants;
                }

                [$codes, $next] = $this->allocate($prefix, (int) $sequence->next_number, $variants->count());

                DB::table('barcode_sequences')->where('id', $sequence->id)->update(['next_number' => $next, 'updated_at' => Carbon::now()]);

                foreach ($variants->values() as $i => $variant) {
                    $variant->barcode = $codes[$i];
                    $variant->save();
                }

                Product::query()->whereKey($variants->pluck('product_id')->unique()->all())->get(['id', 'slug'])
                    ->each(static fn (Product $product) => ProductChanged::dispatch($product->id, $product->slug));

                return $variants;
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // A barcode typed into another product between our existence check and the write.
            throw new CatalogConflict('A generated barcode was taken by another product at the same moment. Try again.');
        }
    }

    /** The configured prefix: digits, 2–3 long. */
    public static function prefix(): string
    {
        $prefix = (string) config('catalog.barcode.prefix', '20');
        if (preg_match('/^\d{2,3}$/', $prefix) !== 1) {
            throw new RuntimeException('catalog.barcode.prefix must be 2–3 digits.');
        }

        return $prefix;
    }

    /**
     * `$count` free codes from `$next` on, skipping any already in use.
     *
     * @return array{0: list<string>, 1: int} the codes and the next running number
     */
    private function allocate(string $prefix, int $next, int $count): array
    {
        $capacity = Ean13::capacity($prefix);
        $codes = [];

        while (count($codes) < $count) {
            $want = min(self::BATCH, $count - count($codes));
            if ($next + $want - 1 > $capacity) {
                throw new CatalogConflict("Every in-store barcode with prefix {$prefix} is used. Change catalog.barcode.prefix.");
            }

            $candidates = array_map(static fn (int $n): string => Ean13::build($prefix, $n), range($next, $next + $want - 1));
            $taken = array_flip(ProductVariant::query()->whereIn('barcode', $candidates)->pluck('barcode')->all());
            $next += $want;

            foreach ($candidates as $code) {
                if (! isset($taken[$code])) {
                    $codes[] = $code;
                }
            }
        }

        return [$codes, $next];
    }
}
