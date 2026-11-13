<?php

declare(strict_types=1);

namespace App\Domain\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The managers' daily stock digest (P5-INV, PRD I-09). Built from InventoryDigest's plain
 * array; every figure is the server's (rule 1). Sent from MailInventoryAlerts.
 */
final class InventoryDigestMail extends Mailable
{
    use Queueable;

    public readonly string $subjectLine;

    public readonly string $adminUrl;

    /** @param  array<string, mixed>  $digest  InventoryDigest::compile() */
    public function __construct(
        public readonly array $digest,
        public readonly string $mailLocale,
    ) {
        $this->locale($mailLocale);
        $this->subjectLine = __('inventory.mail.digest_subject', ['date' => (string) $digest['date']], $mailLocale);
        $this->adminUrl = rtrim((string) config('app.url'), '/').'/admin/inventory';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(html: 'mail.inventory-digest', text: 'mail.inventory-digest-text');
    }
}
