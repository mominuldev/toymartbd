<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One business-alert notice to the owner channel (P10). Sent synchronously — never queued,
 * because "the queue is down" is one of the things it reports. Staff mail is English for now
 * (users carry no language preference yet); the strings live in lang/{en,bn}/monitoring.php.
 */
final class MonitoringAlertMail extends Mailable
{
    public readonly string $subjectLine;

    public function __construct(
        public readonly string $alertName,
        public readonly string $severity,
        public readonly string $kind,
        public readonly string $summary,
        public readonly string $checkedAt,
        public readonly bool $isTest,
        public readonly string $mailLocale = 'en',
    ) {
        $this->locale($mailLocale);
        $this->subjectLine = ($isTest ? '['.__('monitoring.mail.test_tag', [], $mailLocale).'] ' : '')
            .__('monitoring.mail.subject_'.$kind, ['name' => __('monitoring.names.'.$alertName, [], $mailLocale), 'severity' => __('monitoring.severity.'.$severity, [], $mailLocale)], $mailLocale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(html: 'mail.monitoring-alert', text: 'mail.monitoring-alert-text');
    }
}
