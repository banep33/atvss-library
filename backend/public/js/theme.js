/* =============================================================
   TEMA — svetlo / tamno / sistem
   - Čuva izbor u localStorage ('theme': 'light' | 'dark' | 'system')
   - 'system' prati OS podešavanje (prefers-color-scheme) uživo
   - Primenu pre prvog paint-a radi inline snippet u <head> svake
     stranice; ovaj fajl sinhronizuje stanje i veže UI u headeru.
   Zavisi od: public/css/core.css, sekcija „1. TOKENI I TEMA" (tokeni i html.dark)
   ============================================================= */
(function () {
    'use strict';

    var KEY = 'theme';
    var mql = window.matchMedia('(prefers-color-scheme: dark)');

    function stored() {
        try { return localStorage.getItem(KEY) || 'system'; } catch (e) { return 'system'; }
    }

    function resolvedDark(mode) {
        return mode === 'dark' || (mode === 'system' && mql.matches);
    }

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function apply(mode, animate) {
        var root = document.documentElement;

        /* Postoje tačno dve teme — svetla i tamna. „Sistem" nije treća, nego
           bira jednu od te dve po podešavanju uređaja, pa je `dark` jedina
           klasa koja se postavlja. Zatečeni `dark-strong` iz starije verzije
           se sklanja, jer je mogao ostati na <html> iz keširanog snippeta. */
        function prebaci() {
            if (resolvedDark(mode)) root.classList.add('dark');
            else root.classList.remove('dark');
            root.classList.remove('dark-strong');
            updateUI(mode);
        }

        if (!animate || prefersReducedMotion()) {
            prebaci();
            return;
        }

        /* PRELAZ IZMEĐU TEMA — dva načina.

           1) View Transitions (Chromium, novi Firefox): pregledač snimi
              zatečeni i novi izgled i pretopi ih kao dve slike. Cena je ista
              bez obzira na to koliko elemenata stranica ima.

           2) Rezerva, za pregledače bez tog API-ja: klasa `.theme-transition`
              iz core.css, koja animira boje na SVAKOM elementu.

           Rezerva je bila jedini način i na katalogu se videlo zašto to nije
           dovoljno: nekoliko stotina kartica sa slikama znači isto toliko
           animacija u isto vreme, pa je prelaz tamo bio osetno sporiji nego
           na ostalim stranicama, koje imaju mnogo manje elemenata. */
        if (typeof document.startViewTransition === 'function') {
            document.startViewTransition(prebaci);
            return;
        }

        root.classList.add('theme-transition');
        window.setTimeout(function () { root.classList.remove('theme-transition'); }, 300);
        prebaci();
    }

    function setTheme(mode) {
        try { localStorage.setItem(KEY, mode); } catch (e) {}
        apply(mode, true);
    }

    // Prati promenu OS teme dok smo u 'system' režimu
    function onSystemChange() {
        if (stored() === 'system') apply('system', true);
    }
    if (mql.addEventListener) mql.addEventListener('change', onSystemChange);
    else if (mql.addListener) mql.addListener(onSystemChange);

    function updateUI(mode) {
        // Aktivna stavka u meniju (desktop header + mobilni drawer)
        var items = document.querySelectorAll('#themeToolMenu .header-tool__item, .js-theme-opt');
        items.forEach(function (it) {
            it.classList.toggle('is-active', it.getAttribute('data-theme') === mode);
        });

        /* Ikonica dugmeta: skripta samo upiše režim na <html>, a koja se od
           tri ikonice vidi bira CSS (core.css, „IKONICA NA DUGMETU ZA TEMU").

           Isti atribut postavlja i inline snippet u <head>-u (vidi
           partials/tema-init), pre prvog iscrtavanja — zato ikonica na novoj
           stranici stiže odmah tačna. Dok ju je ovde menjala skripta, u
           tamnom režimu se pri svakoj promeni stranice na tren video zatečeni
           markup (sunce) pa tek onda mesec. */
        document.documentElement.setAttribute('data-tema', mode);
    }

    function wire() {
        // Mobilne opcije teme (u drawer-u) — rade i bez desktop menija
        document.querySelectorAll('.js-theme-opt').forEach(function (it) {
            it.addEventListener('click', function () {
                setTheme(it.getAttribute('data-theme'));
            });
        });

        var tool = document.getElementById('themeTool');
        if (tool) {
            var btn = document.getElementById('themeToolBtn');
            var menu = document.getElementById('themeToolMenu');

            /* Namerno BEZ stopPropagation: klik mora da stigne i do
               document-a, gde header-user.js zatvara meni naloga. Ranije je
               zaustavljanje značilo da oba menija ostanu otvorena. */
            btn.addEventListener('click', function () {
                menu.classList.toggle('is-open');
            });

            menu.querySelectorAll('.header-tool__item').forEach(function (it) {
                it.addEventListener('click', function () {
                    setTheme(it.getAttribute('data-theme'));
                    menu.classList.remove('is-open');
                });
            });

            // Zatvori klikom van alatke (klik na samo dugme ne sme da
            // poništi toggle iznad).
            document.addEventListener('click', function (e) {
                if (!tool.contains(e.target)) menu.classList.remove('is-open');
            });

            // Esc zatvara meni — isto ponašanje ima i meni naloga.
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') menu.classList.remove('is-open');
            });
        }

        updateUI(stored());
    }

    window.ThemeManager = { set: setTheme, get: stored, apply: apply };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
