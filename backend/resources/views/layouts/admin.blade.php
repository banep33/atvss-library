{{-- ═══════════════════════════════════════════════════════════════════
     LAYOUT ZA ADMIN / BIBLIOTEKARSKE STRANICE
     (članovi, rezervacije, žanrovi, inventarna knjiga, uvoz knjiga)

     Isti princip kao layouts/app.blade.php, uz tri razlike koje admin
     stranice već imaju: drugi header partial, bez mobilne fioke
     (mobile-nav.css/js se ovde nikad nisu učitavali) i bez AI widgeta
     (partials/ai-chat-widget-loader ionako preskače bibliotekare).
     ═══════════════════════════════════════════════════════════════════ --}}
<!DOCTYPE html>
<html lang="sr" data-tema="system" data-pismo="{{ request()->cookie('pismo') === 'cyr' ? 'cyr' : 'lat' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Biblioteka')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    {{-- Font Awesome je self-hostovan podskup (vidi public/css/fontawesome.css).
         Ranije je ovde stajala kit skripta sa cdn-a: blokirala je iscrtavanje
         i CSS ubacivala tek u runtime-u, pa su ikonice uskakale posle prvog
         iscrtavanja. Font za „solid" se preload-uje jer ga nosi svaka stranica. --}}
    <link rel="preload" href="{{ asset_v('webfonts/fa-solid-900.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset_v('css/fontawesome.css') }}">

    <link rel="stylesheet" href="{{ asset_v('css/core.css') }}">
    @include('partials.tema-init')

    @stack('styles')
</head>

<body>

@include('partials.app-header-admin')

@yield('content')

@include('partials.app-footer')

@stack('modals')

@stack('scripts')

{{-- Nagoveštaj pregledaču da unapred povuče stranicu na koju korisnik
     verovatno ide (prelazak mišem preko linka). "moderate" ne skida ništa
     dok korisnik ne pokaže nameru, pa nema uzaludnog saobraćaja.
     Sve GET stranice aplikacije su čitanje bez sporednih efekata, a
     /storage/* (fajlovi) i odjava (POST) su izostavljeni. --}}
<script type="speculationrules">
{
    "prefetch": [{
        "where": { "and": [
            { "href_matches": "/*" },
            { "not": { "href_matches": "/storage/*" } },
            { "not": { "href_matches": "/login" } }
        ]},
        "eagerness": "moderate"
    }]
}
</script>


</body>
</html>
