<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers\Pathao;

use App\Domain\Fulfilment\Contracts\ReportsUncertainBooking;
use RuntimeException;
use Throwable;

/**
 * A Pathao call that did not produce a definite answer. Thrown (never returned) so the
 * calling job can retry and the circuit breaker can count it (CourierGateway contract).
 * Messages never carry a token, a credential or a request body (rule 20).
 *
 * `malformed` deserves care in the caller: a 2xx whose body we cannot read may still have
 * created the consignment, so a blind retry of `book()` can double-book. The booking job
 * should resolve it by `status()` / webhook rather than re-posting.
 */
final class PathaoException extends RuntimeException implements ReportsUncertainBooking
{
    public const string TRANSPORT = 'transport';

    public const string AUTH = 'auth';

    public const string MALFORMED = 'malformed';

    public const string NOT_FOUND = 'not_found';

    public const string NOT_CONFIGURED = 'not_configured';

    public const string UNKNOWN_STATUS = 'unknown_status';

    public const string UNMAPPED = 'location_unmapped';

    public function __construct(string $message, public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** A `malformed` 2xx may have created the consignment: the booking job must not re-post. */
    public function bookingMayHaveSucceeded(): bool
    {
        return $this->reason === self::MALFORMED;
    }

    public static function transport(string $operation, ?int $status = null, ?Throwable $previous = null): self
    {
        $detail = $status === null ? ($previous === null ? 'no response' : class_basename($previous)) : 'HTTP '.$status;

        return new self("Pathao {$operation} failed in transport ({$detail}).", self::TRANSPORT, $previous);
    }

    public static function auth(string $message): self
    {
        return new self('Pathao refused our credentials: '.$message, self::AUTH);
    }

    public static function malformed(string $operation): self
    {
        return new self("Pathao {$operation} returned a response we could not read.", self::MALFORMED);
    }

    public static function notFound(string $consignmentId): self
    {
        return new self("Pathao has no consignment {$consignmentId}.", self::NOT_FOUND);
    }

    public static function notConfigured(): self
    {
        return new self('Pathao is not configured: no client credentials on the provider row or in env.', self::NOT_CONFIGURED);
    }

    public static function unknownStatus(string $raw): self
    {
        return new self("Pathao reported a status we do not know: \"{$raw}\". Add it to PathaoGateway::statusMap().", self::UNKNOWN_STATUS);
    }

    public static function unmapped(): self
    {
        return new self('This address has no mapped Pathao city and zone. Map it under Shipments → Location mapping; we never guess a zone.', self::UNMAPPED);
    }
}
