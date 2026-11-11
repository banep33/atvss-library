@extends('layouts.app')

@section('title', 'Moj Profil')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/profil.css') }}">
@endpush

@section('content')

<div class="profil-wrapper page-wrapper">

    <!-- LEVA KOLONA -->
    <div class="profil-kartica">
        <div class="profil-avatar">
            {{ strtoupper(substr($user->ime, 0, 1)) }}{{ strtoupper(substr($user->prezime, 0, 1)) }}
        </div>
        <div class="profil-ime">{{ $user->ime }} {{ $user->prezime }}</div>
        <div class="profil-email">{{ $user->email }}</div>

        <span class="profil-role-badge {{ $user->isAdmin() ? 'admin' : ($user->isBibliotekar() ? 'bibliotekar' : 'clan') }}">
            {{ $user->isAdmin() ? 'Administrator' : ($user->isBibliotekar() ? 'Bibliotekar' : 'Član') }}
        </span>

        <div class="profil-info-lista">
            @if($user->isClan())
                <div class="profil-info-item">
                    <span class="label">Broj karte</span>
                    <span class="value">{{ $user->broj_clanske_karte ?? 'N/A' }}</span>
                </div>
                <div class="profil-info-item">
                    <span class="label">Aktivne rez.</span>
                    <span class="value">{{ $aktivneRezervacije->count() }}</span>
                </div>
                <div class="profil-info-item">
                    <span class="label">Istorija</span>
                    <span class="value">{{ $istorijaRezervacija->count() }}</span>
                </div>
            @endif
            @if($user->isAdmin() || $user->isBibliotekar())
                <div class="profil-info-item">
                    <span class="label">Uloga</span>
                    <span class="value">{{ $user->isAdmin() ? 'Admin' : 'Bibliotekar' }}</span>
                </div>
                <div class="profil-info-item">
                    <span class="label">Članovi</span>
                    <span class="value">{{ $statistike['ukupno_clanova'] }}</span>
                </div>
            @endif
        </div>
    </div>

    <!-- DESNA KOLONA -->
    <div class="profil-desno">
        <div>
            <div class="profil-tab-nav">
                <button class="profil-tab-btn active" onclick="showProfilTab('podaci', this)">
                    <i class="fa-solid fa-user"></i> Lični podaci
                </button>
                <button class="profil-tab-btn" onclick="showProfilTab('lozinka', this)">
                    <i class="fa-solid fa-lock"></i> Promena lozinke
                </button>
                @if($user->isClan())
                <button class="profil-tab-btn" onclick="showProfilTab('aktivne', this)">
                    <i class="fa-solid fa-clock"></i> Aktivne rezervacije
                    @if($aktivneRezervacije->count() > 0)
                        <span style="background:#4299e1;color:white;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;">{{ $aktivneRezervacije->count() }}</span>
                    @endif
                </button>
                <button class="profil-tab-btn" onclick="showProfilTab('istorija', this)">
                    <i class="fa-solid fa-history"></i> Istorija
                </button>
                @endif
                @if($user->isAdmin() || $user->isBibliotekar())
                <button class="profil-tab-btn" onclick="showProfilTab('statistike', this)">
                    <i class="fa-solid fa-chart-bar"></i> Statistike
                </button>
                @endif
            </div>

            <div class="profil-tab-content">

                @if(session('success'))
                    <div class="profil-alert success"><i class="fa-solid fa-check"></i> {{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="profil-alert error"><i class="fa-solid fa-xmark"></i> {{ session('error') }}</div>
                @endif

                <!-- TAB: LIČNI PODACI -->
                <div id="tab_podaci" class="profil-sekcija active">
                    <p class="sekcija-naslov"><i class="fa-solid fa-user"></i> Lični podaci</p>
                    {{-- novalidate: proveru radi proveriObavezna() iz notifications.js,
                         pa poruke stoje ispod polja na srpskom, umesto engleskog
                         „balončića" pregledača. --}}
                    <form action="{{ route('profil.update') }}" method="POST" id="profilPodaciForm" novalidate>
                        @csrf
                        <div class="profil-form-grid">
                            <div class="profil-form-group">
                                <label>Ime *</label>
                                <input type="text" name="ime" value="{{ old('ime', $user->ime) }}" required>
                                @error('ime')<span class="polje-greska">{{ $message }}</span>@enderror
                            </div>
                            <div class="profil-form-group">
                                <label>Prezime *</label>
                                <input type="text" name="prezime" value="{{ old('prezime', $user->prezime) }}" required>
                                @error('prezime')<span class="polje-greska">{{ $message }}</span>@enderror
                            </div>
                            <div class="profil-form-group full">
                                <label>Email *</label>
                                <input type="email" name="email" value="{{ old('email', $user->email) }}" required>
                                @error('email')<span class="polje-greska">{{ $message }}</span>@enderror
                            </div>
                            @if($user->isClan())
                            <div class="profil-form-group">
                                <label>Broj članske karte</label>
                                <input type="text" value="{{ $user->broj_clanske_karte ?? 'N/A' }}" readonly>
                            </div>
                            @endif
                        </div>
                        <button type="submit" class="profil-btn-sacuvaj">
                            <i class="fa-solid fa-floppy-disk"></i> Sačuvaj promene
                        </button>
                    </form>
                </div>

                <!-- TAB: PROMENA LOZINKE -->
                <div id="tab_lozinka" class="profil-sekcija">
                    <p class="sekcija-naslov"><i class="fa-solid fa-lock"></i> Promena lozinke</p>
                    <form action="{{ route('profil.lozinka') }}" method="POST" id="profilLozinkaForm" novalidate>
                        @csrf
                        <div class="profil-form-grid">
                            <div class="profil-form-group full">
                                <label for="staraLozinka">Stara lozinka *</label>
                                <input type="password" id="staraLozinka" name="stara_lozinka" required>
                                @error('stara_lozinka')<span class="polje-greska">{{ $message }}</span>@enderror
                            </div>
                            <div class="profil-form-group">
                                {{-- minlength prati PasswordPolicy (najmanje 8); ranije je stajalo 6 --}}
                                <label for="novaLozinka">Nova lozinka *</label>
                                <input type="password" id="novaLozinka" name="nova_lozinka" required minlength="8">
                                @error('nova_lozinka')<span class="polje-greska">{{ $message }}</span>@enderror
                            </div>
                            <div class="profil-form-group">
                                <label for="potvrdaLozinke">Potvrdi novu lozinku *</label>
                                <input type="password" id="potvrdaLozinke" name="nova_lozinka_confirmation" required>
                                @error('nova_lozinka_confirmation')<span class="polje-greska">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <button type="submit" class="profil-btn-sacuvaj">
                            <i class="fa-solid fa-key"></i> Promeni lozinku
                        </button>
                    </form>
                </div>

                @if($user->isClan())
                <!-- TAB: AKTIVNE REZERVACIJE -->
                <div id="tab_aktivne" class="profil-sekcija">
                    <p class="sekcija-naslov"><i class="fa-solid fa-clock"></i> Aktivne rezervacije</p>
                    @if($aktivneRezervacije->count() > 0)
                        <div class="rezervacije-tabela-wrapper">
                            <table class="rezervacije-tabela" data-paginate="10">
                                <thead>
                                    <tr>
                                        <th>Knjiga</th>
                                        <th>Autor</th>
                                        <th>Datum zahteva</th>
                                        <th>Status</th>
                                        <th>Rok za preuzimanje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($aktivneRezervacije as $rez)
                                    <tr>
                                        <td><strong>{{ $rez->knjiga->naziv_knjige }}</strong></td>
                                        <td>{{ $rez->knjiga->autor }}</td>
                                        <td>{{ $rez->datum_rezervacije->format('d.m.Y') }}</td>
                                        <td>
                                            @php
                                                $statusLabels = [
                                                    'na_cekanju' => 'Na čekanju',
                                                    'odobrena'   => 'Odobrena',
                                                ];
                                            @endphp
                                            <span class="status-badge status-{{ $rez->status }}">
                                                {{ $statusLabels[$rez->status->value] ?? $rez->status->value }}
                                            </span>
                                        </td>
                                        <td>
                                            @if($rez->status->value === 'odobrena' && $rez->rok_preuzimanja)
                                                @php
                                                    // Broj dana do isteka roka za preuzimanje (zaokruženo naviše).
                                                    $daniDoIsteka = (int) ceil(now()->diffInDays($rez->rok_preuzimanja, false));
                                                    // <=1 dan crveno (upozorenje), <=2 dana narandžasto, inače zeleno.
                                                    $boja = $daniDoIsteka <= 1 ? '#e53e3e' : ($daniDoIsteka <= 2 ? '#dd6b20' : '#2f855a');
                                                @endphp
                                                @if($daniDoIsteka < 0)
                                                    <span style="color:#e53e3e; font-weight:700;">Rok je istekao</span>
                                                @else
                                                    <span style="color:{{ $boja }}; font-weight:700;">
                                                        {{ $daniDoIsteka }} {{ $daniDoIsteka === 1 ? 'dan' : 'dana' }}
                                                    </span>
                                                    <small style="color:#718096; display:block;">do {{ $rez->rok_preuzimanja->format('d.m.Y') }}</small>
                                                @endif
                                            @else
                                                <span style="color:#a0aec0;">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="prazno-stanje">
                            <i class="fa-solid fa-clock"></i>
                            <p>Nemate aktivnih rezervacija.</p>
                        </div>
                    @endif
                </div>

                <!-- TAB: ISTORIJA -->
                <div id="tab_istorija" class="profil-sekcija">
                    <p class="sekcija-naslov"><i class="fa-solid fa-history"></i> Istorija rezervacija</p>
                    @if($istorijaRezervacija->count() > 0)
                        <div class="rezervacije-tabela-wrapper">
                            <table class="rezervacije-tabela" data-paginate="10">
                                <thead>
                                    <tr>
                                        <th>Knjiga</th>
                                        <th>Autor</th>
                                        <th>Datum zahteva</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($istorijaRezervacija as $rez)
                                    <tr>
                                        <td><strong>{{ $rez->knjiga->naziv_knjige }}</strong></td>
                                        <td>{{ $rez->knjiga->autor }}</td>
                                        <td>{{ $rez->datum_rezervacije->format('d.m.Y') }}</td>
                                        <td>
                                            @php
                                                $statusLabels = [
                                                    'preuzeta'  => 'Zadužena',
                                                    'odbijena'  => 'Odbijena',
                                                    'otkazana'  => 'Otkazana',
                                                    'istekla'   => 'Istekla',
                                                ];
                                            @endphp
                                            <div class="status-cell">
                                                <span class="status-badge status-{{ $rez->status }}">
                                                    {{ $statusLabels[$rez->status->value] ?? $rez->status->value }}
                                                </span>
                                                @if($rez->vracena)
                                                <span class="status-badge status-vracena">
                                                    Vraćena
                                                </span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="prazno-stanje">
                            <i class="fa-solid fa-history"></i>
                            <p>Nema prethodnih rezervacija.</p>
                        </div>
                    @endif
                </div>
                @endif

                @if($user->isAdmin() || $user->isBibliotekar())
                <!-- TAB: STATISTIKE -->
                <div id="tab_statistike" class="profil-sekcija">
                    <p class="sekcija-naslov"><i class="fa-solid fa-chart-bar"></i> Pregled sistema</p>
                    <div class="stats-grid">
                        <div class="stat-kartica">
                            <div class="stat-broj">{{ $statistike['aktivne_rezervacije'] }}</div>
                            <div class="stat-label">Aktivne rezervacije</div>
                        </div>
                        <div class="stat-kartica">
                            <div class="stat-broj">{{ $statistike['zaduzene_knjige'] }}</div>
                            <div class="stat-label">Zadužene knjige</div>
                        </div>
                        <div class="stat-kartica kasnjenja">
                            <div class="stat-broj">{{ $statistike['kasnjenja'] }}</div>
                            <div class="stat-label">Kašnjenja</div>
                        </div>
                        <div class="stat-kartica">
                            <div class="stat-broj">{{ $statistike['ukupno_clanova'] }}</div>
                            <div class="stat-label">Ukupno članova</div>
                        </div>
                    </div>
                    <div style="margin-top: 20px; text-align: center;">
                        <a href="/admin/rezervacije" class="profil-btn-sacuvaj" style="display: inline-block; text-decoration: none;">
                            <i class="fa-solid fa-list"></i> Upravljaj rezervacijama
                        </a>
                    </div>
                </div>
                @endif

            </div>
        </div>
    </div>
</div>

<!--footer-->

@endsection

@push('scripts')
<script>
function showProfilTab(tabId, btn) {
    document.querySelectorAll('.profil-sekcija').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.profil-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab_' + tabId).classList.add('active');
    btn.classList.add('active');
}

/* Obavezna polja i najmanja dužina lozinke — poruka ide ispod polja, bez
   zbirnog dijaloga (obe forme su kratke i cele vidljive). Zamenjuje engleski
   „balončić" pregledača, koji je stizao od `required`/`minlength`. */
['profilPodaciForm', 'profilLozinkaForm'].forEach(function (id) {
    document.getElementById(id)?.addEventListener('submit', function (e) {
        if (typeof proveriObavezna === 'function' && !proveriObavezna(this, { dijalog: false })) {
            e.preventDefault();
        }
    });
});

/* potvrdiOdjavu je globalizovan u notifications.js */

@if(session('success') || session('error'))
    @if(session('_lozinka'))
        document.querySelector('[onclick*="lozinka"]')?.click();
    @endif
@endif
</script>
@endpush
