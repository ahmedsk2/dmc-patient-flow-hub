<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'DMC Internal Medicine') }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" sizes="48x48 32x32 16x16" href="/favicon.ico">
    {{-- No-flash theme bootstrap: set the .dark class on <html> from saved preference (or system)
         BEFORE first paint, so a dark-mode user never sees a white flash.
         Wave 5, Item 5 (CSP-readiness): this is the ONLY inline <script> in the whole app — every
         other page is Vue `@click`-style handlers, which compile to addEventListener() and are
         already CSP-safe with no `unsafe-inline`. This one block is the sole exception because it
         MUST run before Vite's bundle loads (avoiding a light-mode flash for dark-theme users), so it
         cannot be an external/deferred file. When a CSP header is introduced (an ops/deploy task, not
         done here), this is the one script that needs a per-request nonce (or a `sha256-` hash pinned
         to its exact contents) added to `script-src` — do not add a second inline <script> anywhere
         else without updating this note. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('dmc-theme') || 'system';
                var dark = t === 'dark' || (t === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (dark) document.documentElement.classList.add('dark');
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full font-sans antialiased bg-app text-ink-700">
    @inertia
</body>
</html>
