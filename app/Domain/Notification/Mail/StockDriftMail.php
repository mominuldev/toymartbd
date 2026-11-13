<?php

declare(strict_types=1);

namespace App\Domain\Notification\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The nightly reconciliation found stock-ledger drift (DATABASE-DESIGN §7). Lists the
 * new or changed drifts; nothing has been corrected — a person must investigate and
 * acknowledge each one in Inventory → Alerts.
 */
final class StockDriftMail extends Mailable
{
    use Queueable;

    public readonly string $subjectLine;

    public readonly string $adminUrl;

    /**
     * @param  list<array{alert_id: int, kind: string, variant_id: int, sku: string, product_name: string, location_code: string, expected: int, actual: int}>  $drifts
     */
    public function __construct(
        public readonly array $drifts,
        public readonly int $openDriftCount,
        public readonly CarbonImmutable $checkedAt,
        public readonly string $mailLocale,
    ) {
        $this->locale($mailLocale);
        $this->subjectLine = __('inventory.mail.drift_subject', ['count' => count($drifts)], $mailLocale);
        $this->adminUrl = rtrim((string) config('app.url'), '/').'/admin/inventory/alerts';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(html: 'mail.inventory-drift', text: 'mail.inventory-drift-text');
    }
}
