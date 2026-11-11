{{-- ═══════════════════════════════════════════════════════════════════
     419 — ISTEKLA SESIJA

     Laravel vraća 419 kada CSRF token iz forme više ne odgovara onom u
     sesiji — a to se u praksi dešava iz jednog razloga: sesija je u
     međuvremenu istekla (SESSION_LIFETIME, ili zatvaranje pregledača uz
     SESSION_EXPIRE_ON_CLOSE=true). Do sada je korisnik na tom mestu dobijao
     Laravel-ovu podrazumevanu englesku stranu „419 | Page Expired", bez
     objašnjenja šta se desilo i bez puta napred.

     Stranica je samostalna (bez zaglavlja i podnožja aplikacije): korisnik
     je u tom trenutku odjavljen, pa mu navigacija ka zaduženjima i
     notifikacijama ne znači ništa — jedina radnja je ponovna prijava.

     Tema i pismo se poštuju kao i svuda: klasu `dark` postavlja inline
     snippet pre prvog iscrtavanja, a ćirilicu server (PreslovljavanjePisma).
     Alatke u gornjem desnom uglu dozvoljavaju da se oba menjaju i odavde.
     ═══════════════════════════════════════════════════════════════════ --}}
<!DOCTYPE html>
<html lang="sr" data-tema="system" data-pismo="{{ request()->cookie('pismo') === 'cyr' ? 'cyr' : 'lat' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesija je istekla - Biblioteka</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="preload" href="{{ asset_v('webfonts/fa-solid-900.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset_v('css/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/core.css') }}">
    @include('partials.tema-init')
    <link rel="stylesheet" href="{{ asset_v('css/greska.css') }}">
</head>
<body>

{{-- Alatke: pismo (lat/ćir) i tema (svetlo/tamno/sistem) — isti markup i iste
     klase kao u zaglavlju aplikacije, pa ih vezuju iste skripte. --}}
<div class="greska-alatke">
    <div class="header-tool">
        @php
            $jeCir = request()->cookie('pismo') === 'cyr';
        @endphp
        <button type="button" class="header-tool__btn" id="pismoToolBtn" aria-label="Promena pisma"
                title="{{ $jeCir ? 'Prebaci na latinicu' : 'Prebaci na ćirilicu' }}" data-no-tr>
            <span id="pismoToolLabel">{{ $jeCir ? 'Lat' : 'Ћир' }}</span>
        </button>
    </div>
    <div class="header-tool" id="themeTool">
        <button type="button" class="header-tool__btn" id="themeToolBtn" aria-label="Promena teme" title="Tema">
            {{-- Ikonicu bira CSS po `data-tema` (vidi partials/tema-init) —
                 isto kao u zaglavlju aplikacije. --}}
            <i class="ikona-sunce tema-ikona tema-ikona--svetlo" aria-hidden="true"></i>
            <i class="fa-solid fa-moon tema-ikona tema-ikona--tamno" aria-hidden="true"></i>
            <i class="fa-solid fa-desktop tema-ikona tema-ikona--sistem" aria-hidden="true"></i>
        </button>
        <div class="header-tool__menu" id="themeToolMenu" role="menu">
            <button type="button" class="header-tool__item" data-theme="light"><i class="ikona-sunce"></i> Svetlo</button>
            <button type="button" class="header-tool__item" data-theme="dark"><i class="fa-solid fa-moon"></i> Tamno</button>
            <button type="button" class="header-tool__item" data-theme="system"><i class="fa-solid fa-desktop"></i> Sistem</button>
        </div>
    </div>
</div>

<main class="greska-glavno">
    <div class="greska-kartica">

        @php
            /* Isti par logotipa kao u zaglavlju: server već zna izabrano pismo,
               pa `src` ide tačan iz prvog pokušaja, a `data-src-*` ostaju za
               prebacivanje bez osvežavanja (public/js/pismo.js). */
            $logoLat = asset_v('images/akademijanis-lat.png');
            $logoCir = asset_v('images/akademijanis-cir.png');
            $logoSrc = request()->cookie('pismo') === 'cyr' ? $logoCir : $logoLat;
        @endphp
        <img src="{{ $logoSrc }}" alt="ATVSS Niš"
             class="greska-logo js-pismo-logo"
             width="432" height="144"
             data-no-tr
             data-src-lat="{{ $logoLat }}" data-src-cyr="{{ $logoCir }}">

        <div class="greska-ikona" aria-hidden="true">
            <i class="fa-solid fa-clock"></i>
        </div>

        <h1 class="greska-kod">Greška 419</h1>
        <p class="greska-poruka">Sesija je istekla!</p>

        <p class="greska-tekst">
            Zbog duže neaktivnosti odjavljeni ste sa sistema, pa poslednja radnja
            nije izvršena. Prijavite se ponovo da biste nastavili rad.
        </p>

        <a href="/login" class="greska-dugme">
            <i class="fa-solid fa-right-to-bracket"></i> Prijavi se ponovo
        </a>

    </div>
</main>

<script src="{{ asset_v('js/theme.js') }}"></script>
<script src="{{ asset_v('js/pismo.js') }}"></script>

</body>
</html>
