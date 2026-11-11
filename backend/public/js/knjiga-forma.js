/* ═══════════════════════════════════════════════════════════════════════
   OBRAZAC ZA KNJIGU — dodavanje, izmena i brisanje

   Jedan kod za sve tri stranice koje menjaju knjigu: katalog
   (knjige/index), stranicu knjige (knjige/show) i inventarnu knjigu
   (admin/inventarna-knjiga). Markup obrasca je u
   resources/views/partials/knjiga-form-modal.blade.php.

   Ranije je isti posao stajao prepisan na dva mesta (knjige-index.js i
   inline skripta u show.blade), sa sitnim razlikama koje su se s vremenom
   razišle — npr. broj primeraka se pri dodavanju nije ni slao serveru.

   Stranica javlja ŠTA da se osveži posle uspešne radnje:

       window.KnjigaForma.posleCuvanja  = (rezim, data) => { ... }
       window.KnjigaForma.posleBrisanja = (id, data)    => { ... }

   `rezim` je 'dodavanje' ili 'izmena'. Ako stranica ništa ne postavi,
   podrazumevano se osvežava cela stranica — tako je stanje sigurno
   usklađeno i kod serverski iscrtanih tabela (inventarna knjiga).
   ═══════════════════════════════════════════════════════════════════════ */

window.KnjigaForma = window.KnjigaForma || {};

(function () {
    'use strict';

    // Id knjige koja se trenutno menja; null = dodavanje nove.
    let knjigaUIzmeni = null;

    const $ = (id) => document.getElementById(id);

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    /* Podrazumevano osvežavanje: puno učitavanje stranice. Katalog ga
       zamenjuje svojim (osveži samo mrežu knjiga, bez trzaja). */
    function osveziStranicu() {
        setTimeout(() => window.location.reload(), 800);
    }

    // ═══════════════════════════════════════════════════════════════════
    // OTVARANJE / ZATVARANJE
    // ═══════════════════════════════════════════════════════════════════

    /* Prikaz polja koja zavise od tipa unosa:
       - e-knjiga ima link, a nema primerke,
       - broj primeraka se zadaje samo pri dodavanju. */
    function osveziZavisnaPolja() {
        const jeEknjiga = $('je_eknjiga')?.checked;
        const linkGrupa = $('eknjigaLinkGroup');
        const brojGrupa = $('brojPrimerakaGroup');
        const brojPolje = $('broj_primeraka');

        if (linkGrupa) linkGrupa.style.display = jeEknjiga ? 'block' : 'none';

        const prikaziBroj = !jeEknjiga && knjigaUIzmeni === null;
        if (brojGrupa) brojGrupa.style.display = prikaziBroj ? 'block' : 'none';
        // Skriveno polje ne sme da ostane „obavezno" — proveriObavezna()
        // bi tražila vrednost koju korisnik ne vidi.
        if (brojPolje) brojPolje.toggleAttribute('required', prikaziBroj);
    }
    window.toggleEknjigeField = osveziZavisnaPolja;

    function prikaziPostojecuSliku(putanja) {
        const okvir = $('currentSlikaPreview');
        if (!okvir) return;

        if (!putanja) {
            okvir.style.display = 'none';
            return;
        }

        okvir.style.display = 'block';
        const img = $('currentSlikaImg');
        const ime = $('currentSlikaName');
        if (img) img.src = '/' + putanja;
        if (ime) ime.textContent = 'Fajl: ' + putanja.split('/').pop();
    }

    /* Kvadratići žanrova; `oznaceni` je niz žanrova knjige (iz /api/knjige/{id}). */
    async function ucitajZanrove(oznaceni = []) {
        const container = $('zanroviCheckboxes');
        if (!container) return;

        try {
            const res = await fetch('/api/zanrovi');
            const zanrovi = await res.json();

            container.innerHTML = '';
            zanrovi.forEach(zanr => {
                const label = document.createElement('label');
                label.className = 'checkbox-label';
                label.innerHTML = `
                    <input type="checkbox" name="zanrovi[]" value="${zanr.id}"
                        ${oznaceni.some(z => z.id == zanr.id) ? 'checked' : ''}>
                    ${zanr.naziv}
                `;
                container.appendChild(label);
            });
        } catch (err) {
            console.error('Greška pri učitavanju žanrova:', err);
        }
    }

    window.openAddModal = async function () {
        knjigaUIzmeni = null;
        $('modalTitle').textContent = 'Dodaj Knjigu';
        $('bookForm').reset();
        $('bookId').value = '';

        prikaziPostojecuSliku(null);
        await ucitajZanrove();

        // Inventarski broj dodeljuje server (sledeći slobodan), pa se ne kuca
        // ručno. Izgled zaključanog polja dolazi iz .form-group input[readonly]
        // (tokeni teme) — inline boja bi pobedila CSS i ostala bela u tamnoj temi.
        const invInput = $('in_broj');
        invInput.readOnly = true;
        try {
            const res = await fetch('/api/admin/sledeci-inventarski-broj');
            const data = await res.json();
            invInput.value = data.broj;
        } catch (err) {
            console.error('Greška pri dohvatanju inventarskog broja:', err);
        }

        osveziZavisnaPolja();
        $('bookModal').classList.add('active');
    };

    window.openEditModal = async function (id) {
        knjigaUIzmeni = id;
        $('modalTitle').textContent = 'Izmeni Knjigu';

        try {
            const res = await fetch(`/api/knjige/${id}`);
            const data = await res.json();

            $('bookId').value           = data.id;
            $('in_broj').value          = data.in_broj || '';
            $('signatura').value        = data.signatura || '';
            $('datum').value            = data.datum ? String(data.datum).substring(0, 10) : '';
            $('autor').value            = data.autor || '';
            $('naziv_knjige').value     = data.naziv_knjige || '';
            $('mesto_izdavanja').value  = data.mesto_izdavanja || '';
            $('godina_izdavanja').value = data.godina_izdavanja || '';
            $('izdavac').value          = data.izdavac || '';
            $('nacin_nabavke').value    = data.nacin_nabavke || '';
            $('isbn').value             = data.isbn || '';
            $('opis').value             = data.opis || '';
            $('je_eknjiga').checked     = data.je_eknjiga || false;
            $('eknjiga_link').value     = data.eknjiga_link || '';

            // Inventarski broj se ne menja ni ovde: primerci nose sopstvene
            // inventarske brojeve izvedene iz njega, pa bi izmena razdvojila
            // knjigu od redova u inventarnoj knjizi.
            $('in_broj').readOnly = true;

            prikaziPostojecuSliku(data.slika);
            $('slika').value = '';

            await ucitajZanrove(data.zanrovi || []);

            osveziZavisnaPolja();
            $('bookModal').classList.add('active');
        } catch (error) {
            showToast('Greška pri učitavanju podataka', 'error');
            console.error('Error:', error);
        }
    };

    window.closeModal = function () {
        $('bookModal')?.classList.remove('active');
    };

    // ═══════════════════════════════════════════════════════════════════
    // ČUVANJE
    // ═══════════════════════════════════════════════════════════════════

    async function sacuvaj(e) {
        e.preventDefault();

        // Forma ima `novalidate`, pa ovo zamenjuje podrazumevanu proveru
        // pregledača: crvena ivica, poruka ispod polja i jedan zbirni dijalog.
        if (typeof proveriObavezna === 'function' && !proveriObavezna(e.currentTarget, { dijalog: true })) {
            return;
        }

        const izmena = knjigaUIzmeni !== null;
        const formData = new FormData();

        ['in_broj', 'signatura', 'datum', 'autor', 'naziv_knjige', 'mesto_izdavanja',
         'godina_izdavanja', 'izdavac', 'nacin_nabavke', 'isbn', 'opis', 'eknjiga_link']
            .forEach(polje => formData.append(polje, $(polje).value || ''));

        const jeEknjiga = $('je_eknjiga').checked;
        formData.append('je_eknjiga', jeEknjiga ? '1' : '0');

        // Broj primeraka ima smisla samo pri dodavanju fizičke knjige.
        if (!izmena && !jeEknjiga) {
            formData.append('broj_primeraka', $('broj_primeraka').value || '1');
        }

        const slikaInput = $('slika');
        if (slikaInput.files.length > 0) {
            formData.append('slika', slikaInput.files[0]);
        }

        Array.from(document.querySelectorAll('input[name="zanrovi[]"]:checked'))
            .forEach(cb => formData.append('zanrovi[]', cb.value));

        const dugme = e.currentTarget.querySelector('.btn-save');
        if (dugme) dugme.disabled = true;

        try {
            const url = izmena ? `/api/knjige/${knjigaUIzmeni}` : '/api/knjige';
            const headers = { 'X-CSRF-TOKEN': csrf() };
            // Multipart telo mora da ide POST-om; da je izmena javlja se
            // zaglavljem (ruta prihvata i PATCH i POST).
            if (izmena) headers['X-HTTP-Method-Override'] = 'PATCH';

            const res = await fetch(url, {
                method: 'POST',
                headers,
                credentials: 'include',
                body: formData
            });

            const text = await res.text();

            let data;
            try {
                data = JSON.parse(text);
            } catch (err) {
                console.error('Odgovor nije JSON:', text.substring(0, 500));
                showToast('Greška pri čuvanju: serverski odgovor nije ispravan', 'error');
                return;
            }

            if (data.success) {
                showToast(data.message, 'success');
                window.closeModal();
                (window.KnjigaForma.posleCuvanja || osveziStranicu)(izmena ? 'izmena' : 'dodavanje', data);
            } else {
                // Laravelova validacija (422) vraća `errors`, a ne `message`
                const prvaGreska = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                showToast(prvaGreska || data.message || 'Greška pri čuvanju', 'error');
            }
        } catch (error) {
            showToast('Greška pri čuvanju knjige', 'error');
            console.error('Error:', error);
        } finally {
            if (dugme) dugme.disabled = false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // BRISANJE
    // ═══════════════════════════════════════════════════════════════════

    /* Briše CELU knjigu, zajedno sa svim njenim primercima. Server odbija
       brisanje knjige koja je trenutno zadužena ili rezervisana i vraća
       razlog u `message`. */
    window.obrisiKnjigu = async function (id, naziv) {
        const uvod = naziv
            ? `Da li ste sigurni da želite da obrišete knjigu „${naziv}“?`
            : 'Da li ste sigurni da želite da obrišete ovu knjigu?';

        if (!await confirmDialog({
            type: 'danger',
            title: 'Brisanje knjige',
            message: `${uvod} Biće obrisani i svi njeni primerci.`,
            confirmText: 'Obriši'
        })) return;

        try {
            const res = await fetch(`/api/knjige/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf() },
                credentials: 'include'
            });

            const data = await res.json();

            if (data.success) {
                showToast(data.message || 'Knjiga je obrisana.', 'success');
                (window.KnjigaForma.posleBrisanja || osveziStranicu)(id, data);
            } else {
                showToast(data.message || 'Greška pri brisanju', 'error');
            }
        } catch (error) {
            showToast('Greška pri brisanju knjige', 'error');
            console.error('Error:', error);
        }
    };

    // Stari naziv iz kataloga — zadržan da se ne lome zatečeni pozivi.
    window.deleteBook = window.obrisiKnjigu;

    // ═══════════════════════════════════════════════════════════════════

    document.addEventListener('DOMContentLoaded', () => {
        $('bookForm')?.addEventListener('submit', sacuvaj);
        $('je_eknjiga')?.addEventListener('change', osveziZavisnaPolja);

        // Klik na tamnu podlogu zatvara obrazac, kao i kod ostalih modala.
        $('bookModal')?.addEventListener('click', function (e) {
            if (e.target === this) window.closeModal();
        });
    });
})();
