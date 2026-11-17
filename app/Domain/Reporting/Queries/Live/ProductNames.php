<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Live;

use Illuminate\Support\Facades\DB;

/**
 * Tracking items carry the variant SKU and the English catalogue name (storefront
 * lib/tracking/items.ts). This resolves a SKU to its product's current names — English and
 * Bangla — so variants fold into one product; an unknown SKU keeps the name it was sent with.
 */
final class ProductNames
{
    /** @var array<string, array{key: string, name: string, name_bn: string|null}|null> */
    private array $known = [];

    /** @var array<string, true> */
    private array $wanted = [];

    /** @param  list<string>  $skus */
    public function want(array $skus): void
    {
        foreach ($skus as $sku) {
            if (! array_key_exists($sku, $this->known)) {
                $this->wanted[$sku] = true;
            }
        }
    }

    /** @return array{key: string, name: string, name_bn: string|null} */
    public function name(string $sku, string $sentName): array
    {
        $this->want([$sku]);
        $this->load();

        return $this->known[$sku] ?? ['key' => 'sku:'.$sku, 'name' => $sentName, 'name_bn' => null];
    }

    private function load(): void
    {
        if ($this->wanted === []) {
            return;
        }
        $skus = array_map(strval(...), array_keys($this->wanted));
        $this->wanted = [];
        foreach ($skus as $sku) {
            $this->known[$sku] = null;
        }
        foreach (array_chunk($skus, 500) as $chunk) {
            $rows = DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereIn('product_variants.sku', $chunk)
                ->get(['product_variants.sku', 'products.id', 'products.name', 'products.name_bn']);
            foreach ($rows as $row) {
                $this->known[(string) $row->sku] = [
                    'key' => 'product:'.$row->id,
                    'name' => (string) $row->name,
                    'name_bn' => $row->name_bn === null ? null : (string) $row->name_bn,
                ];
            }
        }
    }
}
