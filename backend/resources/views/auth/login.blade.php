<!DOCTYPE html>
<html lang="sr" data-tema="system" data-pismo="{{ request()->cookie('pismo') === 'cyr' ? 'cyr' : 'lat' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prijava - Biblioteka</title>
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
    <link rel="stylesheet" href="{{ asset_v('css/login.css') }}">
</head>
<body>

<div class="login-page">
    {{-- Leva kolona: forma --}}
    <div class="login-left">
        {{-- Logo akademije uz samu gornju ivicu, preko cele širine kolone --}}
        <div class="login-topbar">
            <img src="{{ asset_v('images/atvss-logo.png') }}"
                 alt="Akademija tehničko-vaspitačkih strukovnih studija"
                 class="login-topbar__logo">
        </div>

        <div class="login-card">
            <div class="login-header">
                <h1><i class="fa-solid fa-book"></i> Biblioteka</h1>
                <p>Bibliotečki informacioni sistem</p>
            </div>

            @if(session('error'))
            <div class="alert alert-error">
                {{ session('error') }}
            </div>
            @endif

            {{-- Throttle greška sa odbrojavanjem.
                 Sekunde stižu ili iz flash-a (posle POST-a) ili iz kontrolera
                 preko sesije ($throttleSeconds) — pa blokada preživljava refresh --}}
            @php
                $lockSeconds = max((int) session('throttle_seconds', 0), (int) ($throttleSeconds ?? 0));
            @endphp
            @if($lockSeconds > 0)
                <div class="alert-throttle" id="throttleAlert">
                    <div class="alert-throttle__icon"><i class="fa-solid fa-lock"></i></div>
                    <div class="alert-throttle__content">
                        <strong>Nalog privremeno zaključan</strong>
                        <p>{{ $errors->first('throttle') ?: 'Previše neuspelih pokušaja prijave.' }}</p>
                        <p class="countdown-text">
                            Preostalo vreme: <span id="countdown" class="countdown-timer">
                                {{ $lockSeconds }}
                            </span>
                        </p>
                    </div>
                </div>
            @endif

            {{-- Ostale greške — greške polja (email/lozinka) se ispisuju
                 ISPOD samog polja, pa ih ovaj okvir preskače. --}}
            @if($errors->any() && !$errors->has('throttle') && !$errors->has('email') && !$errors->has('password'))
                <div class="alert alert-error">
                    {{ $errors->first() }}
                </div>
            @endif

            {{-- novalidate: proveru obaveznih polja preuzima proveriObavezna()
                 iz notifications.js, da poruka prati temu i dizajn aplikacije
                 umesto podrazumevanog „balončića" pregledača. --}}
            <form method="POST" action="/login" id="loginForm" novalidate>
                @csrf

                <div class="form-group">
                    <label for="email">E-pošta</label>
                    {{-- data-ziva-provera: zelena kvačica dok se kuca i poruka o
                         formatu na izlasku iz polja. Namerno samo ovde — ostala
                         polja e-pošte u aplikaciji nemaju taj prikaz. --}}
                    <input type="email" id="email" name="email"
                           value="{{ old('email') }}"
                           class="{{ $errors->has('email') ? 'polje--greska' : '' }}"
                           data-ziva-provera
                           required autofocus>
                    @error('email')
                        <span class="polje-greska">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password">Lozinka</label>
                    <input type="password" id="password" name="password"
                           class="{{ $errors->has('password') ? 'polje--greska' : '' }}"
                           required>
                    @error('password')
                        <span class="polje-greska">{{ $message }}</span>
                    @enderror
                </div>

                <button type="submit" class="btn-login">Prijava</button>
            </form>
        </div>
    </div>

    {{-- Desna kolona: ilustracija preko cele polovine, logo iznad lampe.
         Na ≤992px se ova kolona gasi, pa logo preuzima traka u levoj koloni. --}}
    <div class="login-right">
        <img src="{{ asset_v('images/atvss-logo.png') }}" alt="Akademija tehničko-vaspitačkih strukovnih studija" class="login-right__logo">
        <img src="{{ asset_v('images/login-illustration.png') }}" alt="" class="login-right__art">
    </div>
</div>

<script>
    (function () {
    const el = document.getElementById('countdown');
    if (!el) return;

    // Parsira sekunde iz teksta koji Laravel ubaci
    let seconds = parseInt(el.textContent.trim(), 10);
    if (isNaN(seconds) || seconds <= 0) return;

    function fmt(s) {
        const m = Math.floor(s / 60);
        const sec = s % 60;
        return m > 0
            ? `${m}:${String(sec).padStart(2, '0')} min`
            : `${sec} sek`;
    }

    el.textContent = fmt(seconds);

    const interval = setInterval(function () {
        seconds--;
        if (seconds <= 0) {
            clearInterval(interval);
            // Sakrij alert i otključaj formu
            document.getElementById('throttleAlert').style.display = 'none';
            const btn = document.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Prijava';
            }
        } else {
            el.textContent = fmt(seconds);
        }
    }, 1000);

    // Zaključaj submit dugme dok traje odbrojavanje
    const btn = document.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Sačekajte...';
    }
})();
</script>

{{-- Globalne notifikacije (toast) — npr. "Uspešno ste se odjavili" --}}
<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

{{-- Dijalog (isti markup kao u app-footer partialu; ova stranica ga ne
     uključuje, a bez njega alertDialog pada na prozor pregledača). --}}
<div id="confirmOverlay" class="confirm-overlay" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <div class="confirm-modal" data-type="danger">
        <div class="confirm-modal__body">
            <div class="confirm-modal__icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="confirm-modal__content">
                <h3 class="confirm-modal__title" id="confirmTitle">Potvrda</h3>
                <p class="confirm-modal__text">Da li ste sigurni?</p>
            </div>
        </div>
        <div class="confirm-modal__actions">
            <button type="button" class="confirm-btn confirm-btn--cancel">Otkaži</button>
            <button type="button" class="confirm-btn confirm-btn--confirm">Potvrdi</button>
        </div>
    </div>
</div>
<script>
    window.__flashMessages = [
        @if(session('success')) { message: @json(session('success')), type: 'success' }, @endif
        @if(session('status'))  { message: @json(session('status')),  type: 'info'    }, @endif
    ];
</script>
<script src="{{ asset_v('js/notifications.js') }}"></script>
<script>
    /* Prazna obavezna polja: crvena ivica + poruka ispod polja + zbirni
       dijalog. Ide posle notifications.js, koji definiše proveriObavezna. */
    document.getElementById('loginForm')?.addEventListener('submit', function (e) {
        if (typeof proveriObavezna === 'function' && !proveriObavezna(this, { dijalog: true })) {
            e.preventDefault();
        }
    });
</script>
{{-- Inline snippet u <head> samo POSTAVI temu pri učitavanju. theme.js je taj
     koji se pretplaćuje na promenu sistemske teme (prefers-color-scheme), pa bez
     njega stranica u režimu „Sistem" ne bi pratila korisnika koji temu uređaja
     promeni dok je OVDE. Ova stranica ne koristi app-footer, pa se učitava ručno. --}}
<script src="{{ asset_v('js/theme.js') }}"></script>
<script src="{{ asset_v('js/pismo.js') }}"></script>

</body>
</html>
