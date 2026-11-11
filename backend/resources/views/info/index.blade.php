@extends('layouts.app')

@section('title', 'Info sekcija - Biblioteka')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/info.css') }}">
@endpush

@section('content')

<div class="info-container">
    
    <!-- SEKCIJA O BIBLIOTECI -->
    <div class="info-card">
        <h2> <i class="fa-solid fa-building"></i> O biblioteci</h2>
        <div class="info-content">
            <p>Dobrodošli u biblioteku <strong>Akademije tehničko-vaspitačkih strukovnih studija</strong>!</p>
            <p>Naša biblioteka poseduje bogat fond knjiga iz različitih žanrova!</p>
        </div>
    </div>

    <!-- KAKO POSTATI ČLAN -->
    <div class="info-card">
        <h2> <i class="fa-solid fa-user"></i> Ko može postati član?</h2>
        <div class="info-content">
            <div class="requirement-box">
                <h3>Članovi biblioteke mogu da budu svi studenti i zaposleni naše akademije!</h3>
                
            </div>
            
            <div class="benefit-box">
                <h3> <i class="fa-regular fa-money-bill-1"></i> Članarina:</h3>
                <p class="free-tag">BESPLATNO za sve članove!</p>
            </div>

            <div class="benefit-box">
                <h3> <i class="fa-solid fa-clipboard-list"></i> Beneficije članstva:</h3>
                <ul>
                    <li> <i class="fa-solid fa-circle-check"></i> Pozajmica do 1 knjige istovremeno</li>
                    <li> <i class="fa-solid fa-circle-check"></i> Rok pozajmice određuje bibliotekar u dogovoru sa članom koji je rezervisao određeni primerak knjige</li>
                    <li> <i class="fa-solid fa-circle-check"></i> Pristup e-knjigama sa jedinstvenim kodom</li>
                    <li> <i class="fa-solid fa-circle-check"></i> Rezervacija knjiga online</li>
                    
                </ul>
            </div>
        </div>
    </div>

    

    <!-- KONTAKT -->
    <div class="info-card">
        <h2> <i class="fa-solid fa-phone"></i> Kontakt informacije</h2>
        <div class="info-content">
            <div class="contact-grid">
                <div class="contact-item">
                    <span class="icon"> <i class="fa-solid fa-location-dot"></i> </span>
                    <div>
                        <strong>Adresa:</strong>
                        <p>Aleksandra Medvedeva 20, Niš</p>
                    </div>
                </div>
                <div class="contact-item">
                    <span class="icon"> <i class="fa-solid fa-envelope"></i> </span>
                    <div>
                        <strong>Email:</strong>
                        <p>silvana.bogicevic@akademijanis.edu.rs</p>
                    </div>
                </div>
                <div class="contact-item">
                    <span class="icon"> <i class="fa-solid fa-phone"></i> </span>
                    <div>
                        <strong>Telefon:</strong>
                        <p> +381 18 588 211</p>
                    </div>
                </div>
                <div class="contact-item">
                    <span class="icon"> <i class="fa-solid fa-clock"></i> </span>
                    <div>
                        <strong>Radno vreme:</strong>
                        <p>Pon-Pet: 08:00 - 16:00h</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!--footer-->

@endsection

@push('scripts')
<script>
    const userId = {{ $user?->id ?? 'null' }};

/* potvrdiOdjavu je globalizovan u notifications.js */

    /* Slanje predloga
    document.getElementById('predlogForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();

        const data = {
            user_id: userId,
            naziv_knjige: document.getElementById('naziv_knjige').value,
            autor: document.getElementById('autor').value,
            razlog: document.getElementById('razlog').value
        };

        try {
            const res = await fetch('/api/predlozi', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify(data)
            });

            const result = await res.json();

            if (result.success) {
                showToast('Predlog uspešno poslat!', 'success');
                document.getElementById('predlogForm').reset();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('Greška pri slanju predloga', 'error');
            }
        } catch (error) {
            showToast('Greška pri slanju predloga', 'error');
        }
    });
    */

    /*function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }*/
</script>
@endpush
