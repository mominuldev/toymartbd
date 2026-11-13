<!DOCTYPE html>
<html lang="en" data-theme="admin-light" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The admin is a private operations tool. It must never be indexed. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · {{ config('app.name') }}</title>
    {{-- Set the theme before first paint so a dark-mode user never sees a light flash.
         The stored value is a UI preference only, never a credential. --}}
    <script>
        (function () {
            var t = null;
            try { t = localStorage.getItem('tm-admin-theme'); } catch (e) {}
            if (t !== 'admin-light' && t !== 'admin-dark') {
                t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'admin-dark' : 'admin-light';
            }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    {{-- Families match the Figma admin text styles: Hanken Grotesk (UI), Fredoka (titles),
         JetBrains Mono (ids, SKUs), Anek Bangla (Bangla UI), Baloo Da 2 (Bangla titles). --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700,800|fredoka:500,600|jetbrains-mono:500|anek-bangla:400,500,600|baloo-da-2:600&display=swap">
    @viteReactRefresh
    @vite(['resources/css/admin.css', 'resources/js/admin/main.tsx'])
</head>
<body class="h-full antialiased">
    <div id="admin-root" class="h-full"></div>
    <noscript>
        <p style="padding:2rem;font-family:system-ui">
            The admin dashboard needs JavaScript enabled.
        </p>
    </noscript>
</body>
</html>
