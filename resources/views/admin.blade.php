<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The admin is a private operations tool. It must never be indexed. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Admin · {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=hanken-grotesk:400,500,600,700|anek-bangla:400,500,600,700,800&display=swap">
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
