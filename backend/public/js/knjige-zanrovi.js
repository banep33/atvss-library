function openAddModal() {
        document.getElementById('addModal').classList.add('active');
    }

    function closeModal() {
        document.getElementById('addModal').classList.remove('active');
        document.getElementById('zanrForm').reset();
    }

    document.getElementById('zanrForm').addEventListener('submit', async (e) => {
        e.preventDefault();

        const data = {
            naziv: document.getElementById('naziv').value
        };

        try {
            const res = await fetch('/api/zanrovi', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify(data)
            });

            const result = await res.json();

            if (result.success) {
                // Server u poruci vrati i naziv žanra („Žanr X je dodat.")
                showToast(result.message || 'Žanr je dodat.', 'success');
                closeModal();
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.message || 'Greška pri dodavanju', 'error');
            }
        } catch (error) {
            showToast('Greška pri dodavanju žanra', 'error');
        }
    });

    async function deleteZanr(id, naziv) {
        if (!await confirmDialog({ type: 'danger', title: 'Brisanje žanra', message: `Da li ste sigurni da želite da obrišete žanr "${naziv}"?`, confirmText: 'Obriši' })) {
            return;
        }

        try {
            const res = await fetch(`/api/zanrovi/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });

            const data = await res.json();

            if (data.success) {
                showToast(data.message || `Žanr "${naziv}" je obrisan.`, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.message || 'Greška pri brisanju', 'error');
            }
        } catch (error) {
            showToast('Greška pri brisanju žanra', 'error');
        }
    }

/* showToast je globalizovan u notifications.js */

/* potvrdiOdjavu je globalizovan u notifications.js */

/* Pretraga radi nad redovima tabele i skriva ih INLINE stilom
   (display:none). Tako je vidi pagination.js — on prati promenu style
   atributa, izbacuje filtrirane redove iz brojanja i sam se prerenderuje.
   Red sa porukom „nema rezultata" ima td[colspan], pa ga paginacija
   namerno preskače. */
function filterZanrovi() {
    const fold = window.foldSearch || (s => (s || '').toLowerCase().trim());
    const query = fold(document.getElementById('searchZanr')?.value || '');
    const redovi = document.querySelectorAll('.data-table tbody tr[data-naziv]');
    let vidljivo = 0;

    redovi.forEach(red => {
        const naziv = fold(red.getAttribute('data-naziv') || '');
        const pogodak = !query || naziv.includes(query);

        red.style.display = pogodak ? '' : 'none';
        if (pogodak) vidljivo++;
    });

    const poruka = document.getElementById('empty-search-msg');
    if (vidljivo === 0 && query) {
        if (!poruka) {
            const tbody = document.querySelector('.data-table tbody');
            const tr = document.createElement('tr');
            tr.id = 'empty-search-msg';
            tr.innerHTML = `<td colspan="3" style="text-align: center; padding: 40px; color: #a0aec0;">
                <i class="fa-solid fa-magnifying-glass prazna-ikona"></i>
                Nema žanrova koji odgovaraju pretrazi.
            </td>`;
            tbody.appendChild(tr);
        }
    } else {
        poruka?.remove();
    }
}