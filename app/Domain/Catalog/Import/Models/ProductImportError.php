<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One problem in an import file: spreadsheet row (header = 1; 0 = the file itself),
 * column and message.
 *
 * @property int $id
 * @property int $import_id
 * @property int $row_number
 * @property string|null $sku
 * @property string|null $column
 * @property string $message
 */
final class ProductImportError extends Model
{
    public $timestamps = false;

    protected $table = 'product_import_errors';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['row_number' => 'integer'];
    }

    /** @return BelongsTo<ProductImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ProductImport::class, 'import_id');
    }
}
