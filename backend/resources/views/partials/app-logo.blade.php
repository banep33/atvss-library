{{-- ═══════════════════════════════════════════════════════════════════
     LOGOTIP AKADEMIJE U ZAGLAVLJU

     Postoje ČETIRI varijante: dva pisma (latinica / ćirilica) × dve teme
     (svetla / tamna). Tamna varijanta je bela, svetla je crna — logotip je
     jednobojan, pa ne može da se koristi isti fajl na obe podloge.

     Podela posla:
       - PISMO bira server (kolačić `pismo`) i upisuje ga u `src`, pa je već
         prvo iscrtavanje tačno; `data-src-lat` / `data-src-cyr` ostaju da bi
         pismo.js mogao da prebaci sliku bez osvežavanja stranice.
       - TEMU bira CSS (`html.dark`), isto kao kod potpisa tima u podnožju —
         inline snippet u <head>-u postavi klasu pre prvog iscrtavanja, pa
         nema bleska pogrešne varijante.

     Zato u markup-u stoje DVE slike (za svetlu i za tamnu temu), a svaka
     nosi obe varijante pisma. `alt` je samo na jednoj: druga nosi istu
     informaciju, pa bi je čitač ekrana pročitao dva puta.
     ═══════════════════════════════════════════════════════════════════ --}}
@php
    $logoSvetlaLat = asset_v('images/akademija-logo-latinica-crni.svg');
    $logoSvetlaCir = asset_v('images/akademija-logo-odseknis-cirilica-crni.svg');
    $logoTamnaLat  = asset_v('images/akademija-logo-beli-latinica.png');
    $logoTamnaCir  = asset_v('images/akademijanis-logo-beo-cirilica.png');

    $jeCirilica = request()->cookie('pismo') === 'cyr';
@endphp
<img src="{{ $jeCirilica ? $logoSvetlaCir : $logoSvetlaLat }}"
     alt="ATVSS Niš"
     class="header-logo js-pismo-logo header-logo--za-svetlu"
     data-src-lat="{{ $logoSvetlaLat }}"
     data-src-cyr="{{ $logoSvetlaCir }}">
<img src="{{ $jeCirilica ? $logoTamnaCir : $logoTamnaLat }}"
     alt=""
     class="header-logo js-pismo-logo header-logo--za-tamnu"
     data-src-lat="{{ $logoTamnaLat }}"
     data-src-cyr="{{ $logoTamnaCir }}">
<noscript><img src="{{ $jeCirilica ? $logoSvetlaCir : $logoSvetlaLat }}" alt="ATVSS Niš" class="header-logo header-logo--za-svetlu"></noscript>
