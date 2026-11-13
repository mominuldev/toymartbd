<?php

declare(strict_types=1);

use App\Domain\Reporting\Models\ReportSchedule;
use App\Models\Enums\Permission as P;
use App\Models\Enums\Role;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every P8 Reporting endpoint (CLAUDE.md rule 21), derived from
 * Role::permissions() — the matrix the seeder reads.
 */

/** @param  list<P>  $all */
function reportingAllowed(Role $role, array $all): bool
{
    return array_diff(array_map(fn (P $p): string => $p->value, $all), $role->permissionNames()) === [];
}

dataset('reporting reads', [
    'sales analytics' => ['analytics/sales', [P::ReportsView]],
    'sales export' => ['analytics/sales/export', [P::ReportsView, P::ReportsExport]],
    'schedules' => ['reports/schedules', [P::ReportsView, P::ReportsExport]],
    'schedule recipients' => ['reports/schedules/recipients', [P::ReportsView, P::ReportsExport]],
]);

it('guards each reporting read for every role', function (string $uri, array $all): void {
    foreach (Role::cases() as $role) {
        RoleMatrix::assertFor($this, $role, reportingAllowed($role, $all), fn () => $this->get('/api/v1/admin/'.$uri, ['Accept' => 'application/json']));
    }
})->with('reporting reads');

it('guards a schedule\'s show, runs, update and delete for every role', function (): void {
    $schedule = ReportSchedule::query()->create(['name' => 'Daily', 'report_type' => 'sales', 'frequency' => 'daily', 'send_time' => '08:00', 'locale' => 'en', 'format' => 'csv', 'is_active' => true]);
    $allowed = fn (Role $role): bool => reportingAllowed($role, [P::ReportsView, P::ReportsExport]);

    foreach (Role::cases() as $role) {
        RoleMatrix::assertFor($this, $role, $allowed($role), fn () => $this->getJson("/api/v1/admin/reports/schedules/{$schedule->id}"));
        RoleMatrix::assertFor($this, $role, $allowed($role), fn () => $this->getJson("/api/v1/admin/reports/schedules/{$schedule->id}/runs"));
        // An empty PUT is a 422 for an allowed role (authorised, then invalid) and 403 otherwise.
        RoleMatrix::assertFor($this, $role, $allowed($role), fn () => $this->putJson("/api/v1/admin/reports/schedules/{$schedule->id}", []), 422);
        RoleMatrix::assertFor($this, $role, $allowed($role), fn () => $this->postJson('/api/v1/admin/reports/schedules', []), 422);
    }
    // Delete last: only super_admin, manager and accountant may, and the first success removes it.
    $roles = array_values(array_filter(Role::cases(), $allowed));
    expect($roles)->toBe([Role::SuperAdmin, Role::Manager, Role::Accountant]);
    foreach (array_filter(Role::cases(), fn (Role $r): bool => ! $allowed($r)) as $role) {
        RoleMatrix::assertFor($this, $role, false, fn () => $this->deleteJson("/api/v1/admin/reports/schedules/{$schedule->id}"));
    }
    RoleMatrix::assertFor($this, Role::Accountant, true, fn () => $this->deleteJson("/api/v1/admin/reports/schedules/{$schedule->id}"), 204);
});

it('refuses a guest with 401', function (string $uri): void {
    $this->getJson('/api/v1/admin/'.$uri)->assertUnauthorized();
})->with(['analytics/sales', 'analytics/sales/export', 'reports/schedules']);
