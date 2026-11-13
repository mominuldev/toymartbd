{{ $reportLabel }}

{{ __('analytics.mail.intro', ['frequency' => $frequencyLabel, 'period' => $periodLabel, 'format' => $formatLabel], $mailLocale) }}

{{ __('analytics.mail.figures', [], $mailLocale) }}
@foreach ($headline as $row)
- {{ $row['label'] }}: {{ $row['value'] }}
@endforeach

{{ __('analytics.mail.note_revenue', [], $mailLocale) }}
@if ($profitHidden)
{{ __('analytics.mail.note_profit_hidden', [], $mailLocale) }}
@endif

{{ $adminUrl }}

{{ __('analytics.mail.footer', ['name' => $scheduleName], $mailLocale) }}
