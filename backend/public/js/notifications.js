/* =============================================================
   GLOBALNE NOTIFIKACIJE — jedinstveni sistem za celu aplikaciju
   - showToast(message, type, options)  -> zeleni/crveni toast sa
     odbrojavanjem i X dugmetom (top-desno)
   - confirmDialog(options|message)      -> Promise<boolean>, modal
     u stilu "otkazivanje rezervacije"
   - potvrdiOdjavu()                     -> potvrda pre odjave
   Zavisi od: public/css/core.css, sekcija „6. NOTIFIKACIJE I DIJALOZI",
   i markup-a u app-footer.
   ============================================================= */
(function () {
    'use strict';

    /* ---------------- TOAST ---------------- */
    var ICONS = {
        success: '<i class="fa-solid fa-check"></i>',
        error:   '<i class="fa-solid fa-xmark"></i>',
        info:    '<i class="fa-solid fa-circle-info"></i>',
        warning: '<i class="fa-solid fa-triangle-exclamation"></i>'
    };

    function getContainer() {
        var c = document.getElementById('toastContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toastContainer';
            c.className = 'toast-container';
            document.body.appendChild(c);
        }
        return c;
    }

    /**
     * @param {string} message
     * @param {string} type    'success' | 'error' | 'info' | 'warning'
     * @param {object} options { duration: ms }
     */
    function showToast(message, type, options) {
        type = type || 'success';
        options = options || {};
        var duration = typeof options.duration === 'number' ? options.duration : 5000;

        var container = getContainer();

        var toast = document.createElement('div');
        toast.className = 'toast ' + type;

        var icon = document.createElement('span');
        icon.className = 'toast__icon';
        icon.innerHTML = ICONS[type] || ICONS.success;

        var msg = document.createElement('span');
        msg.className = 'toast__message';
        msg.textContent = message;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('aria-label', 'Zatvori');
        close.innerHTML = '<i class="fa-solid fa-xmark"></i>';

        var progress = document.createElement('span');
        progress.className = 'toast__progress';
        progress.style.animationDuration = duration + 'ms';

        toast.appendChild(icon);
        toast.appendChild(msg);
        toast.appendChild(close);
        toast.appendChild(progress);
        container.appendChild(toast);

        var timer = null;

        function dismiss() {
            if (toast.__closed) return;
            toast.__closed = true;
            if (timer) clearTimeout(timer);
            toast.classList.add('toast--hide');
            toast.addEventListener('animationend', function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            });
        }

        close.addEventListener('click', dismiss);

        // Pauza odbrojavanja na hover
        toast.addEventListener('mouseenter', function () {
            if (timer) clearTimeout(timer);
            progress.style.animationPlayState = 'paused';
        });
        toast.addEventListener('mouseleave', function () {
            progress.style.animationPlayState = 'running';
            timer = setTimeout(dismiss, 1200);
        });

        if (duration > 0) {
            timer = setTimeout(dismiss, duration);
        }

        return { dismiss: dismiss };
    }

    /* ---------------- CONFIRM MODAL ---------------- */
    var CONFIRM_ICONS = {
        danger:  '<i class="fa-solid fa-triangle-exclamation"></i>',
        warning: '<i class="fa-solid fa-triangle-exclamation"></i>',
        success: '<i class="fa-solid fa-circle-check"></i>',
        primary: '<i class="fa-solid fa-circle-question"></i>'
    };

    /**
     * confirmDialog("Tekst") ILI confirmDialog({ title, message, type,
     * confirmText, cancelText }). Vraća Promise<boolean>.
     */
    function confirmDialog(opts) {
        if (typeof opts === 'string') opts = { message: opts };
        opts = opts || {};

        /* samoPotvrda: dijalog sa jednim dugmetom (obaveštenje, ne pitanje) —
           koristi ga alertDialog ispod. */
        var samoPotvrda = !!opts.samoPotvrda;
        var type = opts.type || 'danger';
        var title = opts.title || 'Potvrda';
        var message = opts.message || 'Da li ste sigurni?';
        var confirmText = opts.confirmText || 'Potvrdi';
        var cancelText = opts.cancelText || 'Otkaži';

        return new Promise(function (resolve) {
            var overlay = document.getElementById('confirmOverlay');
            // Ako markup nije prisutan (npr. stranica bez footera), fallback na native
            if (!overlay) {
                if (samoPotvrda) {
                    window.alert(message);
                    resolve(true);
                } else {
                    resolve(window.confirm(message));
                }
                return;
            }

            var modal = overlay.querySelector('.confirm-modal');
            var iconEl = overlay.querySelector('.confirm-modal__icon');
            var titleEl = overlay.querySelector('.confirm-modal__title');
            var textEl = overlay.querySelector('.confirm-modal__text');
            var okBtn = overlay.querySelector('.confirm-btn--confirm');
            var cancelBtn = overlay.querySelector('.confirm-btn--cancel');

            modal.setAttribute('data-type', type);
            iconEl.innerHTML = CONFIRM_ICONS[type] || CONFIRM_ICONS.danger;
            titleEl.textContent = title;
            textEl.textContent = message;
            okBtn.textContent = confirmText;
            cancelBtn.textContent = cancelText;
            cancelBtn.style.display = samoPotvrda ? 'none' : '';

            function cleanup(result) {
                overlay.classList.remove('is-open');
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                overlay.removeEventListener('click', onBackdrop);
                document.removeEventListener('keydown', onKey);
                resolve(result);
            }
            function onOk() { cleanup(true); }
            function onCancel() { cleanup(false); }
            function onBackdrop(e) { if (e.target === overlay) cleanup(!samoPotvrda ? false : true); }
            function onKey(e) {
                if (e.key === 'Escape') cleanup(samoPotvrda ? true : false);
                if (e.key === 'Enter') cleanup(true);
            }

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            overlay.addEventListener('click', onBackdrop);
            document.addEventListener('keydown', onKey);

            overlay.classList.add('is-open');
            okBtn.focus();
        });
    }

    /**
     * alertDialog("Tekst") ILI alertDialog({ title, message, type, confirmText })
     * — isti dijalog kao confirmDialog, ali sa jednim dugmetom (obaveštenje).
     */
    function alertDialog(opts) {
        if (typeof opts === 'string') opts = { message: opts };
        opts = opts || {};
        opts.samoPotvrda = true;
        opts.title = opts.title || 'Obaveštenje';
        opts.confirmText = opts.confirmText || 'Ok';

        return confirmDialog(opts);
    }

    /* ---------------- OBAVEZNA POLJA ---------------- */
    /* Prazno obavezno polje dobija crvenu ivicu sa ikonicom i poruku ispod
       („Polje E-pošta je obavezno."), a forma jedan zbirni dijalog. Ovim se
       zamenjuje podrazumevani „balončić" pregledača, koji ne prati temu
       aplikacije, nestaje sam i prikazuje samo PRVO prazno polje. */

    /* Naziv polja za poruku: prvo <label for>, pa obuhvatajući <label>,
       pa aria-label/placeholder/name. Zvezdica obaveznog polja se skida. */
    function nazivPolja(polje) {
        var labela = null;

        if (polje.id) {
            labela = document.querySelector('label[for="' + polje.id.replace(/"/g, '\\"') + '"]');
        }
        if (!labela && polje.closest) labela = polje.closest('label');

        var tekst = labela
            ? labela.textContent
            : (polje.getAttribute('aria-label') || polje.placeholder || polje.name || 'Polje');

        return tekst.replace(/\*/g, '').replace(/\s+/g, ' ').trim().replace(/:$/, '');
    }

    /* E-pošta: format koji se može proveriti dok korisnik kuca. Namerno
       labav izraz (jedan @, tačka u domenu) — stroga provera adrese radi se
       tek na serveru, ovde je cilj brza povratna informacija. */
    var RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    function jeEmailPolje(polje) {
        return polje.type === 'email' || polje.getAttribute('inputmode') === 'email';
    }

    /* ---------- BROJ TELEFONA ----------
       Polje se proverava samo ako nosi `data-telefon` (isti opt-in princip
       kao živa provera e-pošte), pa se pravila ne nameću svakom tekstualnom
       polju u aplikaciji. Prazno polje je ispravno — telefon nije obavezan.

       Priznaju se dva zapisa, onako kako se broj i unosi u praksi:
         +381 61 234 567  → međunarodni, najviše 13 znakova
         061 234 567      → domaći, najmanje 10 znakova
       Razmaci, crtice, kose crte i zagrade su samo prored i ne broje se;
       slova nisu dozvoljena ni u jednom zapisu. */
    var RE_TELEFON_SLOVO = /\p{L}/u;
    var RE_TELEFON_PRORED = /[\s\-/().]/g;

    function jeTelefonPolje(polje) {
        return polje.hasAttribute && polje.hasAttribute('data-telefon');
    }

    /** Vraća poruku o grešci, ili prazan string kada je broj ispravan. */
    function greskaTelefona(vrednost) {
        var unos = String(vrednost || '').trim();
        if (unos === '') return '';

        if (RE_TELEFON_SLOVO.test(unos)) {
            return 'Broj telefona ne sme da sadrži slova.';
        }

        var broj = unos.replace(RE_TELEFON_PRORED, '');

        if (!/^\+?\d+$/.test(broj)) {
            return 'Broj telefona sme da sadrži samo cifre, uz „+" na početku.';
        }

        if (broj.charAt(0) === '+') {
            if (broj.length > 13) {
                return 'Broj telefona u formatu +381… sme da ima najviše 13 znakova.';
            }
            return '';
        }

        if (broj.length < 10) {
            return 'Broj telefona u formatu 06… mora imati najmanje 10 znakova.';
        }

        return '';
    }

    /* Živa provera (zelena kvačica dok se kuca, greška formata na izlasku iz
       polja) je OPT-IN: traži je polje sa atributom `data-ziva-provera`.
       Za sada je to samo e-pošta na prijavi — ostala polja u aplikaciji
       (profil, dodavanje člana…) ostaju bez tog dizajna. */
    function imaZivuProveru(polje) {
        return polje.hasAttribute && polje.hasAttribute('data-ziva-provera');
    }

    /** Vrednost kraća od `minlength` (prazna polja hvata provera iznad). */
    function prekratko(polje) {
        var min = parseInt(polje.getAttribute('minlength'), 10);

        return !isNaN(min) && String(polje.value || '').length < min;
    }

    /** 1 znak, 2-4 znaka, 5+ znakova. */
    function znakova(n) {
        n = parseInt(n, 10);
        var jedinice = n % 10, desetice = n % 100;

        if (desetice >= 11 && desetice <= 14) return 'znakova';
        if (jedinice === 1) return 'znak';
        if (jedinice >= 2 && jedinice <= 4) return 'znaka';

        return 'znakova';
    }

    function oznaciIspravno(polje) {
        polje.classList.add('polje--ispravno');
    }

    function ocistiIspravno(polje) {
        polje.classList.remove('polje--ispravno');
    }

    function oznaciGresku(polje, poruka) {
        ocistiIspravno(polje);
        polje.classList.add('polje--greska');
        polje.setAttribute('aria-invalid', 'true');

        var sledeci = polje.nextElementSibling;
        if (!sledeci || !sledeci.classList.contains('polje-greska')) {
            sledeci = document.createElement('span');
            sledeci.className = 'polje-greska';
            polje.insertAdjacentElement('afterend', sledeci);
        }
        sledeci.textContent = poruka;
    }

    function ocistiGresku(polje) {
        polje.classList.remove('polje--greska');
        polje.removeAttribute('aria-invalid');

        var sledeci = polje.nextElementSibling;
        if (sledeci && sledeci.classList.contains('polje-greska')) sledeci.remove();
    }

    /**
     * Proverava sva [required] polja u formi. Vraća true ako je forma
     * ispravna. Uz { dijalog: true } prikazuje i zbirno obaveštenje.
     */
    function proveriObavezna(forma, opcije) {
        if (!forma) return true;
        opcije = opcije || {};

        var prvoPrazno = null;

        /* Uz obavezna, proveravaju se i polja sa `minlength` i `data-telefon`
           koja to nisu: smeju da ostanu prazna, ali popunjena moraju da
           ispune svoje pravilo. */
        Array.prototype.forEach.call(forma.querySelectorAll('[required], [minlength], [data-telefon]'), function (polje) {
            /* Skrivena polja se preskaču — npr. „Broj primeraka" nestaje kada
               je knjiga označena kao e-knjiga. */
            if (polje.disabled || polje.offsetParent === null) return;

            var obavezno = polje.hasAttribute('required');

            var prazno = (polje.type === 'checkbox' || polje.type === 'radio')
                ? !polje.checked
                : !String(polje.value || '').trim();

            if (prazno && !obavezno) {
                ocistiGresku(polje);   // prazno neobavezno polje je u redu
                return;
            }

            if (prazno) {
                oznaciGresku(polje, 'Polje ' + nazivPolja(polje) + ' je obavezno.');
                if (!prvoPrazno) prvoPrazno = polje;
            } else if (jeEmailPolje(polje) && !RE_EMAIL.test(String(polje.value).trim())) {
                oznaciGresku(polje, 'E-pošta nije u ispravnom formatu.');
                if (!prvoPrazno) prvoPrazno = polje;
            } else if (jeTelefonPolje(polje) && greskaTelefona(polje.value)) {
                oznaciGresku(polje, greskaTelefona(polje.value));
                if (!prvoPrazno) prvoPrazno = polje;
            } else if (prekratko(polje)) {
                /* `minlength` inače prijavljuje pregledač, i to na engleskom. */
                oznaciGresku(polje, 'Polje ' + nazivPolja(polje) + ' mora imati najmanje '
                    + polje.getAttribute('minlength') + ' ' + znakova(polje.getAttribute('minlength')) + '.');
                if (!prvoPrazno) prvoPrazno = polje;
            } else {
                ocistiGresku(polje);
                if (jeEmailPolje(polje) && imaZivuProveru(polje)) oznaciIspravno(polje);
            }
        });

        /* Greška se briše čim korisnik počne da popunjava polje. Slušalac se
           veže jednom po formi. */
        if (!forma.dataset.validacijaVezana) {
            forma.dataset.validacijaVezana = '1';
            forma.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('polje--greska')) ocistiGresku(e.target);
            });
            forma.addEventListener('change', function (e) {
                if (e.target && e.target.classList.contains('polje--greska')) ocistiGresku(e.target);
            });
        }

        if (!prvoPrazno) return true;

        prvoPrazno.focus();

        if (opcije.dijalog) {
            alertDialog({
                type: 'danger',
                title: 'Obavezna polja',
                message: 'Molimo Vas, popunite sva obavezna polja i pokušajte ponovo.'
            });
        }

        return false;
    }

    /* Živa provera e-pošte: čim uneto ima oblik adrese, polje dobija zelenu
       ivicu sa kvačicom. Greška zbog formata se NE prikazuje dok se kuca
       (smetalo bi na svakom slovu) — javi se na izlasku iz polja ili pri
       slanju forme. Slušalac je na dokumentu, pa hvata i polja koja stignu
       kasnije (modali). */
    function zivaProveraEmaila(e) {
        var polje = e.target;
        if (!polje || polje.tagName !== 'INPUT' || !jeEmailPolje(polje) || !imaZivuProveru(polje)) return;

        var vrednost = String(polje.value || '').trim();

        if (vrednost !== '' && RE_EMAIL.test(vrednost)) {
            ocistiGresku(polje);
            oznaciIspravno(polje);
        } else {
            ocistiIspravno(polje);
        }
    }

    function proveriEmailNaIzlasku(e) {
        var polje = e.target;
        if (!polje || polje.tagName !== 'INPUT' || !jeEmailPolje(polje) || !imaZivuProveru(polje)) return;

        var vrednost = String(polje.value || '').trim();
        if (vrednost !== '' && !RE_EMAIL.test(vrednost)) {
            oznaciGresku(polje, 'E-pošta nije u ispravnom formatu.');
        }
    }

    document.addEventListener('input', zivaProveraEmaila);
    document.addEventListener('blur', proveriEmailNaIzlasku, true); // blur ne „buburi"

    /* ---------------- ODJAVA ---------------- */
    function potvrdiOdjavu() {
        confirmDialog({
            type: 'danger',
            title: 'Odjava',
            message: 'Da li ste sigurni da želite da se odjavite?',
            confirmText: 'Odjavi se',
            cancelText: 'Otkaži'
        }).then(function (ok) {
            if (ok) {
                var form = document.getElementById('logoutForm');
                if (form) form.submit();
            }
        });
    }

    /* ---------------- ISTEKLA SESIJA ----------------
       Kada sesija istekne, korisnik to na punom učitavanju stranice sazna od
       servera (redirect na prijavu sa porukom, ili strana errors/419). Ali
       radnje koje idu kroz fetch — rezervacija, otkazivanje, notifikacije —
       dobiju samo 401/419 i do sada su se ponašale kao „ništa se nije desilo":
       dugme klikne, a ne dogodi se ništa i korisnik ne zna zašto.

       Zato se `fetch` obavija tankim omotačem koji ODGOVOR NE DIRA (vraća ga
       nepromenjenog pozivaocu), a uz put prepozna ta dva statusa, javi
       korisniku i odvede ga na prijavu. Radi samo za prijavljenog korisnika i
       samo za zahteve ka sopstvenom serveru; okida se najviše jednom. */
    function nasZahtev(ulaz) {
        try {
            var url = new URL(
                (ulaz && typeof ulaz === 'object' && ulaz.url) ? ulaz.url : String(ulaz),
                window.location.href
            );
            return url.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    var sesijaVecPrijavljena = false;

    function sesijaIstekla() {
        if (sesijaVecPrijavljena) return;
        sesijaVecPrijavljena = true;

        showToast('Sesija je istekla. Prijavite se ponovo da biste nastavili rad.', 'warning', {
            duration: 6000
        });

        /* Kratka pauza da poruka stigne da se pročita pre prelaska na prijavu. */
        window.setTimeout(function () {
            window.location.href = '/login';
        }, 2500);
    }

    if (typeof window.fetch === 'function') {
        var izvorniFetch = window.fetch.bind(window);

        window.fetch = function (ulaz, opcije) {
            return izvorniFetch(ulaz, opcije).then(function (odgovor) {
                if (window.__ulogovan &&
                    (odgovor.status === 401 || odgovor.status === 419) &&
                    nasZahtev(ulaz)) {
                    sesijaIstekla();
                }
                return odgovor;
            });
        };
    }

    /* ---------------- EXPORTS ---------------- */
    window.showToast = showToast;
    window.sesijaIstekla = sesijaIstekla;
    window.confirmDialog = confirmDialog;
    window.alertDialog = alertDialog;
    window.proveriObavezna = proveriObavezna;
    window.potvrdiOdjavu = potvrdiOdjavu;

    /* Napomena: mobilno "Odjavi se" dugme handle-uje mobile-nav.js (poziva potvrdiOdjavu). */
    document.addEventListener('DOMContentLoaded', function () {
        /* Prikaži Laravel flash poruke kao toast (postavlja app-footer) */
        if (window.__flashMessages && window.__flashMessages.length) {
            window.__flashMessages.forEach(function (f) {
                showToast(f.message, f.type);
            });
            window.__flashMessages = [];
        }

        /* Polje koje je već popunjeno ispravnom adresom (npr. vraćena stara
           vrednost posle neuspele prijave) odmah dobija zelenu potvrdu — osim
           ako na njemu stoji greška sa servera. */
        document.querySelectorAll('input[type="email"][data-ziva-provera]').forEach(function (polje) {
            var vrednost = String(polje.value || '').trim();
            if (vrednost !== '' && RE_EMAIL.test(vrednost) && !polje.classList.contains('polje--greska')) {
                oznaciIspravno(polje);
            }
        });
    });
})();
