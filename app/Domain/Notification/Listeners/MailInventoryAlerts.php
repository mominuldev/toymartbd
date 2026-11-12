<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Inventory\Events\InventoryDigestCompiled;
use App\Domain\Inventory\Events\StockDriftDetected;
use App\Domain\Notification\Mail\InventoryDigestMail;
use App\Domain\Notification\Mail\StockDriftMail;
use App\Models\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Staff-facing inventory mail (P5-INV): the daily stock digest and the reconciliation's
 * drift alert, to every active manager and super admin. Email only — staff alerts are
 * not worth an SMS or WhatsApp spend, and the admin Alerts panel holds the same rows.
 * Queued on `notify`; the mail is built from the event's plain rows (no Inventory read).
 * Staff mail is English for now: users carry no language preference yet.
 */
final class MailInventoryAlerts implements ShouldQueue
{
    public string $queue = 'notify';

    public int $tries = 3;

    public function onDigest(InventoryDigestCompiled $event): void
    {
        $recipients = self::managers();
        if ($recipients->isEmpty()) {
            return;
        }

        Mail::to($recipients)->send(new InventoryDigestMail($event->digest, 'en'));
    }

    public function onDrift(StockDriftDetected $event): void
    {
        $recipients = self::managers();
        if ($recipients->isEmpty()) {
            return;
        }

        Mail::to($recipients)->send(new StockDriftMail($event->drifts, $event->openDriftCount, $event->checkedAt, 'en'));
    }

    /** @return Collection<int, User> */
    public static function managers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->whereHas('roles', static fn (Builder $q): Builder => $q->whereIn('name', [Role::SuperAdmin->value, Role::Manager->value]))
            ->orderBy('id')
            ->get();
    }
}
