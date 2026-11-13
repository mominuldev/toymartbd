@extends('mail.layouts.base')

@php
    use App\Domain\Notification\Mail\MailTheme;
    $muted = MailTheme::color('color/text/secondary');
    $line = MailTheme::color('color/border/default');
    $preheader = __('inventory.mail.drift_preheader', ['count' => $openDriftCount], $mailLocale);
@endphp

@section('content')
    <p style="margin:0 0 12px;">{{ __('inventory.mail.drift_intro', ['count' => count($drifts)], $mailLocale) }}</p>
    <p style="margin:0 0 12px;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.drift_body', [], $mailLocale) }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 16px;border-top:1px solid {{ $line }};font-size:14px;">
        <tr>
            <td style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.col_product', [], $mailLocale) }}</td>
            <td style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.col_location', [], $mailLocale) }}</td>
            <td align="right" style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.col_expected', [], $mailLocale) }}</td>
            <td align="right" style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.col_actual', [], $mailLocale) }}</td>
        </tr>
        @foreach ($drifts as $drift)
            <tr>
                <td style="padding:4px 0;">
                    {{ $drift['product_name'] }} <span style="color:{{ $muted }};">{{ $drift['sku'] }} · {{ __('inventory.mail.kind.'.$drift['kind'], [], $mailLocale) }}</span>
                </td>
                <td style="padding:4px 0;">{{ strtoupper($drift['location_code']) }}</td>
                <td align="right" style="padding:4px 0;">{{ $drift['expected'] }}</td>
                <td align="right" style="padding:4px 0;font-weight:600;">{{ $drift['actual'] }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin:16px 0 0;">
        <a href="{{ $adminUrl }}" style="display:inline-block;padding:10px 20px;border-radius:999px;background:{{ MailTheme::color('color/bg/brand') }};color:{{ MailTheme::color('color/text/on-brand') }};text-decoration:none;font-weight:600;">{{ __('inventory.mail.open_alerts', [], $mailLocale) }}</a>
    </p>
@endsection

@section('footer')
    {{ __('inventory.mail.footer', [], $mailLocale) }}
@endsection
