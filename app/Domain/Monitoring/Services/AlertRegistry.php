<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Alerts\BackupStaleCheck;
use App\Domain\Monitoring\Alerts\CourierBookingFailuresCheck;
use App\Domain\Monitoring\Alerts\CourierPollSilenceCheck;
use App\Domain\Monitoring\Alerts\CourierWebhookSilenceCheck;
use App\Domain\Monitoring\Alerts\DiskSpaceCheck;
use App\Domain\Monitoring\Alerts\FailedJobsCheck;
use App\Domain\Monitoring\Alerts\HorizonDownCheck;
use App\Domain\Monitoring\Alerts\NoOrdersCheck;
use App\Domain\Monitoring\Alerts\QueueDepthCheck;
use App\Domain\Monitoring\Alerts\RestoreRehearsalStaleCheck;
use App\Domain\Monitoring\Alerts\SmsFailuresCheck;
use App\Domain\Monitoring\Contracts\AlertCheck;
use Illuminate\Contracts\Container\Container;

/** Every business alert, by name. A new alert is a new AlertCheck added to CHECKS. */
final readonly class AlertRegistry
{
    /** @var list<class-string<AlertCheck>> */
    public const array CHECKS = [
        NoOrdersCheck::class,
        CourierBookingFailuresCheck::class,
        FailedJobsCheck::class,
        CourierPollSilenceCheck::class,
        CourierWebhookSilenceCheck::class,
        SmsFailuresCheck::class,
        BackupStaleCheck::class,
        RestoreRehearsalStaleCheck::class,
        HorizonDownCheck::class,
        QueueDepthCheck::class,
        DiskSpaceCheck::class,
    ];

    public function __construct(private Container $container) {}

    /** @return array<string, AlertCheck> */
    public function all(): array
    {
        $checks = [];
        foreach (self::CHECKS as $class) {
            $check = $this->container->make($class);
            $checks[$check->name()] = $check;
        }

        return $checks;
    }

    public function find(string $name): ?AlertCheck
    {
        return $this->all()[$name] ?? null;
    }
}
