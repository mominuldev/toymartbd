<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\Drivers\LogSmsGateway;
use App\Domain\Notification\Drivers\NullSmsGateway;
use App\Domain\Notification\Drivers\ReveSmsGateway;
use App\Domain\Notification\Drivers\SmsNetBdGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the configured raw SMS driver (rule 5: a new provider is a class plus one arm
 * here). The container's SmsGateway binding wraps this driver in RecordingSmsGateway so
 * a direct send (Customer's OTP job) still lands in notification_logs with its cost; the
 * notification pipeline uses the raw driver because it keeps its own log row.
 */
final readonly class SmsDrivers
{
    public function __construct(private Container $container, private MessagingSettings $settings) {}

    public function name(): string
    {
        // Admin → Integrations → Messaging first, SMS_DRIVER env as the fallback.
        return $this->settings->smsDriver();
    }

    public function driver(): SmsGateway
    {
        return match ($this->name()) {
            'log' => $this->container->make(LogSmsGateway::class),
            'null' => $this->container->make(NullSmsGateway::class),
            'reve' => $this->container->make(ReveSmsGateway::class),
            'sms_net_bd' => $this->container->make(SmsNetBdGateway::class),
            default => throw new InvalidArgumentException('Unknown SMS driver ['.$this->name().'].'),
        };
    }
}
