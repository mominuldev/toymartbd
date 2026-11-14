<?php

declare(strict_types=1);

namespace App\Domain\Pos\Actions;

use App\Domain\Pos\Enums\PosSessionStatus;
use App\Domain\Pos\Exceptions\PosRefused;
use App\Domain\Pos\Models\PosSession;
use App\Domain\Pos\Queries\DrawerTotals;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Close a drawer with the cash counted in it. Under the session's row lock (a sale charging
 * at the same moment either commits first and is counted, or waits and is refused):
 *
 *   expected = opening float + cash kept from this session's sales (DrawerTotals)
 *   over_short = counted − expected        (negative = short)
 *
 * The three figures are stored on the session, which is then closed for good — the closed
 * row is the drawer's audit record (who, when, expected, counted, difference, note). The
 * register and the cashier are free to open a new drawer.
 */
final readonly class CloseRegisterSession
{
    public function __construct(private DrawerTotals $totals) {}

    /** @throws PosRefused when the session is already closed (409) */
    public function handle(PosSession $session, Money $counted, ?string $note, int $userId): PosSession
    {
        return DB::transaction(function () use ($session, $counted, $note, $userId): PosSession {
            /** @var PosSession $locked */
            $locked = PosSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! $locked->isOpen()) {
                throw PosRefused::sessionClosed();
            }

            $expected = $this->totals->for($locked)['expected_cash'];
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;

            $locked->forceFill([
                'status' => PosSessionStatus::Closed,
                'open_key' => null,
                'cashier_open_key' => null,
                'closed_by' => $userId,
                'closed_at' => now(),
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'over_short' => $counted->minus($expected),
                'close_note' => $note,
            ])->save();

            return $locked;
        });
    }
}
