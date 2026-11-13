<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Mail;

use App\Domain\Reporting\Enums\ReportFileFormat;
use App\Domain\Reporting\Exports\Spreadsheet;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Support\DateRange;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A scheduled report (P8): a short summary in the body (EN or BN, the shared mail layout)
 * and the full report attached as CSV or XLSX. Figures are the server's (rule 1); dates are
 * Dhaka dates (rule 11); numerals Western in both languages (rule 36).
 */
final class ScheduledReportMail extends Mailable
{
    use Queueable;

    public readonly string $subjectLine;

    public readonly string $preheader;

    public readonly string $periodLabel;

    public readonly string $reportLabel;

    public readonly string $adminUrl;

    public readonly string $scheduleName;

    public readonly string $frequencyLabel;

    public readonly string $formatLabel;

    /** @var list<array{label: string, value: string}> */
    public readonly array $headline;

    public readonly bool $profitHidden;

    private readonly string $filename;

    private readonly string $bytes;

    private readonly string $mime;

    /** @param  array{headline: list<array{label: string, value: string}>, sheets: list<\App\Domain\Reporting\Exports\Sheet>, profit_hidden: bool}  $built */
    public function __construct(ReportSchedule $schedule, DateRange $range, array $built, public readonly string $mailLocale)
    {
        $this->locale($mailLocale);
        $type = $schedule->report_type->value;
        $this->reportLabel = (string) __('analytics.mail.report.'.$type, [], $mailLocale);
        $this->periodLabel = $range->fromDate() === $range->toDate() ? $range->fromDate() : $range->fromDate().' – '.$range->toDate();
        $this->subjectLine = (string) __('analytics.mail.subject', ['report' => $this->reportLabel, 'period' => $this->periodLabel], $mailLocale);
        $this->preheader = (string) __('analytics.mail.preheader', ['report' => $this->reportLabel, 'period' => $this->periodLabel], $mailLocale);
        $this->frequencyLabel = (string) __('analytics.mail.frequency.'.$schedule->frequency->value, [], $mailLocale);
        $this->formatLabel = strtoupper($schedule->format->value);
        $this->scheduleName = $schedule->name;
        $this->headline = $built['headline'];
        $this->profitHidden = $built['profit_hidden'];
        $query = $schedule->report_type->value === 'pnl'
            ? '?from='.$range->fromDate().'&to='.$range->toDate().'&preset=custom'
            : '?range=custom&from='.$range->fromDate().'&to='.$range->toDate();
        $this->adminUrl = rtrim((string) config('app.url'), '/').$schedule->report_type->adminPath().$query;

        $format = $schedule->format === ReportFileFormat::Xlsx ? Spreadsheet::XLSX : Spreadsheet::CSV;
        $this->filename = "{$type}-report-{$range->fromDate()}-to-{$range->toDate()}.{$format}";
        $this->bytes = Spreadsheet::contents($format, $built['sheets']);
        $this->mime = Spreadsheet::contentType($format);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(html: 'mail.scheduled-report', text: 'mail.scheduled-report-text');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->bytes, $this->filename)->withMime($this->mime)];
    }

    public function attachmentName(): string
    {
        return $this->filename;
    }
}
