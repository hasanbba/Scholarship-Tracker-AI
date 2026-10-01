<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description ?? 'Explore scholarships published with current verification and official sources.' }}">
    <meta name="robots" content="{{ $robots ?? 'index,follow' }}">
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    <meta name="theme-color" content="#f6f7f4">
    <title>{{ $title ?? 'ScholarSignal' }} | ScholarSignal</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="site-shell">
        <header class="topbar public-topbar">
            <a class="brand" href="/"><span class="brand-mark">S</span><span>Scholar<span class="brand-light">Signal</span></span></a>
            <nav class="top-actions" aria-label="Main navigation">
                <a class="nav-link" href="/scholarships">Browse scholarships</a>
                <a class="button button-quiet" href="/login">Sign in</a>
                <a class="button button-primary button-small" href="/register">Create account</a>
            </nav>
        </header>
        <main class="public-main">@yield('content')</main>
        <footer class="site-footer"><span>ScholarSignal</span><span>Official opportunities, carefully verified.</span></footer>
    </div>
</body>
</html>
