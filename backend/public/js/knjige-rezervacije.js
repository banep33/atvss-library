function showTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    document.getElementById('tab_' + tabName).classList.add('active');
    event.target.closest('.tab-btn').classList.add('active');

    premestiKontrole();
}

/**
 * Premešta blok sa pretragom i sortiranjem u karticu OTVORENOG taba, iznad
 * naslova sekcije.
 *
 * Blok je namerno jedan jedini (a ne kopija po tabu): polja imaju id-jeve
 * (`searchClan`, `sortRezervacije`…) koje čita applyFilters(), pa bi kopije
 * značile duplirane id-jeve i unos koji se gubi pri promeni taba. Ovako
 * uneta pretraga ostaje ista i posle prelaska na drugi tab.
 *
 * Server ga ispisuje u kartici prvog taba („Na čekanju"), koji je i
 * podrazumevano otvoren — pri običnom otvaranju stranice ovde nema šta da se
 * pomera, pa nema ni treptaja. Premeštanje radi tek pri promeni taba.
 *
 * Tabovi „Kašnjenja", „Arhiva" i „Održavanje" nemaju `.controls-slot` — tamo
 * se blok samo sakrije.
 */
function premestiKontrole() {
    const kontrole = document.getElementById('rezervacijeKontrole');
    if (!kontrole) return;

    const prihvatnik = document.querySelector('.tab-content.active .controls-slot');

    if (!prihvatnik) {
        kontrole.style.display = 'none';
        return;
    }

    kontrole.style.display = '';
    if (kontrole.parentElement !== prihvatnik) prihvatnik.appendChild(kontrole);
}

document.addEventListener('DOMContentLoaded', premestiKontrole);

async function odobriRezervaciju(id) {
    if (!await confirmDialog({ type: 'success', title: 'Odobri rezervaciju', message: 'Da li ste sigurni da želite da odobrite ovu rezervaciju?', confirmText: 'Odobri' })) {
        return;
    }

    try {
        const res = await fetch(`/api/rezervacije/${id}/odobri`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        });

        const data = await res.json();

        if (data.success) {
            // Poruka sa servera nosi naziv knjige i ime člana.
            showToast(data.message || 'Rezervacija je odobrena.', 'success');
            
            // Osveži stranicu nakon 1 sekunde i otvori Odbijene tab
            setTimeout(() => {
                window.location.href = window.location.pathname + '#odbijene';
                window.location.reload();
            }, 1000);
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri odobravanju', 'error');
    }
}

async function odbijRezervaciju(id) {
    if (!await confirmDialog({ type: 'danger', title: 'Odbij rezervaciju', message: 'Da li ste sigurni da želite da odbijete ovu rezervaciju?', confirmText: 'Odbij' })) {
        return;
    }

    try {
        const res = await fetch(`/api/rezervacije/${id}/odbij`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Rezervacija je odbijena.', 'success');
            
            // Osveži stranicu nakon 1 sekunde i otvori Odbijene tab
            setTimeout(() => {
                window.location.href = window.location.pathname + '#odbijene';
                window.location.reload();
            }, 1000);
        } else {
            showToast('Greška pri odbijanju', 'error');
        }
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri odbijanju', 'error');
    }
}

async function zaduziKnjigu(id, danaBroj = 14) {
    danaBroj = parseInt(danaBroj);
    
    if (danaBroj < 1 || danaBroj > 90) {
        showToast('Broj dana mora biti između 1 i 90!', 'error');
        return;
    }
    
    if (!await confirmDialog({ type: 'primary', title: 'Zaduži knjigu', message: `Da li je član preuzeo knjigu? Rok vraćanja: ${danaBroj} dana.`, confirmText: 'Zaduži' })) {
        return;
    }

    try {
        const res = await fetch(`/api/rezervacije/${id}/zaduzi`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                dana_zaduzenja: danaBroj
            })
        });

        const data = await res.json();

        if (data.success) {
            showToast(`Knjiga uspešno zadužena na ${danaBroj} dana!`, 'success');
            
            // Osveži stranicu nakon 1 sekunde i otvori Zadužene tab
            setTimeout(() => {
                window.location.href = window.location.pathname + '#preuzete';
                window.location.reload();
            }, 1000);
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri zaduženju', 'error');
    }
}

async function vratiKnjigu(id) {
    if (!await confirmDialog({ type: 'primary', title: 'Vraćanje knjige', message: 'Da li je član vratio ovu knjigu u biblioteku?', confirmText: 'Potvrdi' })) {
        return;
    }

    try {
        const res = await fetch(`/api/rezervacije/${id}/vrati`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Knjiga je vraćena.', 'success');
            
            // Osveži stranicu nakon 1 sekunde - prikaži Na čekanju tab (može biti nova rezervacija)
            setTimeout(() => {
                window.location.href = window.location.pathname + '#na_cekanju';
                window.location.reload();
            }, 1000);
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri vraćanju knjige', 'error');
    }
}

// Redosled tabova u admin/rezervacije.blade.php: 0 Na čekanju, 1 Odobrene,
// 2 Odbijene… Brojač iznad tabele mora da se umanji na TOM tabu iz kog je red
// obrisan (odbijena rezervacija ili istekla iz tabele odobrenih).
const REZERVACIJA_TAB_INDEX = { odobrena: 1, odbijena: 2 };

async function obrisiRezervaciju(id, tip) {
    if (!await confirmDialog({ type: 'danger', title: 'Brisanje rezervacije', message: 'Da li želite da obrišete ovu rezervaciju?', confirmText: 'Obriši' })) {
        return;
    }

    try {
        const res = await fetch(`/api/rezervacije/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Rezervacija je obrisana.', 'success');
            
            // Ukloni red
            const row = document.getElementById(`${tip}-${id}`);
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 300);
            }
            
            // Ažuriraj brojač
            setTimeout(() => {
                const tabIndex = REZERVACIJA_TAB_INDEX[tip];
                if (tabIndex === undefined) return;
                const tab   = document.querySelectorAll('.tab-btn')[tabIndex];
                const badge = tab ? tab.querySelector('.tab-count') : null;
                if (badge) {
                    const count = parseInt(badge.textContent) - 1;
                    badge.textContent = count;
                }
            }, 400);
        } else {
            showToast('Greška pri brisanju', 'error');
        }
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri brisanju', 'error');
    }
}

// Vraćanje zaduženja (za tab Kašnjenja)
async function vratiZaduzenje(zaduzivanje_id) {
    if (!await confirmDialog({ type: 'primary', title: 'Vraćanje knjige', message: 'Da li je član vratio ovu knjigu?', confirmText: 'Potvrdi' })) {
        return;
    }

    try {
        const res = await fetch(`/api/zaduzivanja/${zaduzivanje_id}/vrati`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Knjiga je vraćena.', 'success');
            
            // Osveži stranicu nakon 1 sekunde i otvori Kašnjenja tab
            setTimeout(() => {
                window.location.href = window.location.pathname + '#kasnjenja';
                window.location.reload();
            }, 1000);
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Greška:', error);
        showToast('Greška pri vraćanju knjige', 'error');
    }
}

// Brisanje svih knjiga
async function deleteAllKnjige() {
    const resultDiv = document.getElementById('delete-all-knjige-result');

    if (!await confirmDialog({ type: 'danger', title: 'OPASNO — brisanje svega', message: 'Ovo briše SVE knjige, primerke, rezervacije i zaduženja!\n\nDa li si siguran?', confirmText: 'Da, obriši' })) return;
    if (!await confirmDialog({ type: 'danger', title: 'Druga potvrda', message: 'Ovo je NEPOVRATNO!\n\nNastaviti?', confirmText: 'Nastavi' })) return;

    const finalConfirm = prompt('Upiši "OBRIŠI SVE" da potvrdiš:');
    if (finalConfirm !== 'OBRIŠI SVE') {
        resultDiv.innerHTML = '<p class="maintenance-tok">Operacija otkazana.</p>';
        return;
    }

    resultDiv.innerHTML = '<p class="maintenance-tok"><i class="fa-solid fa-spinner fa-spin"></i> Brisanje u toku...</p>';

    try {
        const response = await fetch('/api/admin/delete-all-knjige', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (data.success) {
            resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--uspeh">
                <strong><i class="fa-solid fa-check-circle"></i> ${data.message}</strong>
                <br><em>Stranica će se osvežiti za 3 sekunde...</em>
            </div>`;
            setTimeout(() => location.reload(), 3000);
        } else {
            resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--greska">
                <strong>Greška:</strong> ${data.message}
            </div>`;
        }
    } catch (error) {
        resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--greska">
            <strong>Greška:</strong> ${error.message}
        </div>`;
    }
}

function prikaziPreostaloVreme(el) {
    const rok = new Date(el.getAttribute('data-rok'));
    const sad = new Date();
    const razlikaMs = rok - sad;

    if (razlikaMs <= 0) {
        const prosloMs = Math.abs(razlikaMs);
        const sati = Math.floor(prosloMs / (1000 * 60 * 60));
        const minuti = Math.floor((prosloMs % (1000 * 60 * 60)) / (1000 * 60));
        el.setAttribute('title', `Kasni: ${sati} sati i ${minuti} minuta`);
    } else {
        const sati = Math.floor(razlikaMs / (1000 * 60 * 60));
        const minuti = Math.floor((razlikaMs % (1000 * 60 * 60)) / (1000 * 60));
        el.setAttribute('title', `Do isteka roka ostalo je još ${sati} sati i ${minuti} minuta`);
    }
}

/* showToast je globalizovan u notifications.js */

// Automatski otvori odgovarajući tab na osnovu hash parametra u URL-u
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.substring(1); // Ukloni '#' iz hash-a
    if (hash && ['na_cekanju', 'odobrene', 'odbijene', 'preuzete', 'kasnjenja', 'odrzavanje'].includes(hash)) {
        // Pronaći dugme za tab
        const tabButtons = document.querySelectorAll('.tab-btn');
        tabButtons.forEach((btn, index) => {
            if (btn.getAttribute('onclick').includes(hash)) {
                // Ukloni active klasu sa svih tab-ova
                document.querySelectorAll('.tab-content').forEach(tab => {
                    tab.classList.remove('active');
                });
                document.querySelectorAll('.tab-btn').forEach(b => {
                    b.classList.remove('active');
                });
                
                // Dodaj active klasu na odabrani tab
                btn.classList.add('active');
                document.getElementById('tab_' + hash).classList.add('active');

                /* Ovaj slušalac se izvršava POSLE onog koji premešta kontrole
                   (registrovan je niže u fajlu), pa bi pretraga inače ostala u
                   kartici prvog taba — a on je sada zatvoren. */
                premestiKontrole();
            }
        });
    }
});