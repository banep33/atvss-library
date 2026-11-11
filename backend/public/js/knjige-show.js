// ========== GLOBALNE VARIJABLE ==========
let currentRezervacijaId = null;

// ========== PROVERA STATUSA KORISNIKA ==========
/*async function proveriKorisnikovStatus() {
    if (!userId) return;

    try {
        const res = await fetch('/api/moj-status', {
            credentials: 'same-origin'
        });

        if (!res.ok) return;

        const data = await res.json();
        imaAktivnoZaduzenje   = data.ima_aktivno_zaduzenje;
        imaAktivnuRezervaciju = data.ima_aktivnu_rezervaciju;
    } catch (err) {
        console.error('Greška pri proveri statusa:', err);
    }
}*/

// ========== UČITAVANJE PRIMERAKA ==========
async function loadPrimerci() {
    if (isEknjiga) return;

    try {
        const container = document.getElementById('primerci-list');
        const res = await fetch(`/api/knjige/${knjigaId}`);

        if (!res.ok) {
            throw new Error(`HTTP greška! Status: ${res.status}`);
        }

        const data = await res.json();

        const statusBox    = document.getElementById('statusKnjige');
        const rezervisiBtn = document.getElementById('rezervisiBtn');

        if (container) container.innerHTML = '';

        let dostupna    = false;
        let dostupniBroj = 0;
        let ukupnoBroj   = 0;

        if (!data.primerci || data.primerci.length === 0) {
            if (container) container.innerHTML = '<p style="color: #718096;">Nema evidentiranih primeraka za ovu knjigu.</p>';
            if (statusBox) {
                statusBox.textContent = 'Nema primeraka';
                statusBox.className   = 'status-box unavailable';
            }
            if (rezervisiBtn) rezervisiBtn.disabled = true;
            return;
        }

        data.primerci.forEach(p => {
            ukupnoBroj++;
            if (p.status === 'slobodna') {
                dostupna = true;
                dostupniBroj++;
            }

            if (container) {
                const div = document.createElement('div');
                div.className = 'primerak-box';

                let statusText  = '';
                let statusClass = '';

                if (p.status === 'slobodna') {
                    statusText  = 'Dostupno';
                    statusClass = 'status-green';
                } else if (p.status === 'rezervisana') {
                    statusText  = 'Rezervisano';
                    statusClass = 'status-orange';
                } else {
                    statusText  = 'Zaduženo';
                    statusClass = 'status-red';
                }

                div.innerHTML = `
                    <div><strong>Inventarski broj: ${p.inventarski_broj}</strong></div>
                    <div><span class="${statusClass}">${statusText}</span></div>
                `;
                container.appendChild(div);
            }
        });

        if (dostupna) {
           if (statusBox) {
              statusBox.textContent = `Dostupno ${dostupniBroj} od ${ukupnoBroj} primeraka`;
              statusBox.className   = 'status-box available';
            }

            azurirajDugme(true, ukupnoBroj);
            azurirajAdminDugme(true);
        } else {
           if (statusBox) {
             statusBox.textContent = `Svi primerci (${ukupnoBroj}) su trenutno nedostupni`;
             statusBox.className   = 'status-box unavailable';
           }

           azurirajDugme(false, ukupnoBroj);
           azurirajAdminDugme(false);
  }

    } catch (error) {
        console.error('Greška pri učitavanju primeraka:', error);
        const container = document.getElementById('primerci-list');
        if (container) container.innerHTML = '<p style="color: #f56565;">Greška pri učitavanju primeraka.</p>';
    }
}

// Ovo izvuci VAN loadPrimerci() kao zasebnu funkciju:
function azurirajDugme(dostupna, ukupnoBroj) {
    const rezervisiBtn = document.getElementById('rezervisiBtn');
    if (!rezervisiBtn) return;

    // Dugme „Otkaži rezervaciju" — vidljivo i aktivno SAMO kad korisnik ima
    // rezervaciju na čekanju ili odobrenu (tada je Rezerviši disabled). Računa se
    // PRE provere dostupnosti, jer korisnik mora moći da otkaže i kad knjiga nema
    // slobodnih primeraka (njegov primerak je rezervisan/zauzet).
    const otkaziBtn = document.getElementById('otkaziMojuRezervacijuBtn');
    if (otkaziBtn) {
        const mozeOtkazati = (statusOveRezervacije === 'na_cekanju' || statusOveRezervacije === 'odobrena');
        otkaziBtn.style.display = mozeOtkazati ? 'inline-block' : 'none';
        otkaziBtn.disabled = !mozeOtkazati;
    }

    if (!dostupna) {
        rezervisiBtn.disabled    = true;
        rezervisiBtn.title       = '';
        rezervisiBtn.textContent = 'Nema dostupnih primeraka';
        return;
    }

    if (zaduzioOvuKnjigu) {
        rezervisiBtn.disabled    = true;
        rezervisiBtn.title       = 'Već ste zadužili ovu knjigu';
        rezervisiBtn.textContent = 'Imate zaduženu knjigu';
    } else if (statusOveRezervacije === 'odobrena') {
        rezervisiBtn.disabled    = true;
        rezervisiBtn.title       = 'Rezervacija odobrena, preuzmite knjigu u biblioteci';
        rezervisiBtn.textContent = 'Vaša rezervacija je odobrena';
    } else if (statusOveRezervacije === 'na_cekanju') {
        rezervisiBtn.disabled    = true;
        rezervisiBtn.title       = 'Zahtev je poslat, čeka se odluka bibliotekara';
        rezervisiBtn.textContent = 'Vaša rezervacija je na čekanju';
    } else if (imaAktivnoZaduzenje) {
        rezervisiBtn.disabled    = true;
        rezervisiBtn.title       = 'Ne možete rezervisati dok ne vratite trenutno zaduženu knjigu';
        rezervisiBtn.textContent = 'Već imate zaduženu knjigu';
    } else if (imaAktivnuRezervaciju) {
        rezervisiBtn.disabled    = true;
        rezervisiBtn.title       = 'Imate odobrenu rezervaciju za drugu knjigu';
        rezervisiBtn.textContent = 'Vec imate odobrenu rezervaciju';
    } else if (imaNaCekanju) {
        rezervisiBtn.disabled    = true;
        rezervisiBtn.title       = 'Već ste poslali zahtev za drugu knjigu';
        rezervisiBtn.textContent = 'Vec ste poslali zahtev za drugu knjigu';
    } else {
        rezervisiBtn.disabled    = false;
        rezervisiBtn.title       = '';
        rezervisiBtn.textContent = 'Rezerviši';
    }
}

function azurirajAdminDugme(dostupna) {
    const btn = document.getElementById('adminZaduziSebeBtn');
    if (!btn) return;

    if (!dostupna) {
        btn.disabled     = true;
        btn.title        = 'Nema slobodnih primeraka';
        return;
    }

    // Proveri da li admin već ima zaduženu ovu knjigu
    // (koristimo isti API poziv koji već postoji za status knjige)
    fetch(`/api/moj-status-knjige/${knjigaId}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.zaduzio_ovu_knjigu) {
                btn.disabled = true;
                btn.title    = 'Već ste zadužili ovu knjigu';
            } else if (data.ima_aktivno_zaduzenje) {
                btn.disabled = true;
                btn.title    = 'Imate drugu zaduženu knjigu – vratite je pre novog zaduženja';
            } else {
                btn.disabled = false;
                btn.title    = '';
            }
        })
        .catch(() => {
            btn.disabled = false; // u slučaju greške, dozvoli pokušaj
        });
}

async function proveriStatusRezervacije() {
    // Samo ako clan ima aktivnu rezervaciju ili zaduzenje za ovu knjigu
    if (!userId || (!rezervisaoOvuKnjigu && !zaduzioOvuKnjigu)) return;

    try {
        const res = await fetch(`/api/moj-status-knjige/${knjigaId}`, {
            credentials: 'same-origin'
        });

        if (!res.ok) return;

        const data = await res.json();

        // Ažuriraj globalne varijable
        const stariStatus = statusOveRezervacije;
        statusOveRezervacije  = data.status_ove_rezervacije  ?? '';
        mojaRezervacijaId     = data.rezervacija_id          ?? null;
        rezervisaoOvuKnjigu   = data.rezervisao_ovu_knjigu   ?? false;
        zaduzioOvuKnjigu      = data.zaduzio_ovu_knjigu      ?? false;
        imaAktivnoZaduzenje   = data.ima_aktivno_zaduzenje   ?? false;
        imaAktivnuRezervaciju = data.ima_aktivnu_rezervaciju ?? false;
        imaNaCekanju          = data.ima_na_cekanju          ?? false;

        // Ažuriraj dugme samo ako se status promenio
        if (stariStatus !== statusOveRezervacije || 
            zaduzioOvuKnjigu !== data.zaduzio_ovu_knjigu) {
            azurirajDugme(true, 1);
        }

    } catch (err) {
        // Tiho ne radi ništa ako API nije dostupan
    }
}

// ========== REZERVACIJA KNJIGE ==========
document.getElementById('rezervisiBtn')?.addEventListener('click', async () => {
    if (!userId) {
        showToast('Morate biti prijavljeni', 'error');
        return;
    }

    if (!await confirmDialog({ type: 'primary', title: 'Rezervacija', message: 'Da li želite da rezervišete ovu knjigu?', confirmText: 'Rezerviši' })) {
        return;
    }

    try {
        const res = await fetch('/api/rezervacije', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                user_id:   userId,
                knjiga_id: knjigaId
            })
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');
            imaNaCekanju         = true;
            rezervisaoOvuKnjigu  = true;
            statusOveRezervacije = 'na_cekanju';
            mojaRezervacijaId    = data.data?.id ?? mojaRezervacijaId;
            azurirajDugme(true, 1); // odmah „odmrzni" Otkaži, „zamrzni" Rezerviši
            loadPrimerci();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        showToast('Greška pri rezervaciji', 'error');
    }
});

// ========== OTKAZIVANJE SOPSTVENE REZERVACIJE (član) ==========
function openOtkaziMojuRezervacijuModal() {
    if (!mojaRezervacijaId) return;
    document.getElementById('otkaziMojuRezervacijuModal').style.display = 'flex';
}

function closeOtkaziMojuRezervacijuModal() {
    const m = document.getElementById('otkaziMojuRezervacijuModal');
    if (m) m.style.display = 'none';
}

async function confirmOtkaziMojuRezervaciju() {
    if (!mojaRezervacijaId) { closeOtkaziMojuRezervacijuModal(); return; }

    try {
        const res = await fetch(`/api/rezervacije/${mojaRezervacijaId}/otkazi`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            credentials: 'same-origin'
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Rezervacija je otkazana.', 'success');
            statusOveRezervacije  = '';
            rezervisaoOvuKnjigu   = false;
            imaNaCekanju          = false;
            imaAktivnuRezervaciju = false;
            mojaRezervacijaId     = null;
            closeOtkaziMojuRezervacijuModal();
            azurirajDugme(true, 1); // „odmrzni" Rezerviši, sakrij Otkaži
            loadPrimerci();
        } else {
            showToast(data.message || 'Greška pri otkazivanju rezervacije', 'error');
        }
    } catch (e) {
        showToast('Greška pri otkazivanju rezervacije', 'error');
    }
}

document.getElementById('otkaziMojuRezervacijuBtn')?.addEventListener('click', openOtkaziMojuRezervacijuModal);

// ========== E-KNJIGE ==========
function openEknjigeModal() {
    document.getElementById('eknjigeModal').classList.add('active');
    if (userClanKarta) {
        document.getElementById('clanKod').value = userClanKarta;
    }
}

function closeEknjigeModal() {
    document.getElementById('eknjigeModal').classList.remove('active');
}

document.getElementById('eknjigeForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const kod = document.getElementById('clanKod').value.trim();

    try {
        const res = await fetch('/api/eknjige/verify', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: JSON.stringify({
                knjiga_id: knjigaId,
                kod: kod
            })
        });

        const data = await res.json();

        if (data.success) {
            window.open(data.link, '_blank');
            closeEknjigeModal();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        showToast('Greška pri verifikaciji', 'error');
    }
});

// ========== TOAST ==========
/* showToast je globalizovan u notifications.js */

// ========== PRIMERCI FUNKCIJE ==========
function openDodajPrimerakModal() {
    document.getElementById('dodajPrimerakForm').reset();
    document.getElementById('dodajPrimerakModal').style.display = 'flex';
}

function closeDodajPrimerakModal() {
    document.getElementById('dodajPrimerakModal').style.display = 'none';
}

async function submitDodajPrimerak(e) {
    e.preventDefault();

    // Prefiks `primerak_` razdvaja ova polja od istoimenih u modalu za izmenu
    // knjige — vidi komentar uz #dodajPrimerakForm u knjige/show.blade.php.
    const cena = document.getElementById('primerak_cena').value;

    const data = {
        knjiga_id:        knjigaId,
        inventarski_broj: document.getElementById('primerak_inventarski_broj').value,
        signatura:        document.getElementById('primerak_signatura').value || null,
        lokacija:         document.getElementById('primerak_lokacija').value || null,
        nacin_nabavke:    document.getElementById('primerak_nacin_nabavke').value || null,
        cena:             cena ? parseFloat(cena) : null,
        status:           'slobodna'
    };

    try {
        const res = await fetch('/api/primerci', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            credentials: 'include',
            body: JSON.stringify(data)
        });

        if (!res.ok) {
            const error = await res.json();
            showToast(error.message || 'Greška pri dodavanju primerka', 'error');
            return;
        }

        /* `data` je telo ZAHTEVA; poruku sa nazivom/inventarskim brojem
           vraća server, pa se odgovor mora pročitati zasebno. */
        const odgovor = await res.json();
        showToast(odgovor.message || 'Primerak je dodat.', 'success');
        closeDodajPrimerakModal();
        loadPrimerci();
        loadZaduzivanja();
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri dodavanju primerka', 'error');
    }
}

// ========== ZADUŽAVANJE FUNKCIJE ==========
function openZaduzivanjModal() {
    document.getElementById('zaduzivanjForm').reset();
    document.getElementById('zaduzivanjModal').style.display = 'flex';
    loadClanovZaSelect();
    loadPrimerciZaSelect();
    setDefaultRok();
}

function closeZaduzivanjModal() {
    document.getElementById('zaduzivanjModal').style.display = 'none';
}

async function loadClanovZaSelect() {
    try {
        const res = await fetch('/api/korisnici?tip=clan', {
            credentials: 'include'
        });

        if (!res.ok) return;

        const data   = await res.json();
        const select = document.getElementById('clan_id');

        if (data.korisnici && Array.isArray(data.korisnici)) {
            select.innerHTML = '<option value="">-- Odaberite člana --</option>';
            data.korisnici.forEach(clan => {
                const opt       = document.createElement('option');
                opt.value       = clan.id;
                opt.textContent = `${clan.ime} ${clan.prezime} (${clan.broj_clanske_karte || 'bez karte'})`;
                select.appendChild(opt);
            });
        }
    } catch (error) {
        console.error('Greška pri učitavanju članova:', error);
    }
}

async function loadPrimerciZaSelect() {
    try {
        const res = await fetch(`/api/primerci?knjiga_id=${knjigaId}`, {
            credentials: 'include'
        });

        if (!res.ok) {
            showToast('Greška pri učitavanju primeraka', 'error');
            return;
        }

        const data   = await res.json();
        const select = document.getElementById('zaduz_primerak_id');

        if (data.primerci && Array.isArray(data.primerci)) {
            const dostupni = data.primerci.filter(p => p.status === 'slobodna');

            select.innerHTML = '<option value="">-- Odaberite dostupan primerak --</option>';

            if (dostupni.length === 0) {
                select.innerHTML += '<option disabled>Nema slobodnih primeraka</option>';
            } else {
                dostupni.forEach(p => {
                    const opt       = document.createElement('option');
                    opt.value       = p.id;
                    opt.textContent = `Inv. br: ${p.inventarski_broj} (${p.signatura || 'bez signature'})`;
                    select.appendChild(opt);
                });
            }
        }
    } catch (error) {
        console.error('Greška pri učitavanju primeraka:', error);
        showToast('Greška pri učitavanju primeraka', 'error');
    }
}

function setDefaultRok() {
    const input    = document.getElementById('rok_vracanja');
    const today    = new Date();
    const twoWeeks = new Date(today.getTime() + 14 * 24 * 60 * 60 * 1000);
    const year     = twoWeeks.getFullYear();
    const month    = String(twoWeeks.getMonth() + 1).padStart(2, '0');
    const day      = String(twoWeeks.getDate()).padStart(2, '0');
    input.value    = `${year}-${month}-${day}`;
}

async function submitZaduzivanj(e) {
    e.preventDefault();

    const clanId      = parseInt(document.getElementById('clan_id').value);
    const primerakId  = parseInt(document.getElementById('zaduz_primerak_id').value);
    const rokVracanja = document.getElementById('rok_vracanja').value;

    if (!clanId || isNaN(clanId))       { showToast('Molimo odaberite člana', 'error');    return; }
    if (!primerakId || isNaN(primerakId)) { showToast('Molimo odaberite primerak', 'error'); return; }
    if (!rokVracanja)                    { showToast('Molimo unesite rok vraćanja', 'error'); return; }

    try {
        const res = await fetch('/api/zaduzivanja', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'include',
            body: JSON.stringify({ clan_id: clanId, primerak_id: primerakId, rok_vracanja: rokVracanja })
        });

        if (!res.ok) {
            const error = await res.json();
            showToast(error.message || 'Greška pri zadužavanju', 'error');
            return;
        }

        showToast('Zadužavanje uspešno kreirano!', 'success');
        closeZaduzivanjModal();
        loadPrimerci();
        loadZaduzivanja();
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri zadužavanju', 'error');
    }
}

async function loadZaduzivanja() {
    if (!isAdminOrBibliotekar) return;

    try {
        const res = await fetch(`/api/zaduzivanja?knjiga_id=${knjigaId}`, {
            credentials: 'include'
        });

        if (!res.ok) return;

        const data      = await res.json();
        const container = document.getElementById('zaduzivanja-list');

        if (!data.zaduzivanja || data.zaduzivanja.length === 0) {
            container.innerHTML = '<p class="lista-prazna">Nema aktivnih zaduživanja za ovu knjigu.</p>';
            return;
        }

        container.innerHTML = '';
        data.zaduzivanja.forEach(z => {
            const div        = document.createElement('div');
            div.className    = 'zaduzivanje-card';

            const rokPassed  = new Date(z.rok_vracanja) < new Date();
            const statusColor = rokPassed ? '#f56565' : '#48bb78';

            div.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <strong>${z.clan?.ime || 'Nepoznato'} ${z.clan?.prezime || ''}</strong><br>
                        <small>Primerak: ${z.primerak?.inventarski_broj || 'N/A'}</small><br>
                        <small>Rok vraćanja: <span style="color: ${statusColor};">${new Date(z.rok_vracanja).toLocaleDateString('sr-RS')}</span></small>
                    </div>
                    <button class="action-btn" onclick="returnPrimerak(${z.id})" style="background-color: #ff9800; padding: 6px 10px; font-size: 12px;">
                        <i class="fas fa-rotate-left"></i> Vrati
                    </button>
                </div>
            `;
            container.appendChild(div);
        });
    } catch (error) {
        console.error('Greška pri učitavanju zadužavanja:', error);
    }
}

async function returnPrimerak(zaduzivanjId) {
    if (!await confirmDialog({ type: 'primary', title: 'Vraćanje primerka', message: 'Da li ste sigurni da želite da vratite ovaj primerak?', confirmText: 'Vrati' })) return;

    try {
        const res = await fetch(`/api/zaduzivanja/${zaduzivanjId}/vrati`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            credentials: 'include',
            body: JSON.stringify({})
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Primerak je vraćen.', 'success');
            loadPrimerci();
            loadZaduzivanja();
        } else {
            showToast(data.message || 'Greška pri vraćanju primerka', 'error');
        }
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri vraćanju primerka', 'error');
    }
}

// ========== REZERVACIJE FUNKCIJE ==========
function openOtkaziRezervacijuModal(rezervacijaId, korisnikIme) {
    currentRezervacijaId = rezervacijaId;
    document.getElementById('otkaziRezerRazlog').textContent = `Otkaži rezervaciju za ${korisnikIme}?`;
    document.getElementById('otkaziRezervacijuModal').style.display = 'flex';
}

function closeOtkaziRezervacijuModal() {
    document.getElementById('otkaziRezervacijuModal').style.display = 'none';
}

async function confirmOtkaziRezervaciju() {
    if (!currentRezervacijaId) return;

    try {
        const res = await fetch(`/api/rezervacije/${currentRezervacijaId}/odbij`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            credentials: 'include',
            body: JSON.stringify({})
        });

        if (!res.ok) {
            const error = await res.json();
            showToast(error.message || 'Greška pri otkazivanju rezervacije', 'error');
            return;
        }

        showToast('Rezervacija je otkazana!', 'success');
        closeOtkaziRezervacijuModal();
        loadRezervacije();
        loadPrimerci();
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri otkazivanju rezervacije', 'error');
    }
}

async function loadRezervacije() {
    if (!isAdminOrBibliotekar) return;

    try {
        const res = await fetch(`/api/rezervacije?knjiga_id=${knjigaId}`, {
            credentials: 'include'
        });

        if (!res.ok) return;

        const data      = await res.json();
        const container = document.getElementById('rezervacije-list');

        if (!data.rezervacije || data.rezervacije.length === 0) {
            container.innerHTML = '<p class="lista-prazna">Nema aktivnih rezervacija za ovu knjigu.</p>';
            return;
        }

        container.innerHTML = '';
        data.rezervacije.forEach(r => {
            const div        = document.createElement('div');
            div.className    = 'rezervacija-card';

            const statusBadge = r.status === 'odobrena'
                ? '<span style="background: #48bb78; color: white; padding: 2px 6px; border-radius: 3px; font-size: 12px;">Odobrena</span>'
                : '<span style="background: #ed8936; color: white; padding: 2px 6px; border-radius: 3px; font-size: 12px;">Na čekanju</span>';

            div.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <strong>${r.user?.ime || 'Nepoznato'} ${r.user?.prezime || ''}</strong><br>
                        <small>Broj karte: ${r.user?.broj_clanske_karte || 'N/A'}</small><br>
                        <small>Status: ${statusBadge}</small><br>
                        <small>Rezervisano: ${new Date(r.datum_rezervacije).toLocaleDateString('sr-RS')}</small>
                    </div>
                    <button class="action-btn" onclick="openOtkaziRezervacijuModal(${r.id}, '${r.user?.ime || ''} ${r.user?.prezime || ''}')" style="background-color: #ed8936; padding: 6px 10px; font-size: 12px;">
                        <i class="fas fa-xmark"></i> Otkaži
                    </button>
                </div>
            `;
            container.appendChild(div);
        });
    } catch (error) {
        console.error('Greška pri učitavanju rezervacija:', error);
    }
}

/* ========== OBRAZAC ZA KNJIGU (knjiga-forma.js) ==========

   Dugmad „Izmeni" i „Obriši" iznad ove stranice zovu openEditModal() i
   obrisiKnjigu() iz zajedničkog modula; ovde se kaže samo šta posle toga.

   Posle IZMENE stranica se ponovo učitava, jer je iscrtana na serveru —
   naslov, slika, žanrovi i podaci o izdanju su u Blade-u, ne u JS-u.
   Posle BRISANJA knjige više nema, pa se ide na katalog. */
window.KnjigaForma = window.KnjigaForma || {};

window.KnjigaForma.posleCuvanja = function () {
    setTimeout(() => location.reload(), 800);
};

window.KnjigaForma.posleBrisanja = function () {
    setTimeout(() => { window.location.href = '/'; }, 800);
};

// ========== INICIJALIZACIJA ==========
window.addEventListener('load', async () => {
   // await proveriKorisnikovStatus(); // ✅ prvo proveri status korisnika
    loadPrimerci();                  // pa tek onda učitaj primerke

    if (isAdminOrBibliotekar) {
        loadZaduzivanja();
        loadRezervacije();
    }

    // Event listeneri za modale
    const dodajPrimerakForm = document.getElementById('dodajPrimerakForm');
    if (dodajPrimerakForm) dodajPrimerakForm.addEventListener('submit', submitDodajPrimerak);

    const zaduzivanjForm = document.getElementById('zaduzivanjForm');
    if (zaduzivanjForm) zaduzivanjForm.addEventListener('submit', submitZaduzivanj);

    document.getElementById('dodajPrimerakModal').addEventListener('click', function(e) {
        if (e.target === this) closeDodajPrimerakModal();
    });

    document.getElementById('zaduzivanjModal').addEventListener('click', function(e) {
        if (e.target === this) closeZaduzivanjModal();
    });

    document.getElementById('otkaziRezervacijuModal').addEventListener('click', function(e) {
        if (e.target === this) closeOtkaziRezervacijuModal();
    });

    // Polling samo za clanova koji imaju aktivnu rezervaciju/zaduzenje
    if (userId && (rezervisaoOvuKnjigu || zaduzioOvuKnjigu || imaNaCekanju || imaAktivnuRezervaciju)) {
    setInterval(proveriStatusRezervacije, 15000); // svakih 15 sekundi
    }
});

// ========== ADMIN: ZADUŽI ZA SEBE ==========

function adminZaduziSebe() {
    ucitajPrimerkeZaAdminSebe();
    postaviDefaultRokZaAdmin();
    document.getElementById('adminZaduziSebeModal').classList.add('active');
}

function closeAdminZaduziSebeModal() {
    document.getElementById('adminZaduziSebeModal').classList.remove('active');
}

async function ucitajPrimerkeZaAdminSebe() {
    const sel = document.getElementById('adminSebePrimerakId');
    sel.innerHTML = '<option value="">Učitavanje...</option>';

    try {
        const res  = await fetch(`/api/primerci?knjiga_id=${knjigaId}`, { credentials: 'include' });
        const data = await res.json();

        const slobodni = (data.primerci || []).filter(p => p.status === 'slobodna');
        sel.innerHTML  = '<option value="">-- Izaberi primerak --</option>';

        if (slobodni.length === 0) {
            sel.innerHTML = '<option value="" disabled>Nema slobodnih primeraka</option>';
            return;
        }

        slobodni.forEach(p => {
            const opt       = document.createElement('option');
            opt.value       = p.id;
            opt.textContent = `Inv. br: ${p.inventarski_broj}${p.signatura ? ' (' + p.signatura + ')' : ''}`;
            sel.appendChild(opt);
        });
    } catch (e) {
        sel.innerHTML = '<option value="" disabled>Greška pri učitavanju</option>';
    }
}

function postaviDefaultRokZaAdmin() {
    const input    = document.getElementById('adminSebeRokVracanja');
    const danas    = new Date();
    const dvaNed   = new Date(danas.getTime() + 14 * 24 * 60 * 60 * 1000);
    input.value    = dvaNed.toISOString().split('T')[0];
    input.min      = new Date(danas.getTime() + 24 * 60 * 60 * 1000).toISOString().split('T')[0];
}

async function submitAdminZaduziSebe(e) {
    e.preventDefault();

    const primerakId  = document.getElementById('adminSebePrimerakId').value;
    const rokVracanja = document.getElementById('adminSebeRokVracanja').value;

    if (!primerakId)  { showToast('Izaberite primerak', 'error');      return; }
    if (!rokVracanja) { showToast('Unesite rok vraćanja', 'error');    return; }

    try {
        const res = await fetch('/api/zaduzivanja', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'include',
            body: JSON.stringify({
                clan_id:      userId,      // admin zaduži sebe
                primerak_id:  primerakId,
                rok_vracanja: rokVracanja
            })
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Knjiga je zadužena.', 'success');
            closeAdminZaduziSebeModal();
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.message || 'Greška pri zaduženju', 'error');
        }
    } catch (err) {
        showToast('Greška pri zaduženju', 'error');
    }
}
// ========== MODAL: SVE KNJIGE IZ ŽANRA ==========
// Otvara se klikom na žanr u detaljima knjige. Naziv žanra se čita iz samog
// dugmeta (a ne iz odgovora servera) da bi u naslovu modala bio ispisan
// pismom koje je korisnik trenutno izabrao.

// Učitane knjige žanra se pamte da bi pretraga filtrirala lokalno, bez
// novog zahteva ka serveru pri svakom otkucanom slovu.
let zanrKnjigeUcitane = [];

function zanrModalPoruka(tekst) {
    const p = document.createElement('p');
    p.className = 'zanr-modal-info';
    p.textContent = tekst;
    return p;
}

async function openZanrModal(zanrId, dugme) {
    const modal   = document.getElementById('zanrModal');
    const naslov  = document.getElementById('zanrModalNaslov');
    const sadrzaj = document.getElementById('zanrModalSadrzaj');
    if (!modal) return;

    const nazivZanra = dugme ? dugme.textContent.trim() : '';
    naslov.textContent = nazivZanra ? 'Knjige iz žanra: ' + nazivZanra : 'Knjige iz žanra';

    // Novi žanr kreće od prazne pretrage, da ne ostane filter prethodnog.
    const polje = document.getElementById('zanrPretraga');
    if (polje) polje.value = '';
    zanrKnjigeUcitane = [];

    sadrzaj.replaceChildren(zanrModalPoruka('Učitavanje...'));
    modal.classList.add('active');

    try {
        const res = await fetch(`/api/zanrovi/${zanrId}/knjige`);
        if (!res.ok) throw new Error(`HTTP greška! Status: ${res.status}`);

        const data = await res.json();
        zanrKnjigeUcitane = data.knjige || [];
        prikaziZanrKnjige(zanrKnjigeUcitane);
    } catch (err) {
        console.error('Greška pri učitavanju knjiga žanra:', err);
        sadrzaj.replaceChildren(zanrModalPoruka('Greška pri učitavanju knjiga.'));
    }
}

function prikaziZanrKnjige(knjige) {
    const sadrzaj = document.getElementById('zanrModalSadrzaj');

    if (!knjige.length) {
        sadrzaj.replaceChildren(zanrModalPoruka('Nema knjiga u ovom žanru.'));
        return;
    }

    // Redovi se grade preko textContent (ne innerHTML) — naslovi i imena
    // autora dolaze iz baze i ne smeju da se tumače kao HTML.
    const redovi = knjige.map(k => {
        const red = document.createElement('a');
        red.className = 'zanr-knjiga';
        red.href = `/knjige/${k.id}`;

        const naslov = document.createElement('span');
        naslov.className = 'zanr-knjiga__naslov';
        naslov.textContent = k.naziv_knjige || '';

        const autor = document.createElement('span');
        autor.className = 'zanr-knjiga__autor';
        autor.textContent = k.autor || '';

        red.append(naslov, autor);
        return red;
    });

    sadrzaj.replaceChildren(...redovi);
}

/* Normalizacija za pretragu: foldSearch iz pismo.js izjednačava pismo i
   dijakritike (ćirilica -> latinica, „ž" -> „z"), pa „zena" nalazi „žena",
   a ćirilični unos nalazi latinični zapis u bazi. Ako skripta iz nekog
   razloga nije stigla, pada na obično malo slovo. */
function normalizujZaPretragu(tekst) {
    return typeof window.foldSearch === 'function'
        ? window.foldSearch(tekst)
        : (tekst || '').toLowerCase();
}

function filtrirajZanrKnjige() {
    const polje   = document.getElementById('zanrPretraga');
    const sadrzaj = document.getElementById('zanrModalSadrzaj');
    if (!sadrzaj) return;

    const upit = normalizujZaPretragu(polje ? polje.value : '');

    if (!upit) {
        prikaziZanrKnjige(zanrKnjigeUcitane);
        return;
    }

    const nadjene = zanrKnjigeUcitane.filter(k =>
        normalizujZaPretragu(k.naziv_knjige || '').includes(upit) ||
        normalizujZaPretragu(k.autor || '').includes(upit)
    );

    if (!nadjene.length) {
        sadrzaj.replaceChildren(zanrModalPoruka('Nema knjiga koje odgovaraju pretrazi.'));
        return;
    }

    prikaziZanrKnjige(nadjene);
}

function closeZanrModal() {
    document.getElementById('zanrModal')?.classList.remove('active');
}

// Zatvaranje klikom van modala i tasterom Esc — kao kod ostalih modala
document.getElementById('zanrModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeZanrModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeZanrModal();
});
