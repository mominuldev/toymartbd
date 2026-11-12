@extends('mail.layouts.base')

@php use App\Domain\Notification\Mail\MailTheme; @endphp

@section('content')
    <p style="margin:0 0 12px;">{{ __('notifications.mail.greeting', ['name' => $customerName], $mailLocale) }}</p>

    @foreach ($paragraphs as $paragraph)
        <p style="margin:0 0 12px;">{{ $paragraph }}</p>
    @endforeach

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;border-top:1px solid {{ MailTheme::color('color/border/default') }};">
        <tr>
            <td colspan="2" style="padding:12px 0 4px;font-size:13px;color:{{ MailTheme::color('color/text/secondary') }};">
                {{ __('notifications.mail.order_number', [], $mailLocale) }} {{ $orderNumber }}
            </td>
        </tr>
        @foreach ($items as $item)
            <tr>
                <td style="padding:6px 0;">
                    {{ $item['name'] }}@if ($item['variant']) <span style="color:{{ MailTheme::color('color/text/secondary') }};">· {{ $item['variant'] }}</span>@endif
                    <span style="color:{{ MailTheme::color('color/text/secondary') }};">× {{ $item['quantity'] }}</span>
                </td>
                <td align="right" style="padding:6px 0;white-space:nowrap;">{{ $item['total'] }}</td>
            </tr>
        @endforeach
        <tr><td colspan="2" style="border-top:1px solid {{ MailTheme::color('color/border/default') }};padding-top:6px;"></td></tr>
        @foreach (['subtotal', 'discount', 'delivery'] as $line)
            @isset($totals[$line])
                <tr>
                    <td style="padding:2px 0;color:{{ MailTheme::color('color/text/secondary') }};">{{ __('notifications.mail.'.$line, [], $mailLocale) }}</td>
                    <td align="right" style="padding:2px 0;white-space:nowrap;">{{ $totals[$line] }}</td>
                </tr>
            @endisset
        @endforeach
        <tr>
            <td style="padding:6px 0;font-weight:600;">{{ __('notifications.mail.total', [], $mailLocale) }}</td>
            <td align="right" style="padding:6px 0;font-weight:600;white-space:nowrap;">{{ $totals['total'] }}</td>
        </tr>
        <tr>
            <td style="padding:2px 0;color:{{ MailTheme::color('color/text/secondary') }};">{{ __('notifications.mail.due', [], $mailLocale) }}</td>
            <td align="right" style="padding:2px 0;white-space:nowrap;">{{ $totals['due'] }}</td>
        </tr>
    </table>

    <p style="margin:16px 0 0;">
        <a href="{{ $trackUrl }}" style="display:inline-block;padding:10px 20px;border-radius:999px;background:{{ MailTheme::color('color/bg/brand') }};color:{{ MailTheme::color('color/text/on-brand') }};text-decoration:none;font-weight:600;">{{ __('notifications.mail.track', [], $mailLocale) }}</a>
    </p>
    <p style="margin:16px 0 0;font-size:13px;color:{{ MailTheme::color('color/text/secondary') }};">{{ __('notifications.mail.help', ['hotline' => $hotline], $mailLocale) }}</p>
@endsection

@section('footer')
    {{ __('notifications.mail.footer', [], $mailLocale) }}
@endsection
