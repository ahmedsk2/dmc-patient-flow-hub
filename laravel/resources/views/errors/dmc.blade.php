{{-- Minimal EHC-branded error shell — deliberately dependency-free (no Vite/JS): it must render
     even when the app itself is the thing that broke. Children supply code / title / message.
     Never echo $exception->getMessage() here — internals stay out of error pages (C4). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · DMC IM</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               font-family: ui-sans-serif, system-ui, "Segoe UI", Arial, sans-serif;
               background: #f2f7f7; color: #1f2a2e; }
        .card { text-align: center; padding: 3rem 2.5rem; background: #fff; border-radius: 1.25rem;
                box-shadow: 0 10px 30px rgba(0, 86, 94, .08); max-width: 26rem; margin: 1rem; }
        .brand { font-weight: 800; letter-spacing: .02em; color: #00565e; margin-bottom: 1.75rem; }
        .brand span { color: #009ca6; }
        .code { font-size: 3.25rem; font-weight: 800; color: #009ca6; line-height: 1; }
        h1 { font-size: 1.15rem; margin: .75rem 0 .35rem; }
        p { margin: 0; color: #5b6a6e; font-size: .92rem; line-height: 1.5; }
        a.home { display: inline-block; margin-top: 1.5rem; padding: .6rem 1.4rem; border-radius: .75rem;
                 background: #009ca6; color: #fff; text-decoration: none; font-weight: 600; font-size: .9rem; }
        a.home:hover { background: #00565e; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">DMC <span>IM</span> · Patient-Flow Hub</div>
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a class="home" href="/">Back to the dashboard</a>
    </main>
</body>
</html>
