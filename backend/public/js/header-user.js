/* =============================================================
   MENI NALOGA U HEADERU
   - Dugme sa imenom i prezimenom otvara mali meni: „Moj profil"
     i „Odjava".
   - Odjava šalje skrivenu #logoutForm ODMAH, bez potvrde (za razliku
     od mobilnog drawer-a, koji i dalje traži potvrdu preko
     potvrdiOdjavu() iz notifications.js).
   - Izgled preuzima .header-tool__menu iz core.css, pa meni prati
     temu; tekst je običan DOM, pa ga hvata i prebacivanje pisma.
   ============================================================= */
(function () {
    'use strict';

    function wire() {
        var odjava = document.getElementById('headerLogoutBtn');
        if (odjava) {
            odjava.addEventListener('click', function () {
                var form = document.getElementById('logoutForm');
                if (form) form.submit();
            });
        }

        var wrap = document.getElementById('headerUser');
        var btn  = document.getElementById('headerUserBtn');
        var menu = document.getElementById('headerUserMenu');
        if (!wrap || !btn || !menu) return;

        function zatvori() {
            menu.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
        }

        btn.addEventListener('click', function () {
            var otvoren = menu.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', otvoren ? 'true' : 'false');

            /* Dva menija u headeru se međusobno isključuju: otvaranje jednog
               gasi drugi. Meni teme ima i sopstveni document-slušalac, ali se
               ovde gasi izričito da ponašanje ne zavisi od redosleda skripti. */
            if (otvoren) {
                var meniTeme = document.getElementById('themeToolMenu');
                if (meniTeme) meniTeme.classList.remove('is-open');
            }
        });

        /* Klik van menija ga zatvara; klik unutar alatke teme ne dira ovaj
           meni jer ga gasi već sam toggle iznad. */
        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) zatvori();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') zatvori();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
