<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Services;

use App\Support\DhakaDate;
use Carbon\CarbonImmutable;

/**
 * The week a brief covers: Dhaka calendar days (rule 11), Saturday to Friday — the shop's week,
 * the same as the sales reports and the weekly report email (owner decision 2026-11-12).
 *
 * The week is the one containing *yesterday*, and it ends at yesterday at the latest: today is
 * never counted, because it is not over. On Saturday morning — when the scheduled brief is
 * written — that is the whole week just finished; Sunday to Friday it is this week to date. It
 * compares against the same weekdays one week earlier, so a part-week is compared fairly.
 */
final readonly class AdvisorWeek
{
    private function __construct(
        public string $weekStart,
        public string $from,
        public string $to,
        public string $compareFrom,
        public string $compareTo,
    ) {}

    public static function current(?string $today = null): self
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $today ?? DhakaDate::today(), 'UTC');
        assert($day instanceof CarbonImmutable);
        $yesterday = $day->subDay();
        $start = $yesterday->subDays(($yesterday->dayOfWeek - CarbonImmutable::SATURDAY + 7) % 7);
        $end = $start->addDays(6);
        $to = $yesterday->lessThan($end) ? $yesterday : $end;

        return new self(
            $start->toDateString(),
            $start->toDateString(),
            $to->toDateString(),
            $start->subWeek()->toDateString(),
            $to->subWeek()->toDateString(),
        );
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    public function complete(): bool
    {
        return $this->days() === 7;
    }
}
