<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exceptions;

use RuntimeException;

/** Thrown inside the send job so the queue retries; after the last try the log dead-letters. */
final class NotificationSendFailed extends RuntimeException {}
