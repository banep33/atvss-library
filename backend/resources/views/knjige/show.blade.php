@extends('layouts.app')

@section('title', $knjiga->naziv_knjige)

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/knjige-show.css') }}">
    <link rel="stylesheet" href="{{ asset_v('css/knjige-modal.css') }}">
@endpush

@section('content')

<div class="book-page">

<a href="/" class="back-btn"> <i class="fa-solid fa-arrow-left"></i> Nazad</a>

<div class="book-top">

    <!-- GORNJI RED: Slika i Info -->
    <div>
        <div class="book-left">
            <div class="book-image">
                @if($knjiga->slika)
                    <img src="/{{ $knjiga->slika }}" alt="{{ $knjiga->naziv_knjige }}">
                @else
                    <img src="/images/default.png" alt="{{ $knjiga->naziv_knjige }}">
                @endif
            </div>
        </div>

        <div class="book-info">
            <h1>{{ $knjiga->naziv_knjige }}</h1>

            <div class="book-meta">
                @if($user && $user->isAdminOrBibliotekar())
                    <div class="admin-info-grid">
                        <div class="info-item">
                            <span class="label">Inventarski broj:</span>
                            <span class="value">{{ $knjiga->in_broj }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Signatura:</span>
                            <span class="value">{{ $knjiga->signatura }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Datum:</span>
                            <span class="value">{{ \Carbon\Carbon::parse($knjiga->datum)->format('d.m.Y') }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Autor:</span>
                            <span class="value">{{ $knjiga->autor }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Naziv knjige:</span>
                            <span class="value">{{ $knjiga->naziv_knjige }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Način nabavke:</span>
                            <span class="value">{{ $knjiga->nacin_nabavke }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Mesto i godina izdavanja:</span>
                            <span class="value">{{ $knjiga->izdanje_prikaz }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">ISBN:</span>
                            <span class="value">{{ $knjiga->isbn ?? 'N/A' }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">Izdavač:</span>
                            <span class="value">{{ $knjiga->izdavac }}</span>
                        </div>
                        <div class="info-item full-width">
                            <span class="label">Žanr:</span>
                            <span class="value">
                                @foreach($knjiga->zanrovi as $zanr)
                                    <button type="button" class="zanr-badge zanr-badge--klik"
                                            onclick="openZanrModal({{ $zanr->id }}, this)"
                                            title="Prikaži sve knjige iz ovog žanra">{{ $zanr->naziv }}</button>
                                @endforeach
                            </span>
                        </div>
                    </div>
                @else
                    {{-- Isti okvir kao .admin-info-grid, samo bez dvokolonskog
                         grida — clan vidi manje podataka, pa idu u redovima. --}}
                    <div class="clan-info-box">
                        <p><strong>Autor:</strong> {{ $knjiga->autor }}</p>
                        <p><strong>Izdavač:</strong> {{ $knjiga->izdavac }}</p>
                        <p><strong>Mesto i godina:</strong> {{ $knjiga->izdanje_prikaz }}</p>
                        <p><strong>Žanr:</strong>
                            @foreach($knjiga->zanrovi as $zanr)
                                <button type="button" class="zanr-badge zanr-badge--klik"
                                        onclick="openZanrModal({{ $zanr->id }}, this)"
                                        title="Prikaži sve knjige iz ovog žanra">{{ $zanr->naziv }}</button>
                            @endforeach
                        </p>
                    </div>
                @endif
            </div>

{{-- Modifikator nose samo admin i bibliotekar: kod njih red ima i desnu
     stranu („Izmeni") pa se poravnava sa donjom ivicom slike. Član vidi
     samo „Rezerviši", koje stoji odmah ispod kartice sa podacima. --}}
<div class="book-actions-row{{ $user && $user->isAdminOrBibliotekar() ? ' book-actions-row--uz-sliku' : '' }}">
    <div class="book-actions-left">
    @if(!$knjiga->je_eknjiga)
        @if($user && $user->isAdminOrBibliotekar())
        <button id="adminZaduziSebeBtn" class="action-btn btn-zaduzi-sebe" disabled onclick="adminZaduziSebe()">
            <i class="fa-solid fa-book-bookmark"></i> Zaduži za sebe
        </button>
        @endif

        @if($user && $user->isClan())
        <button id="rezervisiBtn" class="action-btn" disabled>
            Rezerviši
        </button>
        <button id="otkaziMojuRezervacijuBtn" class="action-btn" style="display:none; background:#f56565;">
            Otkaži rezervaciju
        </button>
        @endif
    @endif
   </div>

    @if($user && $user->isAdminOrBibliotekar())
    {{-- „Obriši" je premešten sa kartice u katalogu (kartica je sada samo
         link ka ovoj stranici) i stoji uz „Izmeni" — obe radnje nad knjigom
         su na jednom mestu, tamo gde se knjiga i gleda. --}}
    <div class="book-actions-right">
        <button class="btn-izmeni" onclick="openEditModal({{ $knjiga->id }})">
            <i class="fa-solid fa-pen"></i> Izmeni
        </button>
        <button class="btn-obrisi-knjigu" onclick="obrisiKnjigu({{ $knjiga->id }}, @js($knjiga->naziv_knjige))">
            <i class="fa-solid fa-trash-can"></i> Obriši
        </button>
    </div>
    @endif
</div>

            @if($user && $user->isClan() && $knjiga->je_eknjiga)
    <div class="eknjiga-info-box">
        <h3> <i class="fa-solid fa-book"></i> Ovo je E-knjiga</h3>
        <p>Pristupite knjizi online klikom na dugme ispod:</p>
        <a href="{{ $knjiga->eknjiga_link }}" target="_blank" class="btn-eknjiga">
            <i class="fa-solid fa-link"></i> Otvori E-knjigu
        </a>
    </div>
@endif
        </div>
    </div>

    <!-- OPIS (samo kad ga ima — prazan opis je ranije ostavljao naslov
         „Opis" i plavu liniju bez ijednog reda teksta ispod) -->
    @if(trim((string) $knjiga->opis) !== '')
    <div class="book-description">
        <h2>Opis</h2>
        <p>{{ $knjiga->opis }}</p>
    </div>
    @endif

    @if(!$knjiga->je_eknjiga)
@if($user && $user->isAdminOrBibliotekar())
<div class="admin-primerci-section">
    <div class="admin-primerci-header">
        <h3><i class="fa-solid fa-bars"></i> Upravljanje primercima (Bibliotekar)</h3>
    </div>
    
    <div class="quick-add-section">
        <label>Dodaj primerke:</label>
        <input type="number" id="brojPrimeraka" min="1" max="20" value="1" />
        <button onclick="dodajVisePrimeraka()">+ Dodaj</button>
        <span style="color: #718096; font-size: 13px; margin-left: 12px;">
            Trenutno: <strong id="ukupnoPrimeraka">{{ $knjiga->primerci->count() }}</strong> primeraka
        </span>
        <button onclick="openPrimerciModal()" style="margin-left: 12px;">
            <i class="fa-solid fa-table"></i> Pogledaj primerke knjige
        </button>
    </div>
</div>

<!-- MODAL ZA PRIMERKE -->
<div id="primerciModal" class="primerci-modal">
    <div class="primerci-modal-body">
        <div class="primerci-modal-header">
            <h3><i class="fa-solid fa-bars"></i> Primerci knjige</h3>
            <button class="close-btn" onclick="closePrimerciModal()">&times;</button>
        </div>

        <table class="primerci-tabela" data-paginate="10">
            <thead>
                <tr>
                    <th>Inventarski broj</th>
                    <th>Status</th>
                    <th>Akcije</th>
                </tr>
            </thead>
            <tbody>
                @foreach($knjiga->primerci as $p)
                <tr id="modal-primerak-{{ $p->id }}">
                    <td class="primerci-inv">{{ $p->inventarski_broj }}</td>
                    <td>
                        @if($p->isSlobodan())
                            <span class="primerak-status primerak-status--slobodan">Dostupno</span>
                        @elseif($p->isRezervisan())
                            <span class="primerak-status primerak-status--rezervisan">Rezervisano</span>
                        @else
                            <span class="primerak-status primerak-status--zaduzen">Zaduženo</span>
                        @endif
                    </td>
                    <td>
                        @if($p->isSlobodan())
                            <button
                                type="button"
                                class="btn-obrisi-primerak"
                                onclick="obrisiPrimerakIzModala({{ $p->id }})"
                                title="Obriši primerak"
                            >
                                <i class="fa-solid fa-trash-can"></i> Obriši
                            </button>
                        @else
                            <span class="primerci-napomena">Ne može se obrisati</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endif

    <!-- OD ISTOG AUTORA -->
    @if($autorKnjige->count() > 0)
    <div class="slicne-knjige">
        <h2>Od istog autora</h2>
        <div class="slicne-grid">
            @foreach($autorKnjige as $a)
                <a href="/knjige/{{ $a->id }}" class="slicna-knjiga">
                    @if($a->slika)
                        <img src="/{{ $a->slika }}" alt="{{ $a->naziv_knjige }}">
                    @else
                        <img src="/images/default.png" alt="{{ $a->naziv_knjige }}">
                    @endif
                    <h4>{{ $a->naziv_knjige }}</h4>
                </a>
            @endforeach
        </div>
    </div>
    @endif

</div><!-- end book-top -->
</div><!-- end book-page -->

@if(!$knjiga->je_eknjiga && $user && $user->isAdminOrBibliotekar())
<div class="admin-sections-container">
    <div class="zaduzivanja-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2>Aktuelna Zaduživanja</h2>
            <button class="action-btn" onclick="openZaduzivanjModal()" style="background-color: #2196F3; padding: 8px 12px; font-size: 14px;">
                <i class="fa-solid fa-plus"></i> Novo Zaduživanje
            </button>
        </div>
        <div id="zaduzivanja-list" style="max-height: 300px; overflow-y: auto;">
            <p class="lista-prazna">Učitavanje...</p>
        </div>
    </div>

    <!-- SEKCIJA AKTIVNIH REZERVACIJA - SAMO ZA ADMINA -->
    <div class="rezervacije-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2>Aktivne Rezervacije</h2>
        </div>
        <div id="rezervacije-list" style="max-height: 300px; overflow-y: auto;">
            <p class="lista-prazna">Učitavanje...</p>
        </div>
    </div>
</div>
@endif

<!-- MODAL ZA DODAVANJE PRIMERKA -->
<div id="dodajPrimerakModal" class="modal">
    <div class="modal-content-small">
        <div class="modal-header">
            <h3>Dodaj Primerak</h3>
            <button class="close-btn" onclick="closeDodajPrimerakModal()">&times;</button>
        </div>
        {{-- Polja nose prefiks `primerak_`: modal za izmenu knjige (niže na istoj
             stranici) ima svoja polja `signatura` i `nacin_nabavke`. Bez prefiksa
             bi getElementById pogađao OVA polja — prva u dokumentu — pa bi izmena
             knjige čitala i upisivala prazne vrednosti preko postojećih. --}}
        <form id="dodajPrimerakForm">
            <div class="form-group">
                <label>Inventarski broj: <span class="obavezno">*</span></label>
                <input type="text" id="primerak_inventarski_broj" placeholder="npr. INV-001" required>
            </div>
            <div class="form-group">
                <label>Signatura:</label>
                <input type="text" id="primerak_signatura" placeholder="npr. D-123">
            </div>
            <div class="form-group">
                <label>Lokacija:</label>
                <input type="text" id="primerak_lokacija" placeholder="npr. Polica A1">
            </div>
            <div class="form-group">
                <label>Način nabavke:</label>
                <select id="primerak_nacin_nabavke">
                    <option value="">-- Odaberite --</option>
                    <option value="kupovina">Kupovina</option>
                    <option value="dar">Dar</option>
                    <option value="razmena">Razmena</option>
                </select>
            </div>
            <div class="form-group">
                <label>Cena:</label>
                <input type="number" id="primerak_cena" placeholder="npr. 1500" step="0.01">
            </div>
            <button type="submit" class="btn-save">Dodaj Primerak</button>
        </form>
    </div>
</div>

<!-- MODAL ZA ZADUŽAVANJE -->
<div id="zaduzivanjModal" class="modal">
    <div class="modal-content-small">
        <div class="modal-header">
            <h3>Zadužavanje Primerka</h3>
            <button class="close-btn" onclick="closeZaduzivanjModal()">&times;</button>
        </div>
        <form id="zaduzivanjForm">
            <div class="form-group">
                <label>Izaberite člana: <span class="obavezno">*</span></label>
                <select id="clan_id" required>
                    <option value="">-- Učitavanje članova --</option>
                </select>
            </div>
            <div class="form-group">
                <label>Primerak: <span class="obavezno">*</span></label>
                <select id="zaduz_primerak_id" required>
                    <option value="">-- Učitavanje dostupnih primeraka --</option>
                </select>
            </div>
            <div class="form-group">
                <label>Rok vraćanja: <span class="obavezno">*</span></label>
                <input type="date" id="rok_vracanja" required>
            </div>
            <button type="submit" class="btn-save">Zaduži</button>
        </form>
    </div>
</div>

<!-- MODAL ZA OTKAZIVANJE REZERVACIJE -->
<div id="otkaziRezervacijuModal" class="modal">
    <div class="modal-content-small">
        <div class="modal-header">
            <h3>Otkaži Rezervaciju</h3>
            <button class="close-btn" onclick="closeOtkaziRezervacijuModal()">&times;</button>
        </div>
        <div style="padding: 20px; text-align: center;">
            <p id="otkaziRezerRazlog" style="margin-bottom: 20px; color: #718096;">Da li ste sigurni?</p>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button class="btn-save" onclick="confirmOtkaziRezervaciju()" style="background-color: #f56565; flex: 1;">Otkaži</button>
                <button class="btn-save" onclick="closeOtkaziRezervacijuModal()" style="background-color: #a0aec0; flex: 1;">Nazad</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: KORISNIK OTKAZUJE SVOJU REZERVACIJU (član) -->
<div id="otkaziMojuRezervacijuModal" class="modal">
    <div class="modal-content-small">
        <div class="modal-header">
            <h3>Otkaži rezervaciju</h3>
            <button class="close-btn" onclick="closeOtkaziMojuRezervacijuModal()">&times;</button>
        </div>
        <div style="padding: 20px; text-align: center;">
            <p style="margin-bottom: 20px; color: #718096;">Da li ste sigurni da želite da otkažete svoju rezervaciju?</p>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button class="btn-save" onclick="confirmOtkaziMojuRezervaciju()" style="background-color: #f56565; flex: 1;">Da, otkaži</button>
                <button class="btn-save" onclick="closeOtkaziMojuRezervacijuModal()" style="background-color: #a0aec0; flex: 1;">Nazad</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL ZA E-KNJIGE -->
<div id="eknjigeModal" class="modal">
    <div class="modal-content-small">
        <div class="modal-header">
            <h3>Pristup e-knjizi</h3>
            <button class="close-btn" onclick="closeEknjigeModal()">&times;</button>
        </div>
        <div style="padding: 20px; text-align: center;">
            <p style="margin-bottom: 15px;">Kliknite na dugme ispod da pristupite e-knjizi:</p>
            <a href="{{ $knjiga->eknjiga_link }}" target="_blank" class="btn-save" style="display: inline-block; text-decoration: none;">
                <i class="fa-brands fa-leanpub"></i> Otvori e-knjigu
            </a>
        </div>
    </div>
</div>

<!-- MODAL: SVE KNJIGE IZ ŽANRA -->
<div id="zanrModal" class="modal">
    <div class="modal-content-small zanr-modal">
        <div class="modal-header">
            <h3 id="zanrModalNaslov">Knjige iz žanra</h3>
            <button class="close-btn" onclick="closeZanrModal()">&times;</button>
        </div>
        <div class="zanr-modal-pretraga">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text"
                   id="zanrPretraga"
                   placeholder="Pretraži po naslovu ili autoru"
                   autocomplete="off"
                   oninput="filtrirajZanrKnjige()">
        </div>
        <div id="zanrModalSadrzaj" class="zanr-modal-lista"></div>
    </div>
</div>

<!--footer-->

@endsection

@push('modals')
<!-- MODAL ZA IZMENU KNJIGE -->
@include('partials.knjiga-form-modal')

<!-- MODAL: Admin zaduži sebe -->
<div id="adminZaduziSebeModal" class="modal">
    <div class="modal-content-small">
        <div class="modal-header">
            <h3><i class="fa-solid fa-book-bookmark"></i> Zaduži knjigu za sebe</h3>
            <button class="close-btn" onclick="closeAdminZaduziSebeModal()">&times;</button>
        </div>
        <div style="padding: 4px 0 16px 0; color: #4a5568; font-size: 14px;">
            Zadužujete: <strong>{{ $knjiga->naziv_knjige }}</strong>
        </div>
        <form id="adminZaduziSebeForm" onsubmit="submitAdminZaduziSebe(event)">
            <div class="form-group">
                <label>Izaberi primerak: <span class="obavezno">*</span></label>
                <select id="adminSebePrimerakId" required>
                    <option value="">-- Učitavanje primeraka --</option>
                </select>
            </div>
            <div class="form-group">
                <label>Rok vraćanja: <span class="obavezno">*</span></label>
                <input type="date" id="adminSebeRokVracanja" required>
            </div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="submit" class="btn-save" style="flex:1;">
                    <i class="fa-solid fa-check"></i> Zaduži
                </button>
                <button type="button" class="btn-save" style="background:#a0aec0; flex:1;" onclick="closeAdminZaduziSebeModal()">
                    <i class="fa-solid fa-xmark"></i> Otkaži
                </button>
            </div>
        </form>
    </div>
</div>
@endpush

@push('scripts')
<script>
    const knjigaId = {{ $knjiga->id }};
    const userId = {{ $user ? $user->id : 'null' }};
    const isEknjiga = {{ $knjiga->je_eknjiga ? 'true' : 'false' }};
    const userClanKarta = '{{ $user ? $user->broj_clanske_karte : '' }}';
    const isAdmin = {{ $user && $user->isAdminOrBibliotekar() ? 'true' : 'false' }};
    const inBroj = '{{ $knjiga->in_broj }}';

    const isBibliotekar = {{ $user && $user->isBibliotekar() ? 'true' : 'false' }};
    const isAdminOrBibliotekar = isAdmin || isBibliotekar;

     @if($user && $user->isClan())
    // ✅ Računa se jednom na serveru, nema dodatnog API poziva
    let imaAktivnoZaduzenje = {{ \App\Models\Zaduzivanje::where('user_id', $user->id)->whereNull('datum_vracanja')->exists() ? 'true' : 'false' }};
    let imaAktivnuRezervaciju = {{ \App\Models\Rezervacija::where('user_id', $user->id)->where('status', 'odobrena')->exists() ? 'true' : 'false' }};
    let imaNaCekanju = {{ \App\Models\Rezervacija::where('user_id', $user->id)->where('status', 'na_cekanju')->exists() ? 'true' : 'false' }};

    // Provera za konkretnu knjigu
    let zaduzioOvuKnjigu      = {{ \App\Models\Zaduzivanje::where('user_id', $user->id)->whereNull('datum_vracanja')->whereHas('primerak', fn($q) => $q->where('knjiga_id', $knjiga->id))->exists() ? 'true' : 'false' }};
    let rezervisaoOvuKnjigu   = {{ \App\Models\Rezervacija::where('user_id', $user->id)->where('knjiga_id', $knjiga->id)->whereIn('status', ['na_cekanju', 'odobrena'])->exists() ? 'true' : 'false' }};
    let statusOveRezervacije  = '{{ \App\Models\Rezervacija::where('user_id', $user->id)->where('knjiga_id', $knjiga->id)->whereIn('status', ['na_cekanju', 'odobrena'])->value('status') ?? '' }}';
    let mojaRezervacijaId     = {{ \App\Models\Rezervacija::where('user_id', $user->id)->where('knjiga_id', $knjiga->id)->whereIn('status', ['na_cekanju', 'odobrena'])->value('id') ?? 'null' }};
    @else
    let imaAktivnoZaduzenje   = false;
    let imaAktivnuRezervaciju = false;
    let imaNaCekanju          = false;
    let zaduzioOvuKnjigu      = false;
    let rezervisaoOvuKnjigu   = false;
    let statusOveRezervacije  = '';
    let mojaRezervacijaId     = null;
    @endif
    
    async function dodajVisePrimeraka() {
        const broj = parseInt(document.getElementById('brojPrimeraka').value);
        
        if (broj < 1 || broj > 20) {
            showToast('Možete dodati između 1 i 20 primeraka odjednom.', 'warning');
            return;
        }
        
        if (!await confirmDialog({ type: 'primary', title: 'Dodavanje primeraka', message: `Da li želite da dodate ${broj} primeraka za ovu knjigu?`, confirmText: 'Dodaj' })) {
            return;
        }
        
        try {
            const res = await fetch('/api/primerci/bulk-add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    knjiga_id: knjigaId,
                    broj_primeraka: broj
                })
            });
            
            const data = await res.json();
            
            if (data.success) {
                showToast(`Uspešno dodato ${broj} primeraka!`, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.message || 'Greška pri dodavanju primeraka', 'error');
            }
        } catch (error) {
            console.error('Greška:', error);
            showToast('Greška pri dodavanju primeraka', 'error');
        }
    }

    /* Klasa umesto inline `style.display` — otvoreno stanje mora da bude
       vidljivo iz CSS-a (widget AI asistenta se gasi dok je modal otvoren). */
    function openPrimerciModal() {
    const modal = document.getElementById('primerciModal');
    modal.classList.add('is-open');
}

function closePrimerciModal() {
    const modal = document.getElementById('primerciModal');
    modal.classList.remove('is-open');
}

async function obrisiPrimerakIzModala(primerakId) {
    if (!await confirmDialog({ type: 'danger', title: 'Brisanje primerka', message: 'Da li ste sigurni da želite da obrišete ovaj primerak?', confirmText: 'Obriši' })) return;

    try {
        const res = await fetch(`/api/primerci/${primerakId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        const data = await res.json();

        if (data.success) {
            showToast(data.message || 'Primerak je obrisan.', 'success');
            // Ukloni red iz tabele u modalu
            const row = document.getElementById(`modal-primerak-${primerakId}`);
            if (row) row.remove();
            // Smanji brojac
            const ukupno = parseInt(document.getElementById('ukupnoPrimeraka').textContent);
            document.getElementById('ukupnoPrimeraka').textContent = ukupno - 1;
            loadPrimerci();
        } else {
            showToast(data.message || 'Greška pri brisanju primerka', 'error');
        }
    } catch (error) {
        showToast('Greška pri brisanju primerka', 'error');
    }
}

// Zatvaranje klikom van modala
document.getElementById('primerciModal')?.addEventListener('click', function(e) {
    if (e.target === this) closePrimerciModal();
});
    
/* showToast je globalizovan u notifications.js */

/* potvrdiOdjavu je globalizovan u notifications.js */

    
</script>
<script src="{{ asset_v('js/knjiga-forma.js') }}"></script>
<script src="{{ asset_v('js/knjige-show.js') }}"></script>

@endpush
