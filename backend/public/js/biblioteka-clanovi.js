function openAddModal() {
        document.getElementById('addModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('addModal').classList.remove('active');
        document.getElementById('clanForm').reset();
    }

    document.getElementById('clanForm').addEventListener('submit', async (e) => {
        e.preventDefault();

        /* Obavezna polja i najmanja dužina lozinke — poruka ide ispod polja
           (notifications.js), umesto engleskog „balončića" pregledača. */
        if (typeof proveriObavezna === 'function' && !proveriObavezna(e.currentTarget, { dijalog: false })) {
            return;
        }

        const data = {
            ime: document.getElementById('ime').value,
            prezime: document.getElementById('prezime').value,
            email: document.getElementById('email').value,
            password: document.getElementById('password').value,
            telefon: document.getElementById('telefon').value || null,
            adresa: document.getElementById('adresa').value || null
        };

        try {
            const res = await fetch('/api/clanovi', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify(data)
            });

            const result = await res.json();

            if (result.success) {
                showToast(result.message || 'Član je dodat.', 'success');
                closeModal();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.message || 'Greška pri dodavanju', 'error');
            }
        } catch (error) {
            showToast('Greška pri dodavanju člana', 'error');
        }
    });

    async function deleteClan(id) {
        if (!await confirmDialog({ type: 'danger', title: 'Brisanje člana', message: 'Da li ste sigurni da želite da obrišete ovog člana?', confirmText: 'Obriši' })) {
            return;
        }

        try {
            const res = await fetch(`/api/clanovi/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await res.json();

            if (data.success) {
                showToast(data.message || 'Član je obrisan.', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.message || 'Greška pri brisanju', 'error');
            }
        } catch (error) {
            showToast('Greška pri brisanju člana', 'error');
        }
    }

/* showToast je globalizovan u notifications.js */

/* potvrdiOdjavu je globalizovan u notifications.js */

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchClanovi');
    const resetBtn = document.getElementById('resetClanovi');

    searchInput?.addEventListener('input', filterClanovi);
    resetBtn?.addEventListener('click', function() {
        searchInput.value = '';
        filterClanovi();
    });
});

function filterClanovi() {
    const fold = window.foldSearch || (s => (s || '').toLowerCase());
    const search = fold(document.getElementById('searchClanovi')?.value || '');
    const rows = document.querySelectorAll('.data-table tbody tr[data-ime]');
    let vidljivi = 0;

    rows.forEach(row => {
        const ime = fold(row.getAttribute('data-ime') || '');
        const kartica = fold(row.getAttribute('data-kartica') || '');

        const match = !search ||
            ime.includes(search) ||
            kartica.includes(search);

        row.style.display = match ? '' : 'none';
        if (match) vidljivi++;
    });

    // Prikaži poruku ako nema rezultata
    const emptyMsg = document.getElementById('empty-search-msg');
    if (vidljivi === 0 && search) {
        if (!emptyMsg) {
            const tbody = document.querySelector('.data-table tbody');
            const tr = document.createElement('tr');
            tr.id = 'empty-search-msg';
            tr.innerHTML = `<td colspan="6" style="text-align: center; padding: 40px; color: #a0aec0;">
                <i class="fa-solid fa-magnifying-glass prazna-ikona"></i>
                Nema članova koji odgovaraju pretrazi.
            </td>`;
            tbody.appendChild(tr);
        }
    } else {
        emptyMsg?.remove();
    }

    /* Paginacija ne prati inline stil pojedinačnih redova, pa joj se
       preslaganje traži izričito — inače bi i posle pretrage brojala sve
       redove i ostavljala prazne stranice. */
    window.CoworkPagination?.refresh();
}

function openEditClanModal(id, ime, prezime, email, telefon) {
    document.getElementById('editClanId').value = id;
    document.getElementById('editIme').value = ime;
    document.getElementById('editPrezime').value = prezime;
    document.getElementById('editEmail').value = email;
    document.getElementById('editPassword').value = '';
    document.getElementById('editTelefon').value = telefon;
    document.getElementById('editClanModal').classList.add('active');
}

function closeEditClanModal() {
    document.getElementById('editClanModal').classList.remove('active');
}

document.getElementById('editClanForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    /* Polje „Nova lozinka" sme da ostane prazno (lozinka se tada ne menja),
       ali ako je popunjeno mora da ispuni minimum — to hvata proveriObavezna
       preko `minlength`. */
    if (typeof proveriObavezna === 'function' && !proveriObavezna(e.currentTarget, { dijalog: false })) {
        return;
    }

    const id = document.getElementById('editClanId').value;

    try {
        const res = await fetch(`/api/clanovi/${id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                ime: document.getElementById('editIme').value,
                prezime: document.getElementById('editPrezime').value,
                email: document.getElementById('editEmail').value,
                password: document.getElementById('editPassword').value,
                telefon: document.getElementById('editTelefon').value,
            })
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Podaci člana su sačuvani.', 'success');
            closeEditClanModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message || 'Greška pri ažuriranju', 'error');
        }
    } catch (error) {
        showToast('Greška pri ažuriranju člana', 'error');
    }
});

// Zatvaranje klikom van modala
document.getElementById('editClanModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeEditClanModal();
});