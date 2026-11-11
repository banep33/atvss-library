/* =============================================================
   KLIJENTSKA PAGINACIJA — jedinstveni sistem za sve tabele
   - Aktivira se na svakoj <table data-paginate="N"> (N = redova po strani)
   - Radi nad već renderovanim redovima; poštuje spoljne filtere
     (pretraga koja postavlja inline display:none) i re-renderuje se
     automatski kada se sadržaj tbody-ja promeni (MutationObserver).
   - Prikaz u stilu StudIS: "Prikaži N zapisa" + "Prikaz X do Y od
     ukupno Z zapisa" + numerisani pager.
   - Visina tabele je ISTA na svakoj stranici: poslednja, nepotpuna stranica
     se dopunjava praznim redom do visine najviše (vidi popuni/izmeriCilj),
     pa sadržaj ispod tabele ne skače pri promeni stranice.
   Zavisi od: public/css/core.css, sekcija „5. PAGINACIJA"
   ============================================================= */
(function () {
    'use strict';

    var PAGE_SIZE_OPTIONS = [10, 25, 50, 100];

    function isMessageRow(row) {
        // Redovi tipa "Nema rezultata" (ćelija sa colspan) se ne paginiraju.
        return !!row.querySelector('td[colspan]');
    }

    /* Red-punilo drži visinu poslednje, nepotpune stranice (vidi popuni()). */
    function jePunilo(cvor) {
        return cvor.nodeType === 1 && cvor.classList && cvor.classList.contains('pg-punilo');
    }

    function isFilteredOut(row) {
        // Spoljni filter (pretraga) skriva red inline stilom display:none.
        return row.style.display === 'none';
    }

    function Paginator(table) {
        this.table = table;
        this.tbody = table.tBodies[0];
        if (!this.tbody) return;

        var size = parseInt(table.getAttribute('data-paginate'), 10);
        this.pageSize = (!isNaN(size) && size > 0) ? size : 10;
        this.minOption = PAGE_SIZE_OPTIONS[0];
        this.page = 1;
        this._rerenderQueued = false;

        this.buildControls();
        this.observe();
        this.render();
    }

    Paginator.prototype.anchor = function () {
        // Kontrole se ubacuju oko skrol-wrappera (da ne budu unutar
        // horizontalnog skrola tabele), a ako ga nema — oko same tabele.
        return this.table.closest('.table-responsive, .table-scroll, .table-wrap') || this.table;
    };

    Paginator.prototype.buildControls = function () {
        var self = this;
        var anchor = this.anchor();

        // Gornja traka
        var top = document.createElement('div');
        top.className = 'pg-topbar';
        var lbl1 = document.createElement('span');
        lbl1.textContent = 'Prikaži';
        var select = document.createElement('select');
        PAGE_SIZE_OPTIONS.forEach(function (n) {
            var o = document.createElement('option');
            o.value = n;
            o.textContent = n;
            if (n === self.pageSize) o.selected = true;
            select.appendChild(o);
        });
        select.addEventListener('change', function () {
            self.pageSize = parseInt(select.value, 10) || 10;
            self.page = 1;
            self.ciljVisina = 0;   // druga veličina stranice → druga visina
            self.render();
        });
        var lbl2 = document.createElement('span');
        lbl2.textContent = 'zapisa';
        top.appendChild(lbl1);
        top.appendChild(select);
        top.appendChild(lbl2);

        // Donja traka
        var foot = document.createElement('div');
        foot.className = 'pg-footer';
        var info = document.createElement('div');
        info.className = 'pg-info';
        var pager = document.createElement('div');
        pager.className = 'pg-pager';
        foot.appendChild(info);
        foot.appendChild(pager);

        anchor.parentNode.insertBefore(top, anchor);
        anchor.parentNode.insertBefore(foot, anchor.nextSibling);

        this.el = { top: top, foot: foot, info: info, pager: pager, select: select };
    };

    Paginator.prototype.dataRows = function () {
        // Redovi koji ulaze u paginaciju: nisu poruke i nisu filtrirani.
        var out = [];
        var rows = this.tbody.children;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            if (r.tagName !== 'TR') continue;
            if (isMessageRow(r)) continue;
            if (isFilteredOut(r)) continue;
            out.push(r);
        }
        return out;
    };

    Paginator.prototype.render = function () {
        var rows = this.dataRows();
        var total = rows.length;

        /* Promenio se skup redova (pretraga je nešto sakrila ili je stigao
           novi sadržaj) → zapamćena visina pune stranice više ne važi. */
        if (this._poslednjiTotal !== total) {
            this._poslednjiTotal = total;
            this.ciljVisina = 0;
        }
        var totalPages = Math.max(1, Math.ceil(total / this.pageSize));
        if (this.page > totalPages) this.page = totalPages;
        if (this.page < 1) this.page = 1;

        var start = (this.page - 1) * this.pageSize;
        var end = start + this.pageSize;

        for (var i = 0; i < rows.length; i++) {
            if (i >= start && i < end) rows[i].classList.remove('pg-hidden-row');
            else rows[i].classList.add('pg-hidden-row');
        }

        this.popuni(rows, start, end, total);

        // Sakrij kontrole kada nema potrebe za paginacijom.
        var show = total > this.minOption;
        this.el.top.style.display = show ? '' : 'none';
        this.el.foot.style.display = show ? '' : 'none';
        if (!show) return;

        var from = total === 0 ? 0 : start + 1;
        var to = Math.min(end, total);
        this.el.info.textContent = 'Prikaz ' + from + ' do ' + to + ' od ukupno ' + total + ' zapisa';

        this.renderPager(totalPages);
    };

    /**
     * Drži VISINU tabele istom na svakoj stranici.
     *
     * Poslednja stranica po pravilu ima manje redova od izabranog broja
     * zapisa (npr. 3 od 10), pa se tabela skupljala, a sa njom i sve ispod
     * nje — paginacija i podnožje su „skakali" pri promeni stranice.
     *
     * Ispod redova zato ide jedan prazan red koji dopunjava visinu do
     * `ciljVisina` (vidi izmeriCilj). Jedan red umesto N praznih: manje
     * čvorova, isti efekat. Red nosi `td[colspan]`, pa ga dataRows() ionako
     * preskače, i `aria-hidden` — za čitač ekrana ne postoji.
     */
    Paginator.prototype.popuni = function (rows, start, end, total) {
        var punilo = this.tbody.querySelector('tr.pg-punilo');

        // Sve staje na jednu stranicu → nema šta da se poravnava.
        if (total <= this.pageSize) {
            if (punilo) punilo.remove();
            return;
        }

        // Visina redova koji su na ovoj stranici prikazani (punilo se ne broji).
        var visinaStranice = 0;
        for (var i = start; i < end && i < rows.length; i++) {
            visinaStranice += rows[i].offsetHeight;
        }

        // Tabela još nije iscrtana (offsetHeight = 0) — meri sledeći prolaz.
        if (!visinaStranice) {
            if (punilo) punilo.remove();
            return;
        }

        if (!this.ciljVisina) this.ciljVisina = this.izmeriCilj(rows);

        var nedostaje = Math.round(this.ciljVisina - visinaStranice);

        // Razlika od piksel-dva nije vredna praznog reda.
        if (nedostaje <= 1) {
            if (punilo) punilo.remove();
            return;
        }

        if (!punilo) {
            punilo = document.createElement('tr');
            punilo.className = 'pg-punilo';
            punilo.setAttribute('aria-hidden', 'true');
            var celija = document.createElement('td');
            celija.colSpan = this.brojKolona();
            punilo.appendChild(celija);
        }

        punilo.style.height = nedostaje + 'px';
        this.tbody.appendChild(punilo);
    };

    /**
     * Visina NAJVIŠE stranice — merilo na koje se sve ostale dopunjuju.
     *
     * Redovi nisu iste visine (naziv knjige se u inventarnoj knjizi prelama u
     * dva-tri reda), pa procena „prosečna visina × broj redova koji
     * nedostaju" promašuje utoliko više ukoliko je poslednja stranica kraća.
     * Zato se meri stvarno: svi redovi se na trenutak otkriju, izmeri im se
     * visina, pa se prikaz vrati — i traži se najteži prozor od `pageSize`
     * uzastopnih redova.
     *
     * Merenje je sinhrono, u istom kadru, pa se ništa od toga ne vidi; radi
     * se jednom po veličini stranice i skupu redova (vidi `ciljVisina`).
     * Klijentska paginacija ionako drži sve redove u dokumentu, pa se ništa
     * dodatno ne dovlači.
     */
    Paginator.prototype.izmeriCilj = function (rows) {
        var i;
        var sakriveni = [];

        for (i = 0; i < rows.length; i++) {
            if (rows[i].classList.contains('pg-hidden-row')) {
                rows[i].classList.remove('pg-hidden-row');
                sakriveni.push(rows[i]);
            }
        }

        var visine = new Array(rows.length);
        for (i = 0; i < rows.length; i++) visine[i] = rows[i].offsetHeight;

        for (i = 0; i < sakriveni.length; i++) sakriveni[i].classList.add('pg-hidden-row');

        /* Zbir po STRANICAMA (redovi 0..N-1, N..2N-1, …), a ne po svakom
           prozoru od N uzastopnih redova: prozor koji preseca granicu
           stranica ne odgovara nijednom stvarnom prikazu, pa bi tabelu umeo
           da napuha znatno preko onoga što joj ikad zatreba. */
        var najvisi = 0;
        for (i = 0; i < visine.length; i += this.pageSize) {
            var zbir = 0;
            for (var j = i; j < i + this.pageSize && j < visine.length; j++) zbir += visine[j];
            if (zbir > najvisi) najvisi = zbir;
        }

        return najvisi;
    };

    /** Broj kolona tabele — za colspan reda-punila. */
    Paginator.prototype.brojKolona = function () {
        var zaglavlje = this.table.tHead && this.table.tHead.rows[0];
        if (zaglavlje) return zaglavlje.cells.length || 1;

        var prvi = this.tbody.rows[0];
        return (prvi && prvi.cells.length) || 1;
    };

    Paginator.prototype.renderPager = function (totalPages) {
        var self = this;
        var pager = this.el.pager;
        pager.innerHTML = '';

        function btn(label, page, opts) {
            opts = opts || {};
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pg-btn' + (opts.active ? ' pg-btn--active' : '');
            b.innerHTML = label;
            if (opts.disabled) b.disabled = true;
            else b.addEventListener('click', function () {
                self.page = page;
                self.render();
            });
            return b;
        }

        function ellipsis() {
            var s = document.createElement('span');
            s.className = 'pg-ellipsis';
            s.textContent = '…';
            return s;
        }

        var cur = this.page;
        pager.appendChild(btn('&laquo;', 1, { disabled: cur === 1 }));
        pager.appendChild(btn('&lsaquo;', cur - 1, { disabled: cur === 1 }));

        // Prozor stranica: prva, poslednja, i ±2 oko trenutne.
        var pages = [];
        for (var p = 1; p <= totalPages; p++) {
            if (p === 1 || p === totalPages || (p >= cur - 2 && p <= cur + 2)) pages.push(p);
        }
        var prev = 0;
        pages.forEach(function (p) {
            if (prev && p - prev > 1) pager.appendChild(ellipsis());
            pager.appendChild(btn(String(p), p, { active: p === cur }));
            prev = p;
        });

        pager.appendChild(btn('&rsaquo;', cur + 1, { disabled: cur === totalPages }));
        pager.appendChild(btn('&raquo;', totalPages, { disabled: cur === totalPages }));
    };

    Paginator.prototype.queueRender = function () {
        var self = this;
        if (this._rerenderQueued) return;
        this._rerenderQueued = true;
        window.requestAnimationFrame(function () {
            self._rerenderQueued = false;
            self.render();
        });
    };

    Paginator.prototype.observe = function () {
        var self = this;
        function samoPunilo(lista) {
            for (var i = 0; i < lista.length; i++) {
                if (!jePunilo(lista[i])) return false;
            }
            return true;
        }

        this.observer = new MutationObserver(function (mutations) {
            var relevant = false;
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                /* Ubacivanje i sklanjanje reda-punila je NAŠA izmena — kada
                   bi je posmatrač primio kao promenu sadržaja, render bi
                   pozivao sam sebe u krug. */
                if (m.type === 'childList') {
                    if (samoPunilo(m.addedNodes) && samoPunilo(m.removedNodes)) continue;
                    relevant = true;
                    break;
                }
                // Reaguj samo na promenu inline stila (spoljni filter),
                // ne na naše .pg-hidden-row (class) promene → nema petlje.
                if (m.type === 'attributes' && m.attributeName === 'style') { relevant = true; break; }
            }
            if (relevant) self.queueRender();
        });
        this.observer.observe(this.tbody, {
            childList: true,
            subtree: false,
            attributes: true,
            attributeFilter: ['style']
        });
    };

    /* Svi napravljeni paginatori — treba ih refresh(), koji pozivaju
       spoljne pretrage kada same sakriju redove. */
    var paginatori = [];

    function init() {
        var tables = document.querySelectorAll('table[data-paginate]');
        for (var i = 0; i < tables.length; i++) {
            if (tables[i].__paginated) continue;
            tables[i].__paginated = true;
            /* Skida CSS guard koji je do sada držao prikazanom samo prvu
               stranicu (core.css, `table[data-paginate]:not(.pg-init)`).
               Postavlja se PRE konstruktora, da tabela ostane cela vidljiva i
               ako inicijalizacija pukne. */
            tables[i].classList.add('pg-init');
            try { paginatori.push(new Paginator(tables[i])); } catch (e) { /* ne ruši stranicu */ }
        }
    }

    /**
     * Ponovo prestrani sve tabele.
     *
     * Posmatrač prati SADRŽAJ tbody-ja, ali ne i inline stil pojedinačnih
     * redova, pa filter koji redove samo sakrije (`display: none`) mora sam
     * da javi da se stanje promenilo — inače bi paginacija i dalje računala
     * i sakrivene redove.
     */
    function refresh() {
        for (var i = 0; i < paginatori.length; i++) {
            try { paginatori[i].render(); } catch (e) { /* ne ruši stranicu */ }
        }
    }

    /** Zaboravi izmerena merila i prestrani sve tabele. */
    function premeri() {
        for (var i = 0; i < paginatori.length; i++) paginatori[i].ciljVisina = 0;
        refresh();
    }

    /* Promena širine prozora menja prelamanje u ćelijama, pa i visinu redova —
       izmereno merilo (vidi izmeriCilj) posle toga više ne važi i tabela bi
       ostala viša ili niža nego što treba. */
    var odziv = null;
    window.addEventListener('resize', function () {
        window.clearTimeout(odziv);
        odziv = window.setTimeout(premeri, 150);
    });

    /* Fontovi i slike stižu tek posle prvog iscrtavanja i takođe menjaju
       visinu redova, pa se merilo tada uzima ponovo. */
    window.addEventListener('load', premeri);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.CoworkPagination = { init: init, refresh: refresh };
})();
