{{-- ═══════════════════════════════════════════════════════════════════
     LAYOUT ZA ČLANSKE I JAVNE STRANICE
     (katalog, detalj knjige, zadužene, notifikacije, profil, info)

     Do sada je svaka stranica bila samostalan HTML dokument sa sopstvenim
     <head>-om, pa su se font link, Font Awesome i snippet za temu ponavljali
     u 14 view-ova. Ovde stoje na jednom mestu.

     Stranica popunjava:
       @section('title')   — naslov u <title>
       @push('styles')     — CSS specifičan za stranicu
       @section('content') — sadržaj između headera i footera
       @push('modals')     — modali koji moraju da ostanu direktna deca <body>-ja
                             (fixed pozicioniranje ne sme da zavisi od omotača)
       @push('scripts')    — inline i eksterni JS stranice

     REDOSLED CSS-a: core.css → CSS stranice → mobile-nav.css. Pravila
     koja moraju da nadjačaju stranicu (validacija polja) nose !important
     u core-u, pa ne zavise od redosleda.
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

    {{-- Jedan zajednički stylesheet za celu aplikaciju: tokeni i tema,
         zaglavlje, podnožje, navigacija, paginacija i notifikacije.
         Ranije je to bilo pet zasebnih fajlova, od kojih su se tri
         učitavala usred <body>-ja. --}}
    <link rel="stylesheet" href="{{ asset_v('css/core.css') }}">
    {{-- Tema se postavlja PRE prvog iscrtavanja — bez ovoga bi stranica
         bljesnula u svetloj temi pa se prebacila u tamnu. --}}
    @include('partials.tema-init')

    @stack('styles')

    {{-- Mobilni meni ide POSLE fajla stranice — njegova pravila namerno
         nadjačavaju raspored stranice na uskim ekranima. --}}
    <link rel="stylesheet" href="{{ asset_v('css/mobile-nav.css') }}">

    {{-- AI asistent: isti uslov kao u partials/ai-chat-widget-loader (widget
         ne postoji za bibliotekare i admine), da se CSS ne učitava bez
         potrebe. Ide u <head>, jer je do sada bio inline u <body>-ju. --}}
    @if(! (auth()->user()?->isAdminOrBibliotekar() ?? false))
        <link rel="stylesheet" href="{{ asset_v('css/ai-chat.css') }}">
    @endif
</head>

<body>

{{-- Header partial već sadrži i podlogu (#mobileNavOverlay) i fioku
     (#mobileNavDrawer) mobilnog menija — stranice su do sada imale JOŠ jednu
     kopiju istog bloka, sa istim id-jevima. mobile-nav.js uzima prvi element
     po id-u, pa su te kopije bile mrtav markup (60+ redova po stranici). --}}
@include('partials.app-header')

@yield('content')

@include('partials.app-footer')

@stack('modals')

@stack('scripts')

<script src="{{ asset_v('js/mobile-nav.js') }}"></script>

@include('partials.ai-chat-widget-loader')

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
