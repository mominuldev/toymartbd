<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A stock location (DATABASE-DESIGN §9): a warehouse, retail branch or pickup point.
 * The migration seeds the default one; `code` is the short label the inventory overview
 * shows for it ("CTG 4 · SYL 0"), `description` what it is for.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $description
 * @property string|null $description_bn
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockLocation extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'name_bn', 'description', 'description_bn', 'is_default', 'is_active'];

    public static function defaultId(): int
    {
        $id = self::query()->where('is_default', true)->orderBy('id')->value('id');

        return $id === null ? throw new RuntimeException('No default stock location is seeded.') : (int) $id;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
