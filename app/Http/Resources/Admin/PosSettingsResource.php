<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Pos\Services\PosDiscountSettings;
use App\Domain\Pos\Services\PosDueSettings;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Store settings › Point of sale: the cap on a cashier's discount (rule 33d) and the due limit
 * per customer (rule 33c). Each `*_default` is what applies until the owner saves a value;
 * `*_saved` says whether one has been saved.
 *
 * @property array{discount_max_percent: string, discount_saved: bool, due_limit: string, due_saved: bool} $resource
 */
#[SchemaName('PosSettings')]
final class PosSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** e.g. `10` */
            'discount_max_percent' => $this->resource['discount_max_percent'],
            /**
             * e.g. `10`
             *
             * @var string
             */
            'discount_max_percent_default' => PosDiscountSettings::DEFAULT_MAX_PERCENT,
            /**
             * False while the store default applies (nobody has saved a cap yet).
             *
             * @var bool
             */
            'discount_saved' => $this->resource['discount_saved'],
            /** Per customer, e.g. `5000.00`. */
            'due_limit' => $this->resource['due_limit'],
            /**
             * e.g. `5000.00`
             *
             * @var string
             */
            'due_limit_default' => PosDueSettings::DEFAULT_LIMIT,
            /**
             * False while the store default applies.
             *
             * @var bool
             */
            'due_saved' => $this->resource['due_saved'],
        ];
    }
}
