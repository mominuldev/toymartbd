<?php

declare(strict_types=1);

use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Exceptions\ImmutableRecordException;
use App\Models\User;
use Illuminate\Database\QueryException;

function auditRow(): AuditSensitive
{
    $user = User::factory()->create();

    return AuditSensitive::query()->create([
        'user_id' => $user->id,
        'action' => AuditAction::PriceChanged,
        'model_type' => 'product_variant',
        'model_id' => 42,
        'before' => ['price' => '1250.00', 'name' => 'কাঠের পাজল'],
        'after' => ['price' => '1190.00', 'name' => 'কাঠের পাজল'],
        'ip' => '203.0.113.7',
        'user_agent' => 'Pest',
    ]);
}

it('inserts and reads back an audit row', function (): void {
    $audit = auditRow()->fresh();

    expect($audit)->not->toBeNull()
        ->and($audit->action)->toBe(AuditAction::PriceChanged)
        ->and($audit->before)->toEqual(['price' => '1250.00', 'name' => 'কাঠের পাজল'])
        ->and($audit->created_at)->not->toBeNull()
        ->and(AuditSensitive::UPDATED_AT)->toBeNull();
});

it('refuses to update a model instance', function (): void {
    $audit = auditRow();
    $audit->after = ['price' => '1.00'];
    $audit->save();
})->throws(ImmutableRecordException::class);

it('refuses Model::update()', function (): void {
    auditRow()->update(['ip' => '198.51.100.1']);
})->throws(ImmutableRecordException::class);

it('refuses to delete a model instance', function (): void {
    auditRow()->delete();
})->throws(ImmutableRecordException::class);

it('refuses bulk writes through the query builder', function (string $operation): void {
    auditRow();

    $query = AuditSensitive::query();

    match ($operation) {
        'update' => $query->update(['ip' => null]),
        'delete' => $query->delete(),
        'forceDelete' => $query->forceDelete(),
        'increment' => $query->increment('model_id'),
        'decrement' => $query->decrement('model_id'),
        'upsert' => $query->upsert([['id' => 1, 'action' => 'x']], ['id']),
        'touch' => $query->touch(),
    };
})->with(['update', 'delete', 'forceDelete', 'increment', 'decrement', 'upsert', 'touch'])
    ->throws(ImmutableRecordException::class);

it('leaves the row untouched after a refused write', function (): void {
    $audit = auditRow();

    try {
        $audit->update(['ip' => '198.51.100.1']);
    } catch (ImmutableRecordException) {
        // expected
    }

    expect(AuditSensitive::query()->count())->toBe(1)
        ->and(AuditSensitive::query()->first()?->ip)->toBe('203.0.113.7');
});

it('prevents deleting a user who has audit history', function (): void {
    $audit = auditRow();

    User::query()->whereKey($audit->user_id)->delete();
})->throws(QueryException::class);
