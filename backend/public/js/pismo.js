/* =============================================================
   PISMO — prebacivanje latinica <-> cirilica za CEO vidljivi tekst
   - Izvorni tekst u aplikaciji je srpska latinica (podrazumevano 'lat').
   - Kada je izabrana cirilica, transliterise se sav vidljivi tekst
     (naslovi, tabele, dugmad, opcije, placeholder-i, toast poruke,
     chat widget, login...) i MutationObserver konvertuje i sadrzaj
     koji JS naknadno ubaci (npr. rezultate pretrage, poruke bota).
   - Preskacu se: <script>, <style>, <textarea>, <code>, <pre>,
     input vrednosti, e-mail/URL tekst i elementi sa [data-no-tr].
   Cuva izbor u localStorage ('pismo': 'lat' | 'cyr').
   ============================================================= */
(function () {
    'use strict';

    var KEY = 'pismo';

    var LAT_SINGLE = {
        'A':'А','B':'Б','V':'В','G':'Г','D':'Д','Đ':'Ђ','E':'Е','Ž':'Ж','Z':'З',
        'I':'И','J':'Ј','K':'К','L':'Л','M':'М','N':'Н','O':'О','P':'П','R':'Р',
        'S':'С','T':'Т','Ć':'Ћ','U':'У','F':'Ф','H':'Х','C':'Ц','Č':'Ч','Š':'Ш',
        'a':'а','b':'б','v':'в','g':'г','d':'д','đ':'ђ','e':'е','ž':'ж','z':'з',
        'i':'и','j':'ј','k':'к','l':'л','m':'м','n':'н','o':'о','p':'п','r':'р',
        's':'с','t':'т','ć':'ћ','u':'у','f':'ф','h':'х','c':'ц','č':'ч','š':'ш'
    };

    var CYR_SINGLE = {
        'А':'A','Б':'B','В':'V','Г':'G','Д':'D','Ђ':'Đ','Е':'E','Ж':'Ž','З':'Z',
        'И':'I','Ј':'J','К':'K','Л':'L','Љ':'Lj','М':'M','Н':'N','Њ':'Nj','О':'O',
        'П':'P','Р':'R','С':'S','Т':'T','Ћ':'Ć','У':'U','Ф':'F','Х':'H','Ц':'C',
        'Ч':'Č','Џ':'Dž','Ш':'Š',
        'а':'a','б':'b','в':'v','г':'g','д':'d','ђ':'đ','е':'e','ж':'ž','з':'z',
        'и':'i','ј':'j','к':'k','л':'l','љ':'lj','м':'m','н':'n','њ':'nj','о':'o',
        'п':'p','р':'r','с':'s','т':'t','ћ':'ć','у':'u','ф':'f','х':'h','ц':'c',
        'ч':'č','џ':'dž','ш':'š'
    };

    /* ---- Osnovno preslovljavanje (slovo po slovo) ---- */

    function osnovnoUCyr(str) {
        if (!str) return str;
        str = str
            .replace(/Lj/g, 'Љ').replace(/LJ/g, 'Љ').replace(/lj/g, 'љ')
            .replace(/Nj/g, 'Њ').replace(/NJ/g, 'Њ').replace(/nj/g, 'њ')
            .replace(/Dž/g, 'Џ').replace(/DŽ/g, 'Џ').replace(/dž/g, 'џ');
        var out = '';
        for (var i = 0; i < str.length; i++) {
            var ch = str[i];
            out += (LAT_SINGLE[ch] !== undefined) ? LAT_SINGLE[ch] : ch;
        }
        return out;
    }

    /* Љ/Њ/Џ se pišu sa dva latinična slova, pa u okruženju velikih slova
       moraju da daju LJ/NJ/DŽ: „СВЕ КЊИГЕ" -> „SVE KNJIGE" (ne „KNjIGE"),
       a „Његош" i dalje -> „Njegoš". */
    var CYR_DIGRAFI = { 'Љ': 'Lj', 'Њ': 'Nj', 'Џ': 'Dž' };

    function jeSlovo(ch) {
        return !!ch && ch.toLowerCase() !== ch.toUpperCase();
    }

    function jeVeliko(ch) {
        return jeSlovo(ch) && ch === ch.toUpperCase();
    }

    function osnovnoULat(str) {
        if (!str) return str;
        var out = '';
        for (var i = 0; i < str.length; i++) {
            var ch = str[i];
            var digraf = CYR_DIGRAFI[ch];
            if (digraf !== undefined) {
                /* Merodavno je sledeće slovo; ako ga nema (kraj reči), gleda
                   se prethodno — „КОЊ" -> „KONJ". */
                var sledeci = str[i + 1];
                var sveVeliko = jeSlovo(sledeci) ? jeVeliko(sledeci) : jeVeliko(str[i - 1]);
                out += sveVeliko ? digraf.toUpperCase() : digraf;
                continue;
            }
            out += (CYR_SINGLE[ch] !== undefined) ? CYR_SINGLE[ch] : ch;
        }
        return out;
    }

    /* =================================================================
       IZUZECI

       1) UVEK_LATINICA — delovi teksta koji NIKAD ne prelaze u ćirilicu:
          nazivi fajlova i ekstenzije (.xlsx, slika.jpg) i međunarodne
          oznake koje se i u ćiriličnom tekstu pišu latinicom (ISBN, N/A).

       2) RECI — reči sa ustaljenim srpskim oblikom u ćirilici
          (email -> имејл, Excel -> Ексел). Preslikavaju se u OBA smera,
          pa povratak na latinicu vraća polazni oblik, a ne „imejl".

       Napomena: e-adrese i URL-ovi se ne diraju već u convertText().
       ================================================================= */

    var EKSTENZIJE = 'xlsx|xls|csv|pdf|docx|doc|pptx|ppt|txt|rtf|zip|rar|' +
                     'json|xml|html|htm|md|png|jpg|jpeg|gif|webp|svg|ico';
    var OZNAKE     = 'ISBN|ISSN|ISO|DOI|COBISS|N/A|PDF|CSV|HTML|CSS|URL';

    /* Broj clanske karte (CLAN-001) je identifikator, ne rec: „ЦЛАН-001"
       se vise ne bi poklapao sa onim sto pise u bazi i na samoj kartici.
       Mora da stoji i u App\Support\Pismo (BROJ_KARTICE) — paritet dve
       strane cuva tests/Unit/PismoParitetTest.php. */
    var BROJ_KARTICE = 'CLAN-[0-9]+';

    /* lat = polazni (izvorni) oblik u aplikaciji, cyr = ćirilični oblik.
       samoUCyr: oblik koji se koristi samo u smeru lat -> ćir (za povratak
       se koristi kanonski zapis iz prvog para sa istim ćiriličnim oblikom). */
    var RECI = [
        { lat: 'email',   cyr: 'имејл'  },
        { lat: 'e-mail',  cyr: 'имејл',  samoUCyr: true },
        { lat: 'excel',   cyr: 'ексел'  },
        { lat: 'online',  cyr: 'онлајн' },
        { lat: 'offline', cyr: 'офлајн' }
    ];

    var MAPA_U_CYR = {};
    var MAPA_U_LAT = {};
    var LAT_RECI = [];
    var CYR_RECI = [];

    (function pripremiReci() {
        for (var i = 0; i < RECI.length; i++) {
            var r = RECI[i];
            MAPA_U_CYR[r.lat] = r.cyr;
            LAT_RECI.push(r.lat);
            if (!r.samoUCyr && MAPA_U_LAT[r.cyr] === undefined) {
                MAPA_U_LAT[r.cyr] = r.lat;
                CYR_RECI.push(r.cyr);
            }
        }
    })();

    /* Ime fajla sme da sadrži i naša slova (izveštaj.pdf), pa klasa pokriva
       i dijakritike — inače bi se sačuvao samo rep imena („извеш" + „taj.pdf"). */
    var RE_U_CYR = new RegExp(
        '[A-Za-z0-9_\\u00C0-\\u024F-]*\\.(?:' + EKSTENZIJE + ')\\b' +
        '|\\b(?:' + BROJ_KARTICE + ')\\b' +
        '|\\b(?:' + OZNAKE + ')\\b' +
        '|\\b(?:' + LAT_RECI.join('|') + ')\\b',
        'gi'
    );

    /* \b ne radi nad ćirilicom (\w je ASCII), pa se granica reči proverava
       ručno: sledeći znak preko lookahead-a, prethodni u samoj funkciji. */
    var RE_U_LAT = new RegExp(
        '(?:' + CYR_RECI.join('|') + ')(?![\\u0400-\\u04FF])',
        'gi'
    );

    /* Velika/mala slova se preuzimaju od zatečenog oblika: EMAIL -> ИМЕЈЛ,
       Email -> Имејл, email -> имејл. */
    function primeniVelicinu(uzor, rec) {
        if (uzor.length > 1 && uzor === uzor.toUpperCase() && uzor !== uzor.toLowerCase()) {
            return rec.toUpperCase();
        }
        var prvo = uzor.charAt(0);
        if (prvo === prvo.toUpperCase() && prvo !== prvo.toLowerCase()) {
            return rec.charAt(0).toUpperCase() + rec.slice(1);
        }
        return rec;
    }

    /* Prolazi kroz tekst i na pogodke primenjuje `resolve`, a sve između
       prepušta osnovnom preslovljavanju. resolve vraća null kada pogodak
       ipak nije izuzetak (tada i on ide kroz osnovno preslovljavanje). */
    function saIzuzecima(str, re, resolve, osnovno) {
        var out = '', last = 0, m;
        re.lastIndex = 0;
        while ((m = re.exec(str)) !== null) {
            var zamena = resolve(m[0], str, m.index);
            if (zamena === null) continue;
            out += osnovno(str.slice(last, m.index)) + zamena;
            last = m.index + m[0].length;
        }
        return out + osnovno(str.slice(last));
    }

    function izuzetakUCyr(deo) {
        var rec = MAPA_U_CYR[deo.toLowerCase()];
        /* Ako nije reč iz mape, pogodak je naziv fajla ili oznaka — ostaje
           nepromenjen, tj. u latinici. */
        return rec ? primeniVelicinu(deo, rec) : deo;
    }

    function izuzetakULat(deo, str, idx) {
        var pre = idx > 0 ? str.charAt(idx - 1) : '';
        if (pre && /[Ѐ-ӿ]/.test(pre)) return null;   // deo veće reči
        var rec = MAPA_U_LAT[deo.toLowerCase()];
        return rec ? primeniVelicinu(deo, rec) : null;
    }

    function toCyr(str) {
        if (!str) return str;
        return saIzuzecima(str, RE_U_CYR, izuzetakUCyr, osnovnoUCyr);
    }

    function toLat(str) {
        if (!str) return str;
        return saIzuzecima(str, RE_U_LAT, izuzetakULat, osnovnoULat);
    }

    /* Normalizacija za PRETRAGU — ogledalo PHP TextUtils::normalize():
       cirilica -> latinica, mala slova, bez dijakritika
       (s->s, dj, z, c, c). Nezavisno od pisma i sa engleske tastature. */
    function fold(str) {
        if (!str) return '';
        /* Namerno osnovnoULat, a ne toLat: normalizacija za pretragu mora da
           bude ogledalo PHP TextUtils::normalize(), koji ne poznaje izuzetke
           (npr. „имејл" ovde ostaje „imejl", kao i na serveru). */
        str = osnovnoULat(str).toLowerCase();
        str = str
            .replace(/đ/g, 'dj')
            .replace(/š/g, 's')
            .replace(/ž/g, 'z')
            .replace(/č/g, 'c')
            .replace(/ć/g, 'c');
        return str.replace(/\s+/g, ' ').trim();
    }

    /* ---- DOM obilazak ---- */
    var SKIP_TAGS = { SCRIPT:1, STYLE:1, TEXTAREA:1, CODE:1, PRE:1, NOSCRIPT:1 };
    var SKIP_TEXT = /[\w.+-]+@|https?:\/\//;

    function convertText(t, fn) {
        if (!t || !t.trim()) return t;
        if (SKIP_TEXT.test(t)) return t;
        return fn(t);
    }

    function walk(node, fn) {
        if (!node) return;
        for (var n = node.firstChild; n; n = n.nextSibling) {
            if (n.nodeType === 3) {
                var c = convertText(n.nodeValue, fn);
                if (c !== n.nodeValue) n.nodeValue = c;
            } else if (n.nodeType === 1) {
                if (SKIP_TAGS[n.tagName]) {
                    /* Kod skipovanih polja (npr. <textarea> chat widgeta) preskače
                       se samo NJIHOV sadržaj/vrednost — placeholder je vidljiv
                       tekst i prati izabrano pismo, kao kod <input> polja. */
                    if (!(n.hasAttribute && n.hasAttribute('data-no-tr')) && n.placeholder) {
                        var sp = convertText(n.placeholder, fn);
                        if (sp !== n.placeholder) n.placeholder = sp;
                    }
                    continue;
                }
                if (n.hasAttribute && n.hasAttribute('data-no-tr')) continue;
                if (n.placeholder) {
                    var p = convertText(n.placeholder, fn);
                    if (p !== n.placeholder) n.placeholder = p;
                }
                if (n.hasAttribute && n.hasAttribute('title')) {
                    var ti = convertText(n.getAttribute('title'), fn);
                    if (ti !== n.getAttribute('title')) n.setAttribute('title', ti);
                }
                /* `alt` je tekst koji čitač ekrana izgovara — server ga
                   preslovljava (App\Support\Pismo), pa mora i klijent, inače
                   bi posle prebacivanja pisma ostao u prethodnom pismu. */
                if (n.hasAttribute && n.hasAttribute('alt')) {
                    var al = convertText(n.getAttribute('alt'), fn);
                    if (al !== n.getAttribute('alt')) n.setAttribute('alt', al);
                }
                walk(n, fn);
            }
        }
    }

    /* ---- Observer (aktivan samo u cirilici) ---- */
    var observer = null;
    /* Da li čvor živi UNUTAR podstabla koje se ne preslovljava — označenog sa
       [data-no-tr] ili unutar preskočene oznake (<script>, <code>…).

       walk() to zna dok silazi kroz stablo, jer nailazi na roditelja pre
       deteta. Posmatrač, međutim, dobija čvorove „iz sredine", bez tog
       konteksta, pa proveru mora da ponovi gledajući naviše. Bez ovoga je
       ćirilica prepisivala i sadržaj koji JS naknadno upiše u [data-no-tr] —
       najuočljivije natpis „Lat" na dugmetu za promenu pisma, koji je odmah
       posle upisa postajao „Лат". */
    function uPreskocenom(cvor) {
        var el = cvor.nodeType === 1 ? cvor : cvor.parentNode;

        for (; el && el.nodeType === 1; el = el.parentNode) {
            if (SKIP_TAGS[el.tagName]) return true;
            if (el.hasAttribute && el.hasAttribute('data-no-tr')) return true;
        }

        return false;
    }

    function startObserver() {
        if (observer) return;
        observer = new MutationObserver(function (muts) {
            observer.disconnect();
            for (var i = 0; i < muts.length; i++) {
                var m = muts[i];
                if (m.type === 'childList') {
                    for (var j = 0; j < m.addedNodes.length; j++) {
                        var nd = m.addedNodes[j];
                        if (uPreskocenom(nd)) continue;
                        if (nd.nodeType === 1) walk(nd, toCyr);
                        else if (nd.nodeType === 3) {
                            var c = convertText(nd.nodeValue, toCyr);
                            if (c !== nd.nodeValue) nd.nodeValue = c;
                        }
                    }
                } else if (m.type === 'characterData') {
                    if (uPreskocenom(m.target)) continue;
                    var c2 = convertText(m.target.nodeValue, toCyr);
                    if (c2 !== m.target.nodeValue) m.target.nodeValue = c2;
                }
            }
            reconnect();
        });
        reconnect();
    }
    function reconnect() {
        if (observer) observer.observe(document.body, {
            childList: true, subtree: true, characterData: true
        });
    }
    function stopObserver() {
        if (observer) { observer.disconnect(); observer = null; }
    }

    /* IZBOR PISMA ŽIVI U KOLAČIĆU, ne u localStorage — server mora da ga
       pročita da bi stranicu preslovio PRE slanja (App\Http\Middleware       PreslovljavanjePisma). localStorage se i dalje održava, radi korisnika
       koji su izbor napravili pre ove izmene. */
    function citajKolacic() {
        var m = document.cookie.match(/(?:^|;\s*)pismo=(lat|cyr)/);
        return m ? m[1] : null;
    }

    function upisiKolacic(script) {
        var godina = 60 * 60 * 24 * 365;
        document.cookie = KEY + '=' + script + ';path=/;max-age=' + godina + ';samesite=Lax';
    }

    function stored() {
        var izKolacica = citajKolacic();
        if (izKolacica) return izKolacica;

        var stari = null;
        try { stari = localStorage.getItem(KEY); } catch (e) {}

        /* Prvi dolazak posle izmene: prenesi stari izbor u kolačić, da već
           sledeća stranica stigne sa servera u ispravnom pismu. */
        if (stari === 'cyr' || stari === 'lat') {
            upisiKolacic(stari);
            return stari;
        }

        return 'lat';
    }

    function apply(script) {
        stopObserver();
        if (script === 'cyr') {
            walk(document.body, toCyr);
            startObserver();
        } else {
            walk(document.body, toLat);
        }
        updateUI(script);
    }

    function setPismo(script) {
        try { localStorage.setItem(KEY, script); } catch (e) {}
        upisiKolacic(script);
        /* Klik odmah prepiše zatečenu stranicu (bez osvežavanja), a kolačić
           obezbeđuje da sledeća stigne već preslovljena sa servera. */
        apply(script);
        document.documentElement.setAttribute('data-pismo', script);
    }

    function toggle() {
        setPismo(stored() === 'cyr' ? 'lat' : 'cyr');
    }

    /* Logo u headeru ima zasebnu sliku po pismu (lat/cir) — menja se src. */
    function updateLogo(script) {
        var logos = document.querySelectorAll('.js-pismo-logo');
        for (var i = 0; i < logos.length; i++) {
            var el = logos[i];
            var src = el.getAttribute(script === 'cyr' ? 'data-src-cyr' : 'data-src-lat');
            if (src && el.getAttribute('src') !== src) el.setAttribute('src', src);
        }
    }

    function updateUI(script) {
        updateLogo(script);
        /* Natpis je u pismu NA KOJE dugme prebacuje: dok je aktivna ćirilica
           nudi „Lat" (latinicom), a dok je aktivna latinica „Ћир"
           (ćirilicom). Isti par ispisuje i server u zaglavlju, pa se natpis
           ne menja kada se skripta izvrši. */
        var lbl = document.getElementById('pismoToolLabel');
        if (lbl) lbl.textContent = (script === 'cyr') ? 'Lat' : 'Ћир';

        /* Isto pravilo, pun naziv — stavka „Prikaz" u mobilnoj fioci. */
        var naz = document.querySelectorAll('.js-pismo-naziv');
        for (var n = 0; n < naz.length; n++) {
            naz[n].textContent = (script === 'cyr') ? 'Latinica' : 'Ћирилица';
        }
        var btns = document.querySelectorAll('#pismoToolBtn, .js-pismo-toggle');
        for (var i = 0; i < btns.length; i++) {
            btns[i].setAttribute('title', script === 'cyr' ? 'Prebaci na latinicu' : 'Prebaci na cirilicu');
        }
    }

    window.Pismo = {
        toCyr: toCyr,
        toLat: toLat,
        fold: fold,
        get: stored,
        set: setPismo,
        toggle: toggle
    };
    window.foldSearch = fold;

    function wire() {
        var btns = document.querySelectorAll('#pismoToolBtn, .js-pismo-toggle');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', toggle);
        }
        var s = stored();

        /* Layout upisuje data-pismo na <html> — kad se poklapa sa izborom,
           server je već poslao stranicu u tom pismu, pa nema šta da se
           prepisuje. Ostaje samo observer, zbog sadržaja koji stiže iz
           API-ja (rezultati pretrage, odgovori asistenta). */
        var serverskoPismo = document.documentElement.getAttribute('data-pismo');

        if (s === 'cyr') {
            if (serverskoPismo === 'cyr') {
                startObserver();
                updateUI('cyr');
            } else {
                apply('cyr');
            }
        } else {
            updateUI('lat');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
