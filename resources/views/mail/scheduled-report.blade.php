@extends('mail.layouts.base')

@php
    use App\Domain\Notification\Mail\MailTheme;
    $muted = MailTheme::color('color/text/secondary');
    $line = MailTheme::color('color/border/default');
@endphp

@section('content')
    <p style="margin:0 0 4px;font-size:17px;font-weight:600;">{{ $reportLabel }}</p>
    <p style="margin:0 0 12px;">{{ __('analytics.mail.intro', ['frequency' => $frequencyLabel, 'period' => $periodLabel, 'format' => $formatLabel], $mailLocale) }}</p>

    <p style="margin:12px 0 4px;color:{{ $muted }};font-size:13px;">{{ __('analytics.mail.figures', [], $mailLocale) }}</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-top:1px solid {{ $line }};">
        @foreach ($headline as $row)
            <tr>
                <td style="padding:6px 0;border-bottom:1px solid {{ $line }};">{{ $row['label'] }}</td>
                <td align="right" style="padding:6px 0;border-bottom:1px solid {{ $line }};font-weight:600;white-space:nowrap;">{{ $row['value'] }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin:0 0 8px;color:{{ $muted }};font-size:13px;">{{ __('analytics.mail.note_revenue', [], $mailLocale) }}</p>
    @if ($profitHidden)
        <p style="margin:0 0 8px;color:{{ $muted }};font-size:13px;">{{ __('analytics.mail.note_profit_hidden', [], $mailLocale) }}</p>
    @endif

    <p style="margin:16px 0 0;">
        <a href="{{ $adminUrl }}" style="display:inline-block;padding:10px 20px;border-radius:999px;background:{{ MailTheme::color('color/bg/brand') }};color:{{ MailTheme::color('color/text/on-brand') }};text-decoration:none;font-weight:600;">{{ __('analytics.mail.open', [], $mailLocale) }}</a>
    </p>
@endsection

@section('footer')
    {{ __('analytics.mail.footer', ['name' => $scheduleName], $mailLocale) }}
@endsection
