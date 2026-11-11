{{-- Zajednički footer. Stilove nosi core.css (sekcije „2. ZAGLAVLJE I
     PODNOŽJE" i „4. PODNOŽJE — POTPIS TIMA"); u CSS-u stranice ostaje samo
     pravilo `footer` tamo gde stranica namerno odstupa (npr. admin i
     zaduzene ga pozicioniraju apsolutno). --}}
<footer>
    <div class="footer-content">
        <div class="footer-info">
            <span><i class="fa-solid fa-location-dot"></i> Aleksandra Medvedeva 20, Niš</span>
            <span><i class="fa-solid fa-phone"></i> +381 18 588 211</span>
            <span><i class="fa-solid fa-envelope"></i> <a href="mailto:silvana.bogicevic@akademijanis.edu.rs">silvana.bogicevic@akademijanis.edu.rs</a></span>
        </div>
        <div class="footer-bottom">
            <p class="footer-copyright">&copy; 2026 Biblioteka - Akademija tehničko-vaspitačkih strukovnih studija</p>

            {{-- Potpis tima, i link ka sajtu tima. `data-no-tr` — naziv tima je
                 brend i ostaje u latinici i kada je izabrana ćirilica.
                 `rel="noopener noreferrer"` ide uz `target="_blank"`: bez njega
                 otvorena stranica preko `window.opener` može da preusmeri našu. --}}
            <a class="footer-powered" data-no-tr
               href="https://vtsappsteam.webflow.io"
               target="_blank" rel="noopener noreferrer"
               title="VTŠApps team">
                <span class="footer-powered__text">Powered by VTŠApps team</span>

                {{-- Dve varijante logotipa — prikaz bira CSS po aktivnoj temi
                     (core.css, sekcija „4. PODNOŽJE — POTPIS TIMA"). Bela
                     varijanta ide na tamnu podlogu, tamnoplava na svetlu.
                     alt stoji samo na jednoj: druga je ista informacija, pa
                     bi čitač ekrana naziv tima pročitao dva puta. --}}
                <img src="{{ asset_v('images/vtsappsteam-logo.png') }}"
                     alt="VTŠApps team"
                     class="footer-powered__logo footer-powered__logo--za-tamnu"
                     width="5229" height="1267"
                     loading="lazy" decoding="async">
                <img src="{{ asset_v('images/apps-team-dark.png') }}"
                     alt=""
                     class="footer-powered__logo footer-powered__logo--za-svetlu"
                     width="5229" height="1267"
                     loading="lazy" decoding="async">
            </a>
        </div>
    </div>
</footer>

{{-- ===== GLOBALNE NOTIFIKACIJE (toast) + CONFIRM MODAL ===== --}}
<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

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

{{-- Laravel flash poruke -> toast (čita ih notifications.js) --}}
<script>
    /* Da li je stranica otvorena kao prijavljen korisnik. Čita notifications.js:
       samo tada 401/419 sa servera znači „sesija je istekla" — gostu (asistent
       je dostupan i bez prijave) takav odgovor ne sme da otvori prijavu. */
    window.__ulogovan = @json(auth()->check());

    window.__flashMessages = [
        @if(session('success')) { message: @json(session('success')), type: 'success' }, @endif
        @if(session('error'))   { message: @json(session('error')),   type: 'error'   }, @endif
        @if(session('status'))  { message: @json(session('status')),  type: 'info'    }, @endif
        @foreach($errors->all() as $err) { message: @json($err), type: 'error' }, @endforeach
    ];
</script>
<script src="{{ asset_v('js/notifications.js') }}"></script>
<script src="{{ asset_v('js/pagination.js') }}"></script>
<script src="{{ asset_v('js/theme.js') }}"></script>
<script src="{{ asset_v('js/header-user.js') }}"></script>
<script src="{{ asset_v('js/pismo.js') }}"></script>
