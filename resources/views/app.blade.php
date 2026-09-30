<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f6f7f4">
    <meta name="description" content="A careful, source-first foundation for scholarship discovery.">
    <title>{{ config('app.name', 'ScholarSignal') }} — A clearer path</title>
    @vite(['resources/js/app.js'])
</head>
<body>
    <div id="app"><main class="boot-screen" role="status">Opening your workspace…</main></div>
    <noscript><main style="max-width:48rem;margin:12vh auto;padding:2rem;font:16px/1.7 system-ui;color:#23352e"><h1>ScholarSignal is getting started.</h1><p>This foundation includes secure accounts and a responsive workspace. Scholarship discovery is planned for a later phase. Enable JavaScript to use the account and application shell.</p></main></noscript>
</body>
</html>
