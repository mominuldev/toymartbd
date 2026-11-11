<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Support\Facades\Context;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;

/**
 * Sentry `before_send` / `before_breadcrumb` hooks (config/sentry.php).
 *
 * Tags every event with the request id from the Context — set by AssignRequestId on
 * a web request and re-hydrated on a queue worker — and applies the same redaction
 * as the logs: phones masked, sensitive keys `[redacted]`, in the message, exception
 * values, stack-frame vars, request data, extra, contexts and breadcrumbs. With
 * `send_default_pii=false` the SDK already omits cookies, headers carrying
 * credentials and the user's IP; this is the second line.
 *
 * Static methods, so config/sentry.php can reference them as array callables and
 * survive `config:cache`.
 */
final class SentryEventScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $requestId = Context::get(RequestId::CONTEXT_KEY);

        if (is_string($requestId) && $requestId !== '') {
            $event->setTag(RequestId::CONTEXT_KEY, $requestId);
        }

        $message = $event->getMessage();

        if ($message !== null) {
            $event->setMessage(
                RedactSensitiveData::scrubText($message),
                RedactSensitiveData::redactArray($event->getMessageParams()),
                $event->getMessageFormatted() === null ? null : RedactSensitiveData::scrubText($event->getMessageFormatted()),
            );
        }

        foreach ($event->getExceptions() as $exception) {
            self::scrubException($exception);
        }

        $event->setRequest(RedactSensitiveData::redactArray($event->getRequest()));
        $event->setExtra(RedactSensitiveData::redactArray($event->getExtra()));

        foreach ($event->getContexts() as $name => $data) {
            $event->setContext((string) $name, RedactSensitiveData::redactArray($data));
        }

        $tags = [];
        foreach ($event->getTags() as $key => $value) {
            $tags[$key] = RedactSensitiveData::isSensitiveKey($key) ? RedactSensitiveData::REDACTED : RedactSensitiveData::scrubText($value);
        }
        $event->setTags($tags);

        $user = $event->getUser();

        if ($user !== null) {
            // Keep the id for "how many users hit this"; drop anything identifying.
            $user->setEmail(null)->setIpAddress(null)->setUsername(null);
        }

        $event->setBreadcrumb(array_map(self::beforeBreadcrumb(...), $event->getBreadcrumbs()));

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage(RedactSensitiveData::scrubText($message));
        }

        foreach (RedactSensitiveData::redactArray($breadcrumb->getMetadata()) as $key => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string) $key, $value);
        }

        return $breadcrumb;
    }

    private static function scrubException(ExceptionDataBag $exception): void
    {
        $exception->setValue(RedactSensitiveData::scrubText($exception->getValue()));

        foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
            self::scrubFrame($frame);
        }
    }

    private static function scrubFrame(Frame $frame): void
    {
        $frame->setVars(RedactSensitiveData::redactArray($frame->getVars()));

        // Source lines shipped with the frame can hold a literal number (a fixture, a seeder).
        $frame->setPreContext(array_map(self::maskLine(...), $frame->getPreContext()));
        $frame->setContextLine(self::maskLine($frame->getContextLine()));
        $frame->setPostContext(array_map(self::maskLine(...), $frame->getPostContext()));
    }

    private static function maskLine(?string $line): ?string
    {
        return $line === null ? null : RedactSensitiveData::maskPhones($line);
    }
}
