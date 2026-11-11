{{-- Zajednički header + mobilni meni za member/javne stranice.
     Prikaz po ulogama radi preko $user (mora biti prosleđen view-u).
     Opcioni $pageTitle (isset) prikazuje naslov stranice u headeru.
     NAPOMENA: partial se uključuje iz layouts/app.blade.php, koji učitava i
     CSS (notifications, pagination, header-nav) i mobile-nav.js. Ranije su
     ti <link> tagovi stajali OVDE, dakle usred <body>-ja, pa se već iscrtan
     sadržaj preslagivao kada bi stigli. --}}
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
                {{-- Administrativne stavke vidljive su samo adminu i bibliotekaru --}}
                @if($user && $user->isAdminOrBibliotekar())
                    <a href="/admin/rezervacije" class="header-btn">Rezervacije</a>
                    <a href="/admin/clanovi" class="header-btn">Članovi</a>
                    <a href="/admin/zanrovi" class="header-btn">Žanrovi</a>
                    <a href="/admin/knjige/import" class="header-btn">Uvezi knjige</a>
                    {{-- Inventarna knjiga je do sada bila dugme na katalogu; sada
                         stoji u zaglavlju, uz ostale administrativne stavke. --}}
                    <a href="/admin/inventarna-knjiga" class="header-btn">Inventarna knjiga</a>
                @endif

                {{-- Notifikacije i Zadužene knjige se prikazuju SAMO članovima --}}
                @if($user && $user->isClan())
                @php
                    $neprocitaneUpit = \App\Models\Notifikacija::where('user_id', $user->id)
                        ->where('procitano', false);

                    $neprocitane = (clone $neprocitaneUpit)->count();

                    /* Boja brojača prati NAJNOVIJE nepročitano obaveštenje —
                       zeleno za odobrenu rezervaciju, narandžasto za rok koji
                       ističe, crveno za odbijenu rezervaciju i istekao rok
                       pozajmice. Kad ih ima više različitih, merodavno je
                       poslednje pristiglo: brojač odgovara na pitanje „šta se
                       upravo desilo", a ne „šta je najgore u spisku".
                       Podela je u App\Enums\TipObavestenja::boja(), zajednička
                       sa spiskom obaveštenja i sa trakom u mejlu. */
                    $bojaBedza = \App\Enums\TipObavestenja::iz(
                        (clone $neprocitaneUpit)->latest('created_at')->value('tip')
                    )?->boja() ?? 'greska';
                @endphp
                <a href="{{ route('notifikacije.index') }}" class="header-btn">
                     Notifikacije
                    {{-- Brojač nosi SOPSTVENU klasu, ne opštu `.badge`: tu klasu
                         stranice koriste za svoje plave pločice (admin.css,
                         zaduzene.css), pa je brojač u zaglavlju izgledao drugačije
                         u zavisnosti od toga koji je CSS stranica učitala.
                         Prikazuje se samo kada ima nepročitanih — isto pravilo
                         koje mobilna fioka već primenjuje. --}}
                    @if($neprocitane > 0)
                        <span id="badge" class="header-btn__badge header-btn__badge--{{ $bojaBedza }}">{{ $neprocitane }}</span>
                    @endif
                </a>

                <a href="{{ route('zaduzene.index') }}" class="header-btn">
                 Zadužene knjige
                </a>
                @endif

                <a href="/info" class="header-btn">Info</a>
                {{-- Nalog: link sa inicijalima je premešten u `.header-tools`
                     (uz desnu ivicu, iza pisma i teme). Forma ispod je skrivena
                     i služi samo za POST odjave. --}}
                <form action="/logout" method="POST" id="logoutForm" hidden>
                    @csrf
                </form>
            @else

                {{-- Info je opšta sekcija — dostupna i gostu; levo od „Prijavi se". --}}
                <a href="/info" class="header-btn">Info</a>
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

        <!-- HAMBURGER - vidljiv samo na mobi, direktno u HTML-u -->
        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Otvori meni">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </div>
</header>

<!-- MOBILNI MENI OVERLAY -->
<div class="mobile-nav-overlay" id="mobileNavOverlay"></div>

<!-- MOBILNI DRAWER -->
<div class="mobile-nav-drawer" id="mobileNavDrawer">
    <div class="mobile-nav-header">
        <span class="mobile-nav-title">Biblioteka</span>
        <button class="mobile-nav-close" id="mobileNavClose">&times;</button>
    </div>

    @if($user)
    <div class="mobile-nav-profile">
        <div class="mobile-nav-profile-avatar">
            <i class="fa-solid fa-user"></i>
        </div>
        <div class="mobile-nav-profile-info">
            <strong>{{ $user->ime }} {{ $user->prezime }}</strong>
            <span>{{ $user->isAdminOrBibliotekar() ? 'Bibliotekar / Admin' : 'Član biblioteke' }}</span>
        </div>
    </div>
    @endif

    <div class="mobile-nav-links">
        <div class="mobile-nav-section-label">Navigacija</div>
        <a href="/" class="mobile-nav-link">
            <i class="fa-solid fa-house"></i> Početna
        </a>
        <a href="/info" class="mobile-nav-link">
            <i class="fa-solid fa-circle-info"></i> Info
        </a>

        @if($user && $user->isClan())
        <div class="mobile-nav-separator"></div>
        <div class="mobile-nav-section-label">Moj nalog</div>
        <a href="{{ route('notifikacije.index') }}" class="mobile-nav-link">
            <i class="fa-solid fa-bell"></i> Notifikacije
            {{-- Isti broj kao u zaglavlju; računa se jednom, gore. --}}
            @if(($neprocitane ?? 0) > 0)
                <span class="nav-badge nav-badge--{{ $bojaBedza ?? 'greska' }}">{{ $neprocitane }}</span>
            @endif
        </a>
        <a href="{{ route('zaduzene.index') }}" class="mobile-nav-link">
            <i class="fa-solid fa-book"></i> Zadužene knjige
        </a>
        @endif

        @if($user && $user->isAdminOrBibliotekar())
        <div class="mobile-nav-separator"></div>
        <div class="mobile-nav-section-label">Administracija</div>
        <a href="/admin/rezervacije" class="mobile-nav-link">
            <i class="fa-solid fa-calendar-check"></i> Rezervacije
        </a>
        <a href="/admin/clanovi" class="mobile-nav-link">
            <i class="fa-solid fa-users"></i> Članovi
        </a>
        <a href="/admin/zanrovi" class="mobile-nav-link">
            <i class="fa-solid fa-tags"></i> Žanrovi
        </a>
        <a href="/admin/knjige/import" class="mobile-nav-link">
            <i class="fa-solid fa-file-import"></i> Uvezi knjige
        </a>
        <a href="/admin/inventarna-knjiga" class="mobile-nav-link">
            <i class="fa-solid fa-book-open"></i> Inventarna knjiga
        </a>
        @endif

        @if($user)
        <div class="mobile-nav-separator"></div>
        <a href="/profil" class="mobile-nav-link">
            <i class="fa-solid fa-user-gear"></i> Moj profil
        </a>
        @else
        <a href="/login" class="mobile-nav-link">
            <i class="fa-solid fa-right-to-bracket"></i> Prijavi se
        </a>
        @endif

        {{-- Prikaz: tema + pismo (mobilni) --}}
        <div class="mobile-nav-separator"></div>
        <div class="mobile-nav-section-label">Prikaz</div>
        <button type="button" class="mobile-nav-link mobile-nav-btn js-theme-opt" data-theme="light">
            <i class="ikona-sunce"></i> Svetla tema
        </button>
        <button type="button" class="mobile-nav-link mobile-nav-btn js-theme-opt" data-theme="dark">
            <i class="fa-solid fa-moon"></i> Tamna tema
        </button>
        <button type="button" class="mobile-nav-link mobile-nav-btn js-theme-opt" data-theme="system">
            <i class="fa-solid fa-desktop"></i> Sistemska tema
        </button>
        {{-- Kao i dugme u zaglavlju, natpis je pismo NA KOJE se prebacuje, i
             ispisan je tim pismom: dok je aktivna latinica piše „Ћирилица",
             a dok je aktivna ćirilica „Latinica". Vrednost zna već server
             (kolačić `pismo`), a pismo.js je menja pri prebacivanju bez
             osvežavanja; `data-no-tr` čuva natpis od preslovljavanja, koje bi
             ga vratilo u aktivno pismo. --}}
        <button type="button" class="mobile-nav-link mobile-nav-btn js-pismo-toggle" data-no-tr>
            <i class="fa-solid fa-language"></i> <span class="js-pismo-naziv">{{ $jeCir ? 'Latinica' : 'Ћирилица' }}</span>
        </button>
    </div>

    @if($user)
    <div class="mobile-nav-logout">
        <button id="mobileLogoutBtn">
            <i class="fa-solid fa-right-from-bracket"></i> Odjavi se
        </button>
    </div>
    @endif
</div>
