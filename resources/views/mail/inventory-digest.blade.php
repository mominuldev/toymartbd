@extends('mail.layouts.base')

@php
    use App\Domain\Notification\Mail\MailTheme;
    $muted = MailTheme::color('color/text/secondary');
    $line = MailTheme::color('color/border/default');
    $preheader = __('inventory.mail.digest_preheader', ['out' => $digest['open']['out_of_stock'], 'low' => $digest['open']['low_stock']], $mailLocale);
@endphp

@section('content')
    <p style="margin:0 0 12px;">{{ __('inventory.mail.digest_intro', ['date' => $digest['date']], $mailLocale) }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 16px;border-top:1px solid {{ $line }};">
        <tr>
            <td style="padding:8px 0;color:{{ $muted }};font-size:13px;"></td>
            <td align="right" style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.raised_on', ['date' => $digest['date']], $mailLocale) }}</td>
            <td align="right" style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.open_now', [], $mailLocale) }}</td>
        </tr>
        @foreach (['out_of_stock', 'low_stock', 'drift'] as $kind)
            <tr>
                <td style="padding:4px 0;">{{ __('inventory.mail.kind.'.$kind, [], $mailLocale) }}</td>
                <td align="right" style="padding:4px 0;">{{ $digest['raised'][$kind] }}</td>
                <td align="right" style="padding:4px 0;font-weight:600;">{{ $digest['open'][$kind] }}</td>
            </tr>
        @endforeach
        <tr>
            <td style="padding:4px 0;">{{ __('inventory.mail.pending_adjustments', [], $mailLocale) }}</td>
            <td></td>
            <td align="right" style="padding:4px 0;font-weight:600;">{{ $digest['pending_adjustments'] }}</td>
        </tr>
    </table>

    @if (count($digest['rows']) > 0)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-top:1px solid {{ $line }};font-size:14px;">
            <tr>
                <td style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.col_product', [], $mailLocale) }}</td>
                <td style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.col_location', [], $mailLocale) }}</td>
                <td align="right" style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.col_available', [], $mailLocale) }}</td>
                <td align="right" style="padding:8px 0;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.col_reorder', [], $mailLocale) }}</td>
            </tr>
            @foreach ($digest['rows'] as $row)
                <tr>
                    <td style="padding:4px 0;">
                        {{ $row['product_name'] }}@if ($row['variant_name']) <span style="color:{{ $muted }};">· {{ $row['variant_name'] }}</span>@endif
                        <span style="color:{{ $muted }};">{{ $row['sku'] }}</span>
                        @if ($row['raised_on_date']) <strong>· {{ __('inventory.mail.new', [], $mailLocale) }}</strong>@endif
                    </td>
                    <td style="padding:4px 0;">{{ strtoupper($row['location_code']) }}</td>
                    <td align="right" style="padding:4px 0;">{{ $row['available'] }}</td>
                    <td align="right" style="padding:4px 0;color:{{ $muted }};">{{ $row['reorder_point'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (count($digest['drift_rows']) > 0)
        <p style="margin:0 0 8px;font-weight:600;">{{ __('inventory.mail.drift_heading', [], $mailLocale) }}</p>
        <p style="margin:0 0 12px;color:{{ $muted }};font-size:13px;">{{ __('inventory.mail.drift_body', [], $mailLocale) }}</p>
    @endif

    <p style="margin:16px 0 0;">
        <a href="{{ $adminUrl }}" style="display:inline-block;padding:10px 20px;border-radius:999px;background:{{ MailTheme::color('color/bg/brand') }};color:{{ MailTheme::color('color/text/on-brand') }};text-decoration:none;font-weight:600;">{{ __('inventory.mail.open_inventory', [], $mailLocale) }}</a>
    </p>
@endsection

@section('footer')
    {{ __('inventory.mail.footer', [], $mailLocale) }}
@endsection
