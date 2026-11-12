<?php

declare(strict_types=1);

namespace App\Domain\Notification\Drivers;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\DTOs\SmsResult;
use App\Domain\Notification\Support\SmsSegments;

/**
 * UNVERIFIED SKELETON — Alpha SMS / sms.bd (formerly sms.net.bd).
 *
 * What the research established (docs/_research/agent4-bd-market-and-payments.md §4.1):
 * a "RESTful" GET/POST API at `https://api.sms.net.bd/sendsms` with `api_key` as a
 * request parameter, JSON responses, plus `/report/request/{id}/` and `/user/balance/`.
 * What it did NOT establish: the names of the recipient and message parameters, the
 * response shape, the error codes, or how the sender id (masking) is passed.
 *
 * Until those are read from the provider's own documentation with a merchant account,
 * this driver refuses every send (returns a rejection, so the job retries and then
 * dead-letters visibly) rather than guessing an endpoint contract. No merchant account
 * exists (as of 2026-11-12); keep `notifications.sms.drivers.sms_net_bd.verified=false`.
 */
final class SmsNetBdGateway implements SmsGateway
{
    public function send(string $e164Phone, string $body, string $template): SmsResult
    {
        if (config('notifications.sms.drivers.sms_net_bd.verified') !== true) {
            return SmsResult::rejected('sms_net_bd driver is unverified: request/response contract not yet confirmed against provider documentation.', SmsSegments::count($body));
        }

        // Deliberately unimplemented: see the class docblock. Implement against the
        // provider's published API, with hand-authored fixtures, before flipping `verified`.
        return SmsResult::rejected('sms_net_bd driver is not implemented.', SmsSegments::count($body));
    }
}
