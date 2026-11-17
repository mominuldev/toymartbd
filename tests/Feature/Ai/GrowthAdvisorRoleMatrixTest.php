<?php

declare(strict_types=1);

use App\Domain\Ai\Advisor\Models\AiBrief;
use App\Domain\Ai\Advisor\Models\AiBriefAction;
use App\Domain\Ai\Services\AiDrivers;
use App\Models\Enums\Permission;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every Growth advisor endpoint (rule 21): reports.view throughout.
 * No AI key is set, so an allowed brief or question is 409 ai-not-configured — authorised,
 * then refused — and the provider is never reached.
 */

beforeEach(function (): void {
    AiDrivers::fake();
});

function advisorMatrixAction(): AiBriefAction
{
    $brief = AiBrief::query()->first() ?? AiBrief::query()->create(['week_start' => '2026-11-16',
        'period_from' => '2026-11-16', 'period_to' => '2026-11-18', 'locale' => 'en', 'provider' => 'anthropic',
        'model' => 'claude-opus-5-5', 'sentences' => ['Quiet week.'], 'generated_at' => now(),
    ]);

    return $brief->actions()->create(['priority' => 'high', 'kind' => 'other', 'title' => 'Look', 'why' => 'Because.', 'figures' => [], 'target_type' => 'none', 'status' => 'open']);
}

it('guards GET /analytics/advisor', function (): void {
    RoleMatrix::assert($this, Permission::ReportsView, fn () => $this->getJson('/api/v1/admin/analytics/advisor'));
});

it('guards POST /analytics/advisor/brief', function (): void {
    RoleMatrix::assert($this, Permission::ReportsView, fn () => $this->postJson('/api/v1/admin/analytics/advisor/brief'), 409);
});

it('guards POST /analytics/advisor/ask', function (): void {
    RoleMatrix::assert($this, Permission::ReportsView, fn () => $this->postJson('/api/v1/admin/analytics/advisor/ask', ['question' => 'How did sales go?']), 409);
});

it('guards marking a suggestion done', function (): void {
    RoleMatrix::assert($this, Permission::ReportsView, fn () => $this->postJson('/api/v1/admin/analytics/advisor/actions/'.advisorMatrixAction()->id.'/done'));
});

it('guards marking a suggestion not useful', function (): void {
    RoleMatrix::assert($this, Permission::ReportsView, fn () => $this->postJson('/api/v1/admin/analytics/advisor/actions/'.advisorMatrixAction()->id.'/dismiss'));
});

it('refuses a guest with 401', function (): void {
    $this->getJson('/api/v1/admin/analytics/advisor')->assertUnauthorized();
    $this->postJson('/api/v1/admin/analytics/advisor/ask', ['question' => 'Hi there'])->assertUnauthorized();
});
