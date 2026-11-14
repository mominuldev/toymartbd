@extends('mail.layouts.base')

@php
    use App\Domain\Notification\Mail\MailTheme;
    $muted = MailTheme::color('color/text/secondary');
    $preheader = $summary;
@endphp

@section('content')
    @if ($isTest)
        <p style="margin:0 0 12px;font-weight:600;">{{ __('monitoring.mail.test_note', [], $mailLocale) }}</p>
    @endif
    <p style="margin:0 0 12px;">{{ __('monitoring.mail.intro_'.$kind, [], $mailLocale) }}</p>
    <p style="margin:0 0 4px;font-weight:600;">{{ __('monitoring.names.'.$alertName, [], $mailLocale) }} · {{ __('monitoring.severity.'.$severity, [], $mailLocale) }}</p>
    <p style="margin:0 0 12px;">{{ $summary }}</p>
    <p style="margin:0 0 12px;color:{{ $muted }};font-size:13px;">{{ __('monitoring.mail.checked_at', [], $mailLocale) }}: {{ $checkedAt }} · {{ $alertName }}</p>
    <p style="margin:0;color:{{ $muted }};font-size:13px;">{{ __('monitoring.mail.runbook', [], $mailLocale) }}</p>
@endsection

@section('footer')
    {{ __('monitoring.mail.footer', [], $mailLocale) }}
@endsection
