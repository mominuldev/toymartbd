<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Actions;

use App\Domain\Ai\Advisor\Enums\ActionStatus;
use App\Domain\Ai\Advisor\Exceptions\ActionAlreadyDecided;
use App\Domain\Ai\Advisor\Models\AiBriefAction;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Marks one suggested action done or not useful, recording who and when. Only an open action. */
final readonly class DecideBriefAction
{
    public function handle(AiBriefAction $action, ActionStatus $status, ?int $userId): AiBriefAction
    {
        if ($status === ActionStatus::Open) {
            throw new InvalidArgumentException('An action is decided as done or dismissed.');
        }

        return DB::transaction(static function () use ($action, $status, $userId): AiBriefAction {
            $row = AiBriefAction::query()->whereKey($action->getKey())->lockForUpdate()->firstOrFail();
            if ($row->status !== ActionStatus::Open) {
                throw new ActionAlreadyDecided;
            }
            $row->forceFill(['status' => $status, 'decided_by' => $userId, 'decided_at' => DhakaDate::instant()])->save();

            return $row;
        });
    }
}
