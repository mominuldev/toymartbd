<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Logging `tap` applied to every channel in config/logging.php.
 *
 * Works on the HANDLERS rather than the logger, for two reasons: a `stack` channel
 * collects its children's handlers, so the redaction travels with them; and handler
 * processors run after Laravel's ContextLogProcessor has added `request_id` and the
 * rest of the Context to `extra`, so shared context is scrubbed too.
 *
 * Idempotent: a handler already carrying the redaction is left alone.
 */
final class RedactLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $formatter = $handler->getFormatter();

                if ($formatter instanceof RedactingFormatter) {
                    continue;
                }

                $handler->setFormatter(new RedactingFormatter($formatter));
            }

            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(new RedactSensitiveData);
            }
        }
    }
}
