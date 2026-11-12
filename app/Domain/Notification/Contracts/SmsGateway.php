<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

use App\Domain\Notification\DTOs\SmsResult;

/**
 * Sends one SMS. A new provider is a new driver behind this contract (CLAUDE.md rule 5).
 *
 * Implementations make an external HTTP call, so they are only ever called from a queued
 * job after commit — never inside a database transaction (rule 4). They must not throw
 * for a provider-side rejection; they return SmsResult::rejected(). They never log the
 * full phone number or the body (rule 20).
 *
 * @param  string  $e164Phone  "+8801XXXXXXXXX"
 * @param  string  $template  the template key ("otp.login", "order.placed", …) for cost
 *                            attribution and logging
 */
interface SmsGateway
{
    public function send(string $e164Phone, string $body, string $template): SmsResult;
}
