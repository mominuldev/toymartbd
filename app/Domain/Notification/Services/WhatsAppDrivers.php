<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\Drivers\CloudWhatsAppGateway;
use App\Domain\Notification\Drivers\LogWhatsAppGateway;
use App\Domain\Notification\Drivers\NullWhatsAppGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the WhatsApp notification driver (rule 5): Admin → Integrations → Messaging first,
 * `notifications.whatsapp.driver` (WHATSAPP_DRIVER) as the fallback. `log` and `null` send
 * nothing; `cloud` is the WhatsApp Cloud API on the Inbox's number (UNVERIFIED).
 */
final readonly class WhatsAppDrivers
{
    public function __construct(private Container $container, private MessagingSettings $settings) {}

    public function name(): string
    {
        return $this->settings->whatsappDriver();
    }

    public function driver(): WhatsAppGateway
    {
        return match ($this->name()) {
            'log' => $this->container->make(LogWhatsAppGateway::class),
            'null' => $this->container->make(NullWhatsAppGateway::class),
            'cloud' => $this->container->make(CloudWhatsAppGateway::class),
            default => throw new InvalidArgumentException('Unknown WhatsApp driver ['.$this->name().'].'),
        };
    }
}
