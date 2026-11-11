{{-- Zajednički header za admin i bibliotekar stranice, rezervacije, clanovi, zanrovi, inventarna knjiga, knjige-import.
     CSS (notifications, pagination, header-nav) učitava layouts/admin.blade.php
     u <head>-u; ranije je stajao ovde, usred <body>-ja. --}}
<header>
    <div class="header-content {{ isset($pageTitle) ? 'has-page-title' : '' }}">
        {{-- Logotip je UVEK link ka početnoj strani — i kada je u zaglavlju
             naslov stranice i kada nije. Ranije je bio link samo u prvom
             slučaju, pa je na katalogu izgledao klikabilno a nije bio. --}}
        <h1 class="header-main-title" data-no-tr>
            <a href="/" class="title-link" aria-label="Početna strana">@include('partials.app-logo')</a>
        </h1>

        @if(isset($pageTitle))
            <h2 class="header-page-title">{{ $pageTitle }}</h2>
        @endif
        
        <div class="header-actions">
            @if($user)
                
                <a href="/" class="header-btn">Početna</a>
                
                @if($user->isAdminOrBibliotekar())
                    <a href="/admin/rezervacije" class="header-btn">Rezervacije</a>
                    <a href="/admin/clanovi" class="header-btn">Članovi</a>
                    <a href="/admin/zanrovi" class="header-btn">Žanrovi</a>
                    <a href="/admin/knjige/import" class="header-btn">Uvezi knjige</a>
                    {{-- Inventarna knjiga je do sada bila dugme na katalogu; sada
                         stoji u zaglavlju, uz ostale administrativne stavke. --}}
                    <a href="/admin/inventarna-knjiga" class="header-btn">Inventarna knjiga</a>
                @endif
                
                <a href="/info" class="header-btn">Info</a>
                
                {{-- Nalog: link sa inicijalima je premešten u `.header-tools`
                     (uz desnu ivicu, iza pisma i teme). Forma ispod je skrivena
                     i služi samo za POST odjave. --}}
                <form action="/logout" method="POST" id="logoutForm" hidden>
                    @csrf
                </form>
            @else
                <a href="/login" class="header-btn">Prijavi se</a>
            @endif

            {{-- Alatke: promena pisma (lat/ćir) i teme (svetlo/tamno/sistem) --}}
            <div class="header-tools">
                {{-- Natpis je uvek u pismu NA KOJE dugme prebacuje: dok je aktivna
                     latinica piše „Ћир", a dok je aktivna ćirilica „Lat". Vrednost
                     zna već server (kolačić `pismo`), pa nema treptaja pre nego
                     što se izvrši pismo.js; `data-no-tr` čuva natpis od
                     preslovljavanja, koje bi ga vratilo u aktivno pismo. --}}
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
                        {{-- Ikonica se bira u CSS-u, po `data-tema` na <html> (postavlja ga
                             partials/tema-init pre prvog iscrtavanja). Dok ju je postavljao
                             theme.js, u tamnom režimu bi se na svakoj stranici na trenutak
                             videlo sunce pa tek onda mesec. Sunce nije Font Awesome ikonica
                             nego sopstvena maska (.ikona-sunce iz core.css) — „fa-sun" iz FA7
                             u ovoj veličini liči na zupčanik. --}}
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

                {{-- Nalog: inicijali (npr. „JJ") otvaraju mali meni: Moj profil / Odjava. --}}
                @if($user)
                    @php
                        $inicijali = mb_strtoupper(
                            mb_substr($user->ime ?? '', 0, 1, 'UTF-8') . mb_substr($user->prezime ?? '', 0, 1, 'UTF-8'),
                            'UTF-8'
                        );
                    @endphp
                    <div class="header-user" id="headerUser">
                        <button type="button" class="header-btn header-user__btn" id="headerUserBtn"
                                aria-haspopup="true" aria-expanded="false"
                                title="{{ $user->ime }} {{ $user->prezime }}">{{ $inicijali }}</button>
                        {{-- Meni naloga: zaglavlje sa inicijalima i punim imenom, pa
                             stavke odvojene tankim linijama. Bez ikonica — one su
                             ostale samo uz alatke (tema, pismo), da meni naloga ne
                             deluje kao još jedna traka sa alatkama. --}}
                        <div class="header-tool__menu header-user__menu" id="headerUserMenu" role="menu">
                            <div class="header-user__zaglavlje">
                                <span class="header-user__avatar" aria-hidden="true">{{ $inicijali }}</span>
                                <span class="header-user__ime">{{ $user->ime }} {{ $user->prezime }}</span>
                            </div>
                            <div class="header-user__linija"></div>
                            <a href="/profil" class="header-tool__item" role="menuitem">Moj profil</a>
                            <div class="header-user__linija"></div>
                            <button type="button" class="header-tool__item" role="menuitem" id="headerLogoutBtn">Odjava</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</header>
