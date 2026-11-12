<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Models;

use App\Domain\Catalog\Import\Enums\ProductImportStatus;
use App\Domain\Catalog\Import\Enums\SpreadsheetFormat;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An uploaded product import file and its latest run (P4-CAT-10).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $original_name
 * @property string $path
 * @property SpreadsheetFormat $format
 * @property ProductImportStatus $status
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $create_count
 * @property int $update_count
 * @property int $error_count
 * @property string|null $failure_message
 * @property Carbon|null $previewed_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
final class ProductImport extends Model
{
    /** Where uploaded files are kept until the import completes (a private disk). */
    public const string DISK = 'local';

    protected $table = 'product_imports';

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'total_rows' => 0,
        'processed_rows' => 0,
        'create_count' => 0,
        'update_count' => 0,
        'error_count' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'format' => SpreadsheetFormat::class,
            'status' => ProductImportStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'create_count' => 'integer',
            'update_count' => 'integer',
            'error_count' => 'integer',
            'previewed_at' => 'datetime',
            'committed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ProductImportError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(ProductImportError::class, 'import_id');
    }
}
