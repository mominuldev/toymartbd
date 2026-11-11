<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Enums\SettingGroup;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed, grouped key/value setting (DATABASE-DESIGN §3.10, PRD P-04).
 *
 * `value` is stored as JSON, so it reads back with its original type. When `is_encrypted`
 * is true the JSON holds a Laravel-encrypted payload (credentials — rule 20); set
 * `is_encrypted` before `value`, or use {@see self::put()} which does it in order.
 *
 * @property string $key
 * @property mixed $value
 * @property SettingGroup $group
 * @property bool $is_encrypted
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(name: 'settings', key: 'key', keyType: 'string', incrementing: false)]
class Setting extends Model
{
    /** @var list<string> */
    protected $fillable = ['key', 'group', 'is_encrypted', 'value', 'updated_by'];

    /** Encrypted values never leave the model through toArray()/toJson(). */
    protected $hidden = ['value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->find($key);

        return $setting instanceof self ? $setting->value : $default;
    }

    public static function put(
        string $key,
        mixed $value,
        SettingGroup $group,
        bool $encrypted = false,
        ?int $updatedBy = null,
    ): self {
        $setting = static::query()->findOrNew($key);
        $setting->key = $key;
        $setting->group = $group;
        $setting->is_encrypted = $encrypted;
        $setting->value = $value;
        $setting->updated_by = $updatedBy;
        $setting->save();

        return $setting;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'group' => SettingGroup::class,
            'is_encrypted' => 'boolean',
        ];
    }

    /**
     * @return Attribute<mixed, mixed>
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: static function (?string $raw, array $attributes): mixed {
                if ($raw === null) {
                    return null;
                }

                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                if (! (bool) ($attributes['is_encrypted'] ?? false)) {
                    return $decoded;
                }

                return json_decode(Crypt::decryptString((string) $decoded), true, 512, JSON_THROW_ON_ERROR);
            },
            set: static function (mixed $value, array $attributes): string {
                $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

                if (! (bool) ($attributes['is_encrypted'] ?? false)) {
                    return $json;
                }

                return json_encode(Crypt::encryptString($json), JSON_THROW_ON_ERROR);
            },
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
