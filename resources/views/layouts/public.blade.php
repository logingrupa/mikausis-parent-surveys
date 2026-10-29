<!doctype html>
<html lang="lv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Vecāku aptaujas') · Mikausis</title>
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="preload" href="{{ asset('fonts/montserrat-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/survey.css') }}?v={{ filemtime(public_path('css/survey.css')) }}">
    @stack('head')
</head>
<body>
<a class="skip-link" href="#main-content">Pāriet uz saturu</a>
<header class="site-header">
    <div class="header-inner">
        <a href="{{ route('surveys.index') }}" class="brand" aria-label="Mikausis — vecāku aptaujas">
            <img src="{{ asset('images/mikausis.svg') }}" alt="Mikausis" width="179" height="48">
            <span>Vecāku aptaujas</span>
        </a>
        <a class="school-link" href="https://mikausis.lv/" target="_blank" rel="noopener noreferrer">Dārziņa mājaslapa <span aria-hidden="true">↗</span></a>
    </div>
</header>
<main id="main-content" class="page-shell">@yield('content')</main>
<footer class="site-footer">
    <div><span>PPII Mikausis</span><span class="footer-dot" aria-hidden="true">·</span><span>Kopā mūsu bērniem.</span></div>
    <nav aria-label="Papildu informācija"><a href="{{ route('surveys.privacy') }}">Par privātumu</a>@include('surveys.partials.source-link', ['explanation' => false])<a href="/admin">Administrācijai</a></nav>
</footer>
@stack('scripts')
</body>
</html>
