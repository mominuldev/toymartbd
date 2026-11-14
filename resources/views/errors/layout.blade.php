{{--
    Branded error page for everything Laravel serves outside the JSON API (P10 launch readiness):
    the API host's web routes (/, /m/{id}/{name}, a mistyped path) and maintenance mode.
    api/* never reaches these views — it always answers RFC 9457 problem+json (rule 18).

    Self-contained on purpose: an error page must render when the Vite build is missing, the
    database is down or the app is in maintenance mode. So no @vite, no DB, no external fonts.
    Colours, spacing and radii are the design tokens (packages/tokens/dist/tokens.css, Theme B
    "admin-light"), inlined; no raw hex here (rule 26). Bilingual: the host has no locale prefix,
    so English and Bangla are shown together, Bangla with the .lang-bn line-height (rule 38).
    Numerals stay Western in both (rule 36). Never shows an exception message or a trace.
--}}
@php
    $tokensPath = base_path('packages/tokens/dist/tokens.css');
    $tokens = is_file($tokensPath) ? (string) file_get_contents($tokensPath) : '';
    $requestId = \Illuminate\Support\Facades\Context::get('request_id');
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="admin-light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('code') · @yield('title') · Toy Mart BD</title>
    <style>
        {!! $tokens !!}
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; }
        body {
            min-height: 100dvh; display: grid; place-items: center;
            padding: var(--tm-layout-gutter-mobile);
            background: var(--tm-color-bg-canvas); color: var(--tm-color-text-primary);
            font-family: var(--tm-font-latin); font-size: 1rem; line-height: 1.5;
        }
        .card {
            width: 100%; max-width: 35rem; display: flex; flex-direction: column; gap: var(--tm-space-4);
            padding: var(--tm-space-8); border-radius: var(--tm-radius-md);
            background: var(--tm-color-bg-surface); border: var(--tm-border-width-hairline) solid var(--tm-color-border-default);
            box-shadow: var(--tm-elevation-e1);
        }
        .brand { font-weight: 700; color: var(--tm-color-text-brand); letter-spacing: 0.01em; }
        .code { font-family: var(--tm-font-mono); font-size: 0.8125rem; font-weight: 600; color: var(--tm-color-text-secondary); text-transform: uppercase; letter-spacing: 0.06em; }
        h1 { margin: 0; font-size: 1.5rem; line-height: 1.3; }
        p { margin: 0; color: var(--tm-color-text-secondary); }
        .lang-bn { font-family: var(--tm-font-bangla); line-height: 1.725; }
        h1.lang-bn { line-height: 1.5; }
        .divider { border: 0; border-top: var(--tm-border-width-hairline) solid var(--tm-color-border-default); margin: var(--tm-space-2) 0; }
        .actions { display: flex; flex-wrap: wrap; gap: var(--tm-space-3); padding-top: var(--tm-space-2); }
        .button {
            display: inline-flex; align-items: center; gap: var(--tm-space-1); min-height: var(--tm-size-control-sm);
            padding: 0 var(--tm-space-4); border-radius: var(--tm-radius-xs-plus);
            background: var(--tm-color-bg-brand); color: var(--tm-color-text-on-brand);
            font-weight: 600; text-decoration: none;
        }
        .button:focus-visible { outline: var(--tm-border-width-strong) solid var(--tm-color-border-focus); outline-offset: var(--tm-space-0-5); }
        .meta { font-family: var(--tm-font-mono); font-size: 0.75rem; color: var(--tm-color-text-tertiary); }
        @media (min-width: 48rem) { .card { padding: var(--tm-space-10); } }
    </style>
</head>
<body>
    <main class="card" role="main">
        <span class="brand">Toy Mart BD · <span lang="bn" class="lang-bn">টয় মার্ট বিডি</span></span>
        <span class="code">Error @yield('code')</span>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <hr class="divider">
        <h1 lang="bn" class="lang-bn">@yield('title_bn')</h1>
        <p lang="bn" class="lang-bn">@yield('message_bn')</p>
        @hasSection('actions')
            <div class="actions">@yield('actions')</div>
        @endif
        @if (is_string($requestId) && $requestId !== '')
            <span class="meta">Reference / রেফারেন্স: {{ $requestId }}</span>
        @endif
    </main>
</body>
</html>
