async function oznaciProcitano(id) {
        try {
            const res = await fetch(`/api/notifikacije/${id}/procitano`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });
            const data = await res.json();

            if(data.success) {
                // ukloni notifikaciju iz liste
                document.getElementById(`notifikacija-${id}`).remove();
            } else {
                showToast('Greška pri označavanju notifikacije kao pročitane.', 'error');
            }
        } catch(e) {
            showToast('Greška pri komunikaciji sa serverom.', 'error');
        }
    }