<?php

declare(strict_types=1);

use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class MoneyCastProbe extends Model
{
    protected $table = 'money_cast_probes';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['total' => MoneyCast::class];
    }
}

beforeEach(function (): void {
    Schema::create('money_cast_probes', function (Blueprint $table): void {
        $table->id();
        $table->decimal('total', 14, 2)->nullable();
    });
});

it('writes DECIMAL(14,2) and reads back Money', function (): void {
    $row = MoneyCastProbe::query()->create(['total' => Money::of('2450.5')]);

    $fresh = MoneyCastProbe::query()->findOrFail($row->id);

    expect($fresh->total)->toBeInstanceOf(Money::class)
        ->and($fresh->total->toDecimal())->toBe('2450.50');
});

it('accepts a decimal string and null', function (): void {
    expect(MoneyCastProbe::query()->create(['total' => '70'])->fresh()?->total?->toDecimal())->toBe('70.00')
        ->and(MoneyCastProbe::query()->create(['total' => null])->fresh()?->total)->toBeNull();
});

it('rejects a float', function (): void {
    MoneyCastProbe::query()->create(['total' => 0.1 + 0.2]);
})->throws(InvalidArgumentException::class);
