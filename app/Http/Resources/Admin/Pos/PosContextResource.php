<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Catalog\Models\Category;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Pos\Tenders\TenderDriver;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Everything the register needs before the first scan: the branches a drawer can open at,
 * the tenders (with which need a reference), the category tabs, the signed-in cashier's open
 * drawer (or null), and whether a WhatsApp receipt can be sent — it cannot: no WhatsApp
 * provider is live, so `whatsapp_receipt.enabled` is false with the reason.
 *
 * @property array{locations: Collection<int, StockLocation>, tenders: list<TenderDriver>, categories: Collection<int, Category>, session: PosSessionResource|null, can_manage: bool} $resource
 */
#[SchemaName('PosContext')]
final class PosContextResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var list<array{id: int, code: string, name: string, name_bn: string|null, type: string|null}> */
            'locations' => $this->resource['locations']->map(static fn (StockLocation $l): array => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'name_bn' => $l->name_bn, 'type' => $l->type?->value,
            ])->values()->all(),
            /** @var list<array{method: 'cash'|'bkash'|'nagad'|'card', requires_reference: bool, gives_change: bool}> */
            'tenders' => array_map(static fn (TenderDriver $t): array => [
                'method' => $t->method()->value, 'requires_reference' => $t->requiresReference(), 'gives_change' => $t->givesChange(),
            ], $this->resource['tenders']),
            /** @var list<array{id: int, name: string, name_bn: string|null}> */
            'categories' => $this->resource['categories']->map(static fn (Category $c): array => [
                'id' => $c->id, 'name' => $c->name, 'name_bn' => $c->name_bn,
            ])->values()->all(),
            /** @var PosSessionResource|null */
            'session' => $this->resource['session'],
            /** @var bool */
            'can_manage' => $this->resource['can_manage'],
            'whatsapp_receipt' => [
                'enabled' => false,
                /** @var 'no_provider' */
                'reason' => 'no_provider',
            ],
        ];
    }
}
