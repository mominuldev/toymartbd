@php
    use App\Domain\Notification\Mail\MailTheme;
    $locale = $mailLocale ?? 'en';
    $font = MailTheme::fontStack($locale);
    $leading = MailTheme::lineHeight($locale);
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subjectLine ?? '' }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Anek+Bangla:wght@400;600&family=Hanken+Grotesk:wght@400;600&display=swap" rel="stylesheet">
</head>
<body class="{{ $locale === 'bn' ? 'lang-bn' : '' }}" style="margin:0;padding:0;background:{{ MailTheme::color('color/bg/canvas') }};color:{{ MailTheme::color('color/text/primary') }};font-family:{!! $font !!};line-height:{{ $leading }};">
    {{-- Preheader: shown in the inbox list, hidden in the body. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader ?? '' }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ MailTheme::color('color/bg/canvas') }};">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:{{ MailTheme::color('color/bg/surface') }};border:1px solid {{ MailTheme::color('color/border/default') }};border-radius:16px;">
                    <tr>
                        <td style="padding:24px 24px 8px;font-size:20px;font-weight:600;color:{{ MailTheme::color('color/text/brand') }};">
                            {{ $locale === 'bn' ? 'টয় মার্ট বিডি' : 'Toy Mart BD' }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 24px 24px;font-size:15px;">
                            @yield('content')
                        </td>
                    </tr>
                </table>
                <p style="max-width:560px;margin:16px auto 0;font-size:12px;color:{{ MailTheme::color('color/text/tertiary') }};">
                    @yield('footer')
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
