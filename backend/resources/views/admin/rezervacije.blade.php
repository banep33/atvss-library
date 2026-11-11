@extends('layouts.admin')

@section('title', 'Upravljanje rezervacijama')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/admin.css') }}">
@endpush

@section('content')

<div class="admin-container">

    <!-- Tab navigacija -->
    <div class="tab-navigation">
        <button class="tab-btn active" onclick="showTab('na_cekanju')">
            <i class="fa-solid fa-clock-rotate-left"></i> Na čekanju 
            <span class="tab-count">{{ isset($naCekanju) ? $naCekanju->count() : 0 }}</span>
        </button>
        <button class="tab-btn" onclick="showTab('odobrene')">
            <i class="fa-solid fa-circle-check"></i> Odobrene 
            <span class="tab-count">{{ isset($odobrene) ? $odobrene->count() : 0 }}</span>
        </button>
        <button class="tab-btn" onclick="showTab('odbijene')">
            <i class="fa-solid fa-ban"></i> Odbijene 
            <span class="tab-count">{{ isset($odbijene) ? $odbijene->count() : 0 }}</span>
        </button>
        <button class="tab-btn" onclick="showTab('preuzete')">
            <i class="fa-solid fa-book"></i> Zadužene 
            <span class="tab-count">{{ isset($preuzete) ? $preuzete->count() : 0 }}</span>
        </button>
        <button class="tab-btn" onclick="showTab('kasnjenja')">
            <i class="fa-solid fa-triangle-exclamation"></i> Kašnjenja 
            <span class="tab-count kasnjenje-badge">{{ isset($kasnjenja) ? $kasnjenja->count() : 0 }}</span>
        </button>
        <button class="tab-btn" onclick="showTab('arhiva')">
            <i class="fa-solid fa-box-archive"></i> Arhiva
            <span class="tab-count">
            {{ (isset($arhivaZahtevi) ? $arhivaZahtevi->count() : 0) +
            (isset($arhivaOdobrene) ? $arhivaOdobrene->count() : 0) +
            (isset($arhivaOdbijene) ? $arhivaOdbijene->count() : 0) +
            (isset($arhivaZaduzene) ? $arhivaZaduzene->count() : 0) +
            (isset($arhivaVracene) ? $arhivaVracene->count() : 0) }}
           </span>
        </button>
        <button class="tab-btn" onclick="showTab('odrzavanje')">
            <i class="fa-solid fa-tools"></i> Održavanje
        </button>
    </div>

    <!-- Tab: Održavanje i Cleanup -->
    <div id="tab_odrzavanje" class="tab-content">
        <div class="admin-card">
            <div class="card-header">
                <h2><i class="fa-solid fa-tools"></i> Održavanje rezervacija</h2>
            </div>

            <div class="maintenance-wrapper">
                <div class="maintenance-section maintenance-section--ok">
                    <h3><i class="fa-solid fa-broom"></i> Očisti rezervisane primerke</h3>
                    <p>
                        Pronalazi primerke označene kao "rezervisana" koji nemaju aktivnu rezervaciju i vraća ih u status "slobodna".
                        Ovo je korisno za čišćenje starih testnih podataka ili problema nastalih nakon odbijanja rezervacija.
                    </p>
                    <button onclick="cleanupRezervacije()" class="btn-primary btn-maintenance btn-maintenance--ok">
                        <i class="fa-solid fa-broom"></i> Očisti rezervisane primerke
                    </button>
                    <div id="cleanup-result" class="maintenance-result"></div>
                </div>

                <div class="maintenance-section maintenance-section--upozorenje">
                    <h3><i class="fa-solid fa-clock"></i> Očisti istekle rezervacije</h3>
                    <p>
                        Pronalazi sve odobrene rezervacije kojima je prošao rok preuzimanja (5 dana) i automatski ih označava kao istekle.
                        Oslobađa primerke i šalje notifikacije korisnicima.
                    </p>
                    <button onclick="cleanupIstekle()" class="btn-primary btn-maintenance btn-maintenance--upozorenje">
                        <i class="fa-solid fa-clock"></i> Očisti istekle rezervacije
                    </button>
                    <div id="cleanup-istekle-result" class="maintenance-result"></div>
                </div>

                @if($user->isAdmin())
                <div class="maintenance-section maintenance-section--opasno">
                    <h3><i class="fa-solid fa-trash-can"></i> OBRIŠI SVE KNJIGE</h3>
                    <p>
                        <strong>OPASNO:</strong> Briše SVE knjige, primerke, rezervacije, zaduženja i notifikacije.
                    </p>
                    <button onclick="deleteAllKnjige()" class="btn-primary btn-maintenance btn-maintenance--opasno">
                        <i class="fa-solid fa-trash-can"></i> OBRIŠI SVE KNJIGE I PRIMERKE
                    </button>
                    <div id="delete-all-knjige-result" class="maintenance-result"></div>
                </div>
                @endif
            </div>
        </div>
    </div>

    
    <!-- Tab 1: Na čekanju -->
    <div id="tab_na_cekanju" class="tab-content active">
        <div class="admin-card">
            {{-- Pretraga i sortiranje. Blok je JEDAN za celu stranicu, a
                 knjige-rezervacije.js ga premešta u karticu taba koji korisnik
                 otvori (vidi prazne `.controls-slot` u ostalim tabovima).
                 Tabovi „Kašnjenja", „Arhiva" i „Održavanje" nemaju prihvatnik,
                 pa se blok tamo sakriva.

                 Ovde je ISPISAN SA SERVERA, a ne samo prazan prihvatnik: dok
                 je stajao iznad tabova pa ga je skripta spuštala u karticu,
                 pregledač bi ga prvo iscrtao na starom mestu, pa tek onda na
                 novom — pretraga bi „zatreperila" iznad kartice pri svakom
                 otvaranju stranice. Ovaj tab je i podrazumevano otvoren, pa
                 premestiKontrole() na učitavanju zatekne blok gde treba i ne
                 pomera ništa. --}}
            <div class="controls-slot">
                <div class="controls-wrapper" id="rezervacijeKontrole">
                    <div class="controls-grid">
                        <div class="control-group">
                            <label>Pretraga po članu</label>
                            <input type="text" id="searchClan" placeholder="Ime, prezime ili broj kartice...">
                        </div>

                        <div class="control-group">
                            <label>Pretraga po knjizi</label>
                            <input type="text" id="searchKnjiga" placeholder="Naslov knjige...">
                        </div>

                        <div class="control-group">
                            <label>Sortiranje</label>
                            <select id="sortRezervacije">
                                <option value="datum_desc">Najnovije prvo</option>
                                <option value="datum_asc">Najstarije prvo</option>
                                <option value="clan_asc">Član A-Z</option>
                                <option value="clan_desc">Clan Z-A</option>
                                <option value="knjiga_asc">Knjiga A-Z</option>
                                <option value="knjiga_desc">Knjiga Z-A</option>
                            </select>
                        </div>

                        <div class="control-group">
                            <button id="resetFilters" class="btn-secondary">
                                <i class="fa-solid fa-rotate-right"></i> Resetuj filtere
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-header">
                <h2> <i class="fa-solid fa-clock-rotate-left"></i> Zahtevi za rezervaciju - Na čekanju</h2>
            </div>

            @include('admin.partials.otkazivanje-obavestenja', ['otkazane' => $otkazaneNaCekanju ?? collect()])

            @if(isset($naCekanju) && $naCekanju->count() > 0)
            <div class="table-responsive">
                <table class="data-table" data-paginate="10">
                    <thead>
                        <tr>
                            <th>Član</th>
                            <th>Knjiga</th>
                            <th>Datum zahteva</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($naCekanju as $rez)
                        <tr data-id="{{ $rez->id }}" id="rezervacija-{{ $rez->id }}">
                            <td>
                                <div class="user-cell">
                                    <strong>{{ $rez->user->ime }} {{ $rez->user->prezime }}</strong>
                                    <small>{{ $rez->user->email }}</small>
                                    <small>Kartica: {{ $rez->user->broj_clanske_karte ?? 'N/A' }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="book-cell">
                                    <strong>{{ $rez->knjiga->naziv_knjige }}</strong>
                                    <small>{{ $rez->knjiga->autor }}</small>
                                    @php
                                        $slobodni = $rez->knjiga->primerci->filter(
                                            fn($p) => (($p->status instanceof \App\Enums\PrimerakStatus) 
                                                ? $p->status->value 
                                                : $p->status) === 'slobodna'
                                        );

                                        // Broj OSTALIH zahteva na čekanju za istu knjigu
                                        $ostaliZahtevi = $naCekanju->where('knjiga_id', $rez->knjiga_id)
                                                                   ->where('id', '!=', $rez->id)
                                                                   ->count();
                                    @endphp
                                    @if($slobodni->count() > 0)
                                        <small style="color: #48bb78;">
                                            Slobodni inv. br: {{ $slobodni->pluck('inventarski_broj')->join(', ') }}
                                        </small>
                                    @else
                                        <small style="color: #f56565;">Nema slobodnih primeraka</small>
                                    @endif

                                    @if($ostaliZahtevi > 0)
                                        <small style="color: #ed8936; font-weight: 600;">
                                            ⚠️ Još {{ $ostaliZahtevi }} {{ $ostaliZahtevi == 1 ? 'zahtev' : 'zahteva' }} za ovu knjigu
                                        </small>
                                    @endif
                                </div>
                            </td>
                            <td>{{ $rez->datum_rezervacije->format('d.m.Y H:i') }}</td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" class="btn-approve" onclick="odobriRezervaciju({{ $rez->id }})">
                                        Odobri
                                    </button>
                                    <button type="button" class="btn-reject" onclick="odbijRezervaciju({{ $rez->id }})">
                                        Odbij
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="empty-state">
                <span class="empty-icon"> <i class="fa-solid fa-circle-check"></i> </span>
                <h3>Nema zahteva na čekanju</h3>
                <p>Trenutno nema novih zahteva za rezervaciju.</p>
            </div>
            @endif
        </div>
    </div>

    <!-- Tab 2: Odobrene -->
    <div id="tab_odobrene" class="tab-content">
        <div class="admin-card">
            <div class="controls-slot"></div>
            <div class="card-header">
                <h2> <i class="fa-solid fa-circle-check"></i> Odobrene rezervacije</h2>
            </div>

            @include('admin.partials.otkazivanje-obavestenja', ['otkazane' => $otkazaneOdobrene ?? collect()])

            @if(isset($odobrene) && $odobrene->count() > 0)
            <div class="table-responsive">
                <table class="data-table" data-paginate="10">
                    <thead>
                        <tr>
                            <th>Član</th>
                            <th>Knjiga</th>
                            <th>Inventarski broj</th>
                            <th>Datum odobrenja</th>
                            <th>Datum isteka</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody id="odobrene-tbody">
                        @foreach($odobrene as $rez)
                        @php
                            // Rok je istekao kada je zakazana komanda već prevela
                            // rezervaciju u status Istekla, ili kada je rok prošao
                            // a komanda još nije stigla da odradi svoj prolaz.
                            $istekla = $rez->jeIstekla()
                                || ($rez->rok_preuzimanja && $rez->rok_preuzimanja->isPast());
                        @endphp
                        <tr data-id="{{ $rez->id }}" id="odobrena-{{ $rez->id }}">
                            <td>
                                <div class="user-cell">
                                    <strong>{{ $rez->user->ime }} {{ $rez->user->prezime }}</strong>
                                    <small>Kartica: {{ $rez->user->broj_clanske_karte ?? 'N/A' }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="book-cell">
                                    <strong>{{ $rez->knjiga->naziv_knjige }}</strong>
                                    <small>{{ $rez->knjiga->autor }}</small>
                                    <!-- <small>{{ $rez->primerak ? $rez->primerak->inventarski_broj : 'N/A' }}</small> -->
                                </div>
                            </td>
                            <td>{{ $rez->primerak ? $rez->primerak->inventarski_broj : 'N/A' }}</td>
                            <td>{{ $rez->datum_odobrenja ? $rez->datum_odobrenja->format('d.m.Y H:i') : 'N/A' }}</td>
                            <td>
                                @if($rez->rok_preuzimanja)
                                @php
                                    $daysLeft = now()->diffInDays($rez->rok_preuzimanja, false);
                                @endphp
                                <span class="deadline {{ $istekla ? 'isteklo' : ($daysLeft <= 1 ? 'urgent' : '') }}">
                                    {{ $rez->rok_preuzimanja->format('d.m.Y') }}
                                    <small>({{ $istekla ? 'Rok je istekao' : ceil($daysLeft) . ' dana' }})</small>
                                </span>
                                @else
                                N/A
                                @endif
                            </td>
                            <td>
                                @if($istekla)
                                {{-- Rok je prošao: primerak je već oslobođen automatskim
                                     čišćenjem, pa je jedina akcija uklanjanje reda iz tabele. --}}
                                <button type="button" class="btn-delete-rezervacija" onclick="obrisiRezervaciju({{ $rez->id }}, 'odobrena')">
                                    <i class="fa-solid fa-trash-can"></i> Obriši
                                </button>
                                @else
                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <div style="display: flex; align-items: center; gap: 4px;">
                                        <button type="button" class="btn-dana-quick" onclick="document.getElementById('dani_{{ $rez->id }}').value=7" title="7 dana">7d</button>
                                        <button type="button" class="btn-dana-quick" onclick="document.getElementById('dani_{{ $rez->id }}').value=14" title="14 dana">14d</button>
                                        <button type="button" class="btn-dana-quick" onclick="document.getElementById('dani_{{ $rez->id }}').value=30" title="30 dana">30d</button>
                                        <input 
                                            type="number" 
                                            id="dani_{{ $rez->id }}" 
                                            value="14" 
                                            min="1" 
                                            max="90" 
                                            class="dana-input"
                                            title="Broj dana za vraćanje"
                                        >
                                    </div>
                                    <button type="button" class="btn-zaduzi" onclick="zaduziKnjigu({{ $rez->id }}, document.getElementById('dani_{{ $rez->id }}').value)">
                                        <i class="fa-solid fa-book"></i> Zaduži
                                    </button>
                                </div>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="empty-state">
                <span class="empty-icon"> <i class="fa-solid fa-circle-check"></i> </span>
                <h3>Nema odobrenih rezervacija</h3>
                <p>Sve odobrene rezervacije su već preuzete ili su istekle.</p>
            </div>
            @endif
        </div>
    </div>

    <!-- Tab 3: ODBIJENE -->
    <div id="tab_odbijene" class="tab-content">
        <div class="admin-card">
            <div class="controls-slot"></div>
            <div class="card-header">
                <h2> <i class="fa-solid fa-ban"></i> Odbijene rezervacije</h2>
            </div>

            @if(isset($odbijene) && $odbijene->count() > 0)
            <div class="table-responsive">
                <table class="data-table" data-paginate="10">
                    <thead>
                        <tr>
                            <th>Član</th>
                            <th>Knjiga</th>
                            <th>Datum zahteva</th>
                            <th>Datum odbijanja</th>
                            <th>Status</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody id="odbijene-tbody">
                        @foreach($odbijene as $rez)
                        <tr id="odbijena-{{ $rez->id }}">
                            <td>
                                <div class="user-cell">
                                    <strong>{{ $rez->user->ime }} {{ $rez->user->prezime }}</strong>
                                    <small>{{ $rez->user->email }}</small>
                                    <small>Kartica: {{ $rez->user->broj_clanske_karte ?? 'N/A' }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="book-cell">
                                    <strong>{{ $rez->knjiga->naziv_knjige }}</strong>
                                    <small>{{ $rez->knjiga->autor }}</small>
                                    <!-- <small>{{ $rez->knjiga->in_broj }}</small> -->
                                </div>
                            </td>
                            <td>{{ $rez->datum_rezervacije->format('d.m.Y H:i') }}</td>
                            <td>{{ $rez->datum_odobrenja ? $rez->datum_odobrenja->format('d.m.Y H:i') : 'N/A' }}</td>
                            <td>
                                <span class="status-badge odbijena"> <i class="fa-solid fa-ban"></i> Odbijeno</span>
                            </td>
                            <td>
                                <button type="button" class="btn-delete-rezervacija" onclick="obrisiRezervaciju({{ $rez->id }}, 'odbijena')">
                                    <i class="fa-solid fa-trash-can"></i> Obriši
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="empty-state">
                <span class="empty-icon"> <i class="fa-solid fa-circle-check"></i> </span>
                <h3>Nema odbijenih rezervacija</h3>
                <p>Još niste odbili nijednu rezervaciju.</p>
            </div>
            @endif
        </div>
    </div>

    <!-- Tab 4: Preuzete/Zadužene -->
    <div id="tab_preuzete" class="tab-content">
        <div class="admin-card">
            <div class="controls-slot"></div>
            <div class="card-header">
                <h2> <i class="fa-solid fa-book"></i> Zadužene knjige</h2>
            </div>

            @if(isset($preuzete) && $preuzete->count() > 0)
            <div class="table-responsive">
                <table class="data-table" data-paginate="10">
                    <thead>
                        <tr>
                            <th>Član</th>
                            <th>Knjiga</th>
                            <th>Inventarski broj</th>
                            <th>Datum zaduženja</th>
                            <th>Rok vraćanja</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody id="preuzete-tbody">
                        @foreach($preuzete as $rez)
                        @php
                            // Pronađi zaduženje za ovu rezervaciju
                            $zaduzivanje = App\Models\Zaduzivanje::where('primerak_id', $rez->primerak_id)
                                ->whereNull('datum_vracanja')
                                ->first();
                            
                            $danaPreostalo = null;
                            $satiPreostalo = null;
                            if ($zaduzivanje) {
                                $danaPreostalo = (int) ceil(now()->diffInDays($zaduzivanje->rok_vracanja, false));
                                $satiPreostalo = now()->diffInHours($zaduzivanje->rok_vracanja, false);
                            }
                        @endphp
                        <tr data-id="{{ $rez->id }}" id="preuzeta-{{ $rez->id }}">
                            <td>
                                <div class="user-cell">
                                    <strong>{{ $rez->user->ime }} {{ $rez->user->prezime }}</strong>
                                    <small>Kartica: {{ $rez->user->broj_clanske_karte ?? 'N/A' }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="book-cell">
                                    <strong>{{ $rez->knjiga->naziv_knjige }}</strong>
                                    <small>{{ $rez->knjiga->autor }}</small>
                                    <!-- <small>{{ $rez->knjiga->in_broj }}</small> -->
                                </div>
                            </td>
                            <td>{{ $rez->primerak ? $rez->primerak->inventarski_broj : 'N/A' }}</td>
                            <td>{{ $rez->datum_preuzimanja ? $rez->datum_preuzimanja->format('d.m.Y') : $rez->updated_at->format('d.m.Y') }}</td>
                            <td>
                                @if($zaduzivanje)
                                    <span 
                                    class="rok-badge {{ $satiPreostalo < 0 ? 'rok-isteklo' : ($satiPreostalo <= 48 ? 'rok-kritican' : ($danaPreostalo <= 5 ? 'rok-upozorenje' : 'rok-ok')) }}"
                                    data-rok="{{ $zaduzivanje->rok_vracanja->toIso8601String() }}"
                                    onmouseover="prikaziPreostaloVreme(this)"
                                    title=""
                                    >
                                       @if($satiPreostalo < 0)
                                           {{ $zaduzivanje->rok_vracanja->format('d.m.Y') }}
                                           <small>(Kašnjenje: {{ abs((int)$danaPreostalo) }} dana)</small>
                                        @elseif($satiPreostalo <= 48)
                                           ⚠️ Bliži se rok!
                                           <br>
                                           {{ $zaduzivanje->rok_vracanja->format('d.m.Y') }}
                                        @else
                                           {{ $zaduzivanje->rok_vracanja->format('d.m.Y') }}
                                           <small>({{ (int)$danaPreostalo }} {{ (int)$danaPreostalo == 1 ? 'dan' : 'dana' }})</small>
                                        @endif
                                    </span>
                                @else
                                    N/A
                                @endif
                            </td>
                            <td>
                                <button type="button" class="btn-vrati" onclick="vratiKnjigu({{ $rez->id }})">
                                     Vrati knjigu
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="empty-state">
                <span class="empty-icon"> <i class="fa-solid fa-circle-check"></i> </span>
                <h3>Nema zaduženih knjiga</h3>
                <p>Trenutno nema članova koji su preuzeli knjige.</p>
            </div>
            @endif
        </div>
    </div>

    <!-- Tab 5: Kašnjenja -->
    <div id="tab_kasnjenja" class="tab-content">
        <div class="admin-card">
            <div class="card-header">
                <h2> <i class="fa-solid fa-triangle-exclamation"></i> Kašnjenja sa vraćanjem</h2>
            </div>

            @if(isset($kasnjenja) && $kasnjenja->count() > 0)
            <div class="table-responsive">
                <table class="data-table" data-paginate="10">
                    <thead>
                        <tr>
                            <th>Član</th>
                            <th>Knjiga</th>
                            <th>Inventarski broj</th>
                            <th>Datum zaduženja</th>
                            <th>Rok vraćanja</th>
                            <th>Kasni (dana)</th>
                            <th>Akcije</th>
                        </tr>
                    </thead>
                    <tbody id="kasnjenja-tbody">
                        @foreach($kasnjenja as $k)
                        @php
                            $danaKasnjenja = round(now()->diffInDays($k->rok_vracanja));
                        @endphp
                        <tr class="kasnjenje-row" data-id="{{ $k->id }}" id="kasnjenje-{{ $k->id }}">
                            <td>
                                <div class="user-cell">
                                    <strong>{{ $k->user->ime }} {{ $k->user->prezime }}</strong>
                                    <small>Email: {{ $k->user->email }}</small>
                                    <small>Kartica: {{ $k->user->broj_clanske_karte ?? 'N/A' }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="book-cell">
                                    <strong>{{ $k->primerak->knjiga->naziv_knjige }}</strong>
                                    <small>{{ $k->primerak->knjiga->autor }}</small>
                                </div>
                            </td>
                            <td>{{ $k->primerak->inventarski_broj }}</td>
                            <td>{{ $k->datum_izdavanja->format('d.m.Y') }}</td>
                            <td class="urgent-text">{{ $k->rok_vracanja->format('d.m.Y') }}</td>
                            <td>
                                <span class="kasnjenje-badge {{ $danaKasnjenja > 7 ? 'kasnjenje-kritican' : '' }}">
                                    {{ $danaKasnjenja }} {{ $danaKasnjenja == 1 ? 'dan' : 'dana' }}
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn-vrati" onclick="vratiZaduzenje({{ $k->id }})">
                                    <i class="fa-solid fa-check"></i> Vrati knjigu
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="empty-state">
                <span class="empty-icon"> <i class="fa-solid fa-circle-check"></i> </span>
                <h3>Nema kašnjenja</h3>
                <p>Svi članovi su vratili knjige na vreme!</p>
            </div>
            @endif
        </div>
    </div>

    

    <!-- Tab 6: Arhiva -->
<div id="tab_arhiva" class="tab-content">
    <div class="admin-card">
        <div class="card-header">
            <h2><i class="fa-solid fa-box-archive"></i> Arhiva</h2>
        </div>

        @php
        $tipMap = [
        'Zahtev'  => ['label' => 'Na čekanju', 'color' => '#ed8936'],
        'Odobrena'=> ['label' => 'Odobrena',   'color' => '#48bb78'],
        'Odbijena'=> ['label' => 'Odbijena',   'color' => '#f56565'],
        'Zaduzena'=> ['label' => 'Zadužena',   'color' => '#4299e1'],
        'Vracena' => ['label' => 'Vraćena',    'color' => '#2d3748'],
        ];
        @endphp

        <!-- Pod-tabovi -->
        <div style="display: flex; gap: 8px; padding: 16px 16px 0 16px; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap;">
            <button class="arhiva-tab-btn active" onclick="showArhivaTab('arhiva_zahtevi', this)">
               <i class="fa-solid fa-clock-rotate-left"></i> Zahtevi ({{ isset($arhivaZahtevi) ? $arhivaZahtevi->count() : 0 }})
            </button>
            <button class="arhiva-tab-btn" onclick="showArhivaTab('arhiva_odobrene', this)">
               <i class="fa-solid fa-circle-check"></i> Odobrene ({{ isset($arhivaOdobrene) ? $arhivaOdobrene->count() : 0 }})
            </button>
            <button class="arhiva-tab-btn" onclick="showArhivaTab('arhiva_odbijene', this)">
               <i class="fa-solid fa-ban"></i> Odbijene ({{ isset($arhivaOdbijene) ? $arhivaOdbijene->count() : 0 }})
            </button>
            <button class="arhiva-tab-btn" onclick="showArhivaTab('arhiva_zaduzene', this)">
               <i class="fa-solid fa-book"></i> Zadužene ({{ isset($arhivaZaduzene) ? $arhivaZaduzene->count() : 0 }})
            </button>
            <button class="arhiva-tab-btn" onclick="showArhivaTab('arhiva_vracene', this)">
               <i class="fa-solid fa-rotate-left"></i> Vraćene ({{ isset($arhivaVracene) ? $arhivaVracene->count() : 0 }})
            </button>
        </div>



        <!-- Pod-tab: Zahtevi -->
<div id="arhiva_zahtevi" class="arhiva-tab-content">
    @if(isset($arhivaZahtevi) && $arhivaZahtevi->count() > 0)
    <div class="table-responsive">
        <table class="data-table" data-paginate="10">
            <thead>
                <tr>
                    <th>Član</th>
                    <th>Knjiga</th>
                    <th>Autor</th>
                    <th>Datum zahteva</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($arhivaZahtevi as $a)
                <tr>
                    <td>
                        <div class="user-cell">
                            <strong>{{ $a->ime_clana }}</strong>
                            <small>{{ $a->email_clana }}</small>
                            <small>Kartica: {{ $a->broj_clanske_karte ?? 'N/A' }}</small>
                        </div>
                    </td>
                    <td><strong>{{ $a->naziv_knjige }}</strong></td>
                    <td>{{ $a->autor ?? 'N/A' }}</td>
                    <td>{{ $a->datum_akcije->format('d.m.Y H:i') }}</td>
                    <td>
                      @php $t = $tipMap[$a->tip] ?? ['label' => $a->tip, 'color' => '#a0aec0']; @endphp
                      <span style="background: {{ $t['color'] }}; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                      {{ $t['label'] }}
                      </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="empty-state">
        <span class="empty-icon"><i class="fa-solid fa-box-archive"></i></span>
        <h3>Nema evidentiranih zahteva</h3>
    </div>
    @endif
</div>

<!-- Pod-tab: Odobrene -->
<div id="arhiva_odobrene" class="arhiva-tab-content" style="display:none;">
    @if(isset($arhivaOdobrene) && $arhivaOdobrene->count() > 0)
    <div class="table-responsive">
        <table class="data-table" data-paginate="10">
            <thead>
                <tr>
                    <th>Član</th>
                    <th>Knjiga</th>
                    <th>Autor</th>
                    <th>Datum odobrenja</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($arhivaOdobrene as $a)
                <tr>
                    <td>
                        <div class="user-cell">
                            <strong>{{ $a->ime_clana }}</strong>
                            <small>{{ $a->email_clana }}</small>
                            <small>Kartica: {{ $a->broj_clanske_karte ?? 'N/A' }}</small>
                        </div>
                    </td>
                    <td><strong>{{ $a->naziv_knjige }}</strong></td>
                    <td>{{ $a->autor ?? 'N/A' }}</td>
                    <td>{{ $a->datum_akcije->format('d.m.Y H:i') }}</td>
                    <td>
                      @php $t = $tipMap[$a->tip] ?? ['label' => $a->tip, 'color' => '#a0aec0']; @endphp
                      <span style="background: {{ $t['color'] }}; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                      {{ $t['label'] }}
                      </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="empty-state">
        <span class="empty-icon"><i class="fa-solid fa-circle-check"></i></span>
        <h3>Nema odobrenih rezervacija</h3>
    </div>
    @endif
</div>

<!-- Pod-tab: Odbijene -->
<div id="arhiva_odbijene" class="arhiva-tab-content" style="display:none;">
    @if(isset($arhivaOdbijene) && $arhivaOdbijene->count() > 0)
    <div class="table-responsive">
        <table class="data-table" data-paginate="10">
            <thead>
                <tr>
                    <th>Član</th>
                    <th>Knjiga</th>
                    <th>Autor</th>
                    <th>Datum odbijanja</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($arhivaOdbijene as $a)
                <tr>
                    <td>
                        <div class="user-cell">
                            <strong>{{ $a->ime_clana }}</strong>
                            <small>{{ $a->email_clana }}</small>
                            <small>Kartica: {{ $a->broj_clanske_karte ?? 'N/A' }}</small>
                        </div>
                    </td>
                    <td><strong>{{ $a->naziv_knjige }}</strong></td>
                    <td>{{ $a->autor ?? 'N/A' }}</td>
                    <td>{{ $a->datum_akcije->format('d.m.Y H:i') }}</td>
                    <td>
                      @php $t = $tipMap[$a->tip] ?? ['label' => $a->tip, 'color' => '#a0aec0']; @endphp
                      <span style="background: {{ $t['color'] }}; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                      {{ $t['label'] }}
                      </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="empty-state">
        <span class="empty-icon"><i class="fa-solid fa-ban"></i></span>
        <h3>Nema odbijenih rezervacija</h3>
    </div>
    @endif
</div>

<!-- Pod-tab: Zadužene -->
<div id="arhiva_zaduzene" class="arhiva-tab-content" style="display:none;">
    @if(isset($arhivaZaduzene) && $arhivaZaduzene->count() > 0)
    <div class="table-responsive">
        <table class="data-table" data-paginate="10">
            <thead>
                <tr>
                    <th>Član</th>
                    <th>Knjiga</th>
                    <th>Autor</th>
                    <th>Datum zaduženja</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($arhivaZaduzene as $a)
                <tr>
                    <td>
                        <div class="user-cell">
                            <strong>{{ $a->ime_clana }}</strong>
                            <small>{{ $a->email_clana }}</small>
                            <small>Kartica: {{ $a->broj_clanske_karte ?? 'N/A' }}</small>
                        </div>
                    </td>
                    <td><strong>{{ $a->naziv_knjige }}</strong></td>
                    <td>{{ $a->autor ?? 'N/A' }}</td>
                    <td>{{ $a->datum_akcije->format('d.m.Y H:i') }}</td>
                    <td>
                      @php $t = $tipMap[$a->tip] ?? ['label' => $a->tip, 'color' => '#a0aec0']; @endphp
                      <span style="background: {{ $t['color'] }}; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                      {{ $t['label'] }}
                      </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="empty-state">
        <span class="empty-icon"><i class="fa-solid fa-book"></i></span>
        <h3>Nema zaduženih knjiga</h3>
    </div>
    @endif
</div>

<!-- Pod-tab: Vraćene -->
<div id="arhiva_vracene" class="arhiva-tab-content" style="display:none;">
    @if(isset($arhivaVracene) && $arhivaVracene->count() > 0)
    <div class="table-responsive">
        <table class="data-table" data-paginate="10">
            <thead>
                <tr>
                    <th>Član</th>
                    <th>Knjiga</th>
                    <th>Autor</th>
                    <th>Datum vraćanja</th>
                    <th>Status</th>
                    <th>Na vreme</th>
                </tr>
            </thead>
            <tbody>
                @foreach($arhivaVracene as $a)
                <tr>
                    <td>
                        <div class="user-cell">
                            <strong>{{ $a->ime_clana }}</strong>
                            <small>{{ $a->email_clana }}</small>
                            <small>Kartica: {{ $a->broj_clanske_karte ?? 'N/A' }}</small>
                        </div>
                    </td>
                    <td><strong>{{ $a->naziv_knjige }}</strong></td>
                    <td>{{ $a->autor ?? 'N/A' }}</td>
                    <td>{{ $a->datum_akcije->format('d.m.Y H:i') }}</td>
                    <td>
                      @php $t = $tipMap[$a->tip] ?? ['label' => $a->tip, 'color' => '#a0aec0']; @endphp
                      <span style="background: {{ $t['color'] }}; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">
                      {{ $t['label'] }}
                      </span>
                    </td>
                    <td>
                    @if($a->rok_vracanja)
                    @php
                    $kasnjenje = $a->datum_akcije->diffInDays($a->rok_vracanja, false);
                    @endphp
                    @if($kasnjenje >= 0)
                    <span style="color: #48bb78; font-weight: 600;">
                    <i class="fa-solid fa-check"></i> Da
                    </span>
                    @else
                    <span style="color: #f56565; font-weight: 600;">
                    <i class="fa-solid fa-xmark"></i> Ne ({{ abs($kasnjenje) }} dana)
                    </span>
                    @endif
                    @else
                    <span style="color: #a0aec0;">N/A</span>
                    @endif
                   </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="empty-state">
        <span class="empty-icon"><i class="fa-solid fa-rotate-left"></i></span>
        <h3>Nema vraćenih knjiga</h3>
    </div>
    @endif
</div>

    </div>
</div>

    <!-- INFO BOX -->
    <div class="info-box">
        <h3> <i class="fa-solid fa-triangle-exclamation"></i> Napomena</h3>
        <ul>
            <li><strong>Na čekanju:</strong> Novi zahtevi koji čekaju vašu odluku.</li>
            <li><strong>Odobrene:</strong> Rezervacije koje ste odobrili. Član ima <strong>5 dana</strong> da preuzme knjigu.</li>
            <li><strong>Odbijene:</strong> Rezervacije koje ste odbili. Možete ih obrisati kada više nisu potrebne.</li>
            <li><strong>Zadužene:</strong> Knjige koje su članovi fizički preuzeli.</li>
            <li>Član može imati <strong>samo jednu aktivnu rezervaciju</strong> ili zaduženu knjigu.</li>
            <li>Kada član preuzme knjigu, kliknite <strong>"Zaduži"</strong> - red se automatski briše.</li>
            <li>Kada član vrati knjigu, kliknite <strong>"Vrati knjigu"</strong> - primerak postaje dostupan i red se briše.</li>
        </ul>
    </div>

</div>

<!--footer-->

@endsection

@push('scripts')
<script src="{{ asset_v('js/knjige-rezervacije.js') }}"></script>

<script>

// Bibliotekar/admin „pročita" obaveštenje da je korisnik otkazao rezervaciju
async function oznaciOtkazivanjeVidjeno(id) {
    try {
        const res = await fetch(`/api/rezervacije/${id}/otkazivanje-vidjeno`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            credentials: 'same-origin'
        });

        if (!res.ok) {
            // Ne ćuti — prikaži status (npr. 404 = keš ruta zastareo → php artisan route:clear).
            showToast('Greška ' + res.status + ' pri označavanju obaveštenja.', 'error');
            return;
        }

        const data = await res.json();
        if (data.success) {
            document.getElementById(`otkazivanje-${id}`)?.remove();
        }
    } catch (e) {
        showToast('Greška pri označavanju obaveštenja: ' + e.message, 'error');
    }
}

// Cleanup funkcija za rezervisane primerke bez aktivne rezervacije
async function cleanupRezervacije() {
    const resultDiv = document.getElementById('cleanup-result');
    resultDiv.innerHTML = '<p style="color: #007bff;"><i class="fa-solid fa-spinner fa-spin"></i> Čišćenje u toku...</p>';
    
    try {
        const response = await fetch('/api/admin/cleanup-rezervacije', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        // Proveri HTTP status kod
        if (!response.ok) {
            resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--greska">
                <strong><i class="fa-solid fa-exclamation-triangle"></i> Greška ${response.status}:</strong> ${data.message || 'Nepoznata greška'}
                <br><small>Ako kaže da morate biti prijavljeni, osvežite stranicu (F5) i pokušajte ponovo.</small>
            </div>`;
            return;
        }
        
        if (data.success) {
            let html = `<div class="maintenance-poruka maintenance-poruka--uspeh">
                <strong><i class="fa-solid fa-check-circle"></i> ${data.message}</strong><br>`;
            
            if (data.ocisceni.length > 0) {
                html += '<br><strong>Očišćeni primerci:</strong><ul style="margin: 0.5rem 0;">';
                data.ocisceni.forEach(p => {
                    html += `<li>${p.knjiga} - Inv. br: ${p.inventarski_broj}</li>`;
                });
                html += '</ul>';
            }
            
            if (data.preskoceni.length > 0) {
                html += '<br><strong>Preskočeni (imaju aktivnu rezervaciju):</strong><ul style="margin: 0.5rem 0;">';
                data.preskoceni.forEach(p => {
                    html += `<li>${p.knjiga} - Inv. br: ${p.inventarski_broj} (Rez. ID: ${p.rezervacija_id})</li>`;
                });
                html += '</ul>';
            }
            
            html += '</div>';
            resultDiv.innerHTML = html;
        } else {
            resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--greska">
                <strong><i class="fa-solid fa-exclamation-triangle"></i> Greška:</strong> ${data.message}
            </div>`;
        }
    } catch (error) {
        resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--greska">
            <strong><i class="fa-solid fa-exclamation-triangle"></i> Greška:</strong> ${error.message}
        </div>`;
    }
}

// Cleanup funkcija za istekle rezervacije
async function cleanupIstekle() {
    const resultDiv = document.getElementById('cleanup-istekle-result');
    resultDiv.innerHTML = '<p class="maintenance-tok"><i class="fa-solid fa-spinner fa-spin"></i> Čišćenje u toku...</p>';
    
    try {
        const response = await fetch('/api/admin/cleanup-istekle-rezervacije', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        // Proveri HTTP status kod
        if (!response.ok) {
            resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--greska">
                <strong><i class="fa-solid fa-exclamation-triangle"></i> Greška ${response.status}:</strong> ${data.message || 'Nepoznata greška'}
                <br><small>Ako kaže da morate biti prijavljeni, osvežite stranicu (F5) i pokušajte ponovo.</small>
            </div>`;
            return;
        }
        
        if (data.success) {
            let html = `<div class="maintenance-poruka maintenance-poruka--upozorenje">
                <strong><i class="fa-solid fa-check-circle"></i> ${data.message}</strong><br>`;
            
            if (data.ociscene.length > 0) {
                html += '<br><strong>Istekle rezervacije:</strong><ul style="margin: 0.5rem 0;">';
                data.ociscene.forEach(r => {
                    html += `<li>${r.knjiga} - ${r.korisnik} (Rok: ${r.rok_preuzimanja})</li>`;
                });
                html += '</ul>';
            } else {
                html += '<br>Nema isteklih rezervacija za čišćenje.';
            }
            
            html += '</div>';
            resultDiv.innerHTML = html;
            
            // Osveži stranicu nakon 3 sekunde
            if (data.ociscene.length > 0) {
                setTimeout(() => {
                    location.reload();
                }, 3000);
            }
        } else {
            resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--greska">
                <strong><i class="fa-solid fa-exclamation-triangle"></i> Greška:</strong> ${data.message}
            </div>`;
        }
    } catch (error) {
        resultDiv.innerHTML = `<div class="maintenance-poruka maintenance-poruka--greska">
            <strong><i class="fa-solid fa-exclamation-triangle"></i> Greška:</strong> ${error.message}
        </div>`;
    }
}

// ============================================
// PRETRAGA I SORTIRANJE REZERVACIJA
// ============================================

let originalData = {
    na_cekanju: [],
    odobrene: [],
    odbijene: [],
    preuzete: [],
    kasnjenja: []
};

// Čuvanje originalnih podataka pri učitavanju stranice
document.addEventListener('DOMContentLoaded', function() {
    // Na čekanju
    document.querySelectorAll('#tab_na_cekanju tbody tr').forEach(row => {
        originalData.na_cekanju.push({
            element: row.cloneNode(true),
            clan: row.querySelector('.user-cell strong')?.textContent.toLowerCase() || '',
            email: row.querySelector('.user-cell small:nth-child(2)')?.textContent.toLowerCase() || '',
            kartica: row.querySelector('.user-cell small:nth-child(3)')?.textContent.toLowerCase() || '',
            knjiga: row.querySelector('.book-cell strong')?.textContent.toLowerCase() || '',
            datum: row.querySelector('td:nth-child(3)')?.textContent || ''
        });
    });

    // Odobrene
    document.querySelectorAll('#tab_odobrene tbody tr').forEach(row => {
        originalData.odobrene.push({
            element: row.cloneNode(true),
            clan: row.querySelector('.user-cell strong')?.textContent.toLowerCase() || '',
            kartica: row.querySelector('.user-cell small:nth-child(2)')?.textContent.toLowerCase() || '',
            knjiga: row.querySelector('.book-cell strong')?.textContent.toLowerCase() || '',
            datum: row.querySelector('td:nth-child(4)')?.textContent || ''
        });
    });

    // Odbijene
    document.querySelectorAll('#tab_odbijene tbody tr').forEach(row => {
        originalData.odbijene.push({
            element: row.cloneNode(true),
            clan: row.querySelector('.user-cell strong')?.textContent.toLowerCase() || '',
            email: row.querySelector('.user-cell small:nth-child(2)')?.textContent.toLowerCase() || '',
            kartica: row.querySelector('.user-cell small:nth-child(3)')?.textContent.toLowerCase() || '',
            knjiga: row.querySelector('.book-cell strong')?.textContent.toLowerCase() || '',
            datum: row.querySelector('td:nth-child(4)')?.textContent || ''
        });
    });

    // Preuzete
    document.querySelectorAll('#tab_preuzete tbody tr').forEach(row => {
        originalData.preuzete.push({
            element: row.cloneNode(true),
            clan: row.querySelector('.user-cell strong')?.textContent.toLowerCase() || '',
            kartica: row.querySelector('.user-cell small:nth-child(2)')?.textContent.toLowerCase() || '',
            knjiga: row.querySelector('.book-cell strong')?.textContent.toLowerCase() || '',
            datum: row.querySelector('td:nth-child(4)')?.textContent || ''
        });
    });

    // Kašnjenja
    document.querySelectorAll('#tab_kasnjenja tbody tr').forEach(row => {
        originalData.kasnjenja.push({
            element: row.cloneNode(true),
            clan: row.querySelector('.user-cell strong')?.textContent.toLowerCase() || '',
            kartica: row.querySelector('.user-cell small:nth-child(3)')?.textContent.toLowerCase() || '',
            knjiga: row.querySelector('.book-cell strong')?.textContent.toLowerCase() || '',
            datum: row.querySelector('td:nth-child(4)')?.textContent || ''
        });
    });

    // Event listeneri
    document.getElementById('searchClan')?.addEventListener('input', applyFilters);
    document.getElementById('searchKnjiga')?.addEventListener('input', applyFilters);
    document.getElementById('sortRezervacije')?.addEventListener('change', applyFilters);
    document.getElementById('resetFilters')?.addEventListener('click', resetFilters);
});

function applyFilters() {
    const fold = window.foldSearch || (s => (s || '').toLowerCase());
    const searchClan = fold(document.getElementById('searchClan')?.value || '');
    const searchKnjiga = fold(document.getElementById('searchKnjiga')?.value || '');
    const sortValue = document.getElementById('sortRezervacije')?.value || 'datum_desc';

    // Filtriraj i sortiraj sve tabove
    ['na_cekanju', 'odobrene', 'odbijene', 'preuzete', 'kasnjenja'].forEach(tab => {
        filterAndSortTab(tab, searchClan, searchKnjiga, sortValue);
    });
}

function filterAndSortTab(tabName, searchClan, searchKnjiga, sortValue) {
    let data = [...originalData[tabName]];

    // FILTRIRANJE (normalizovano: nezavisno od pisma i dijakritika)
    const fold = window.foldSearch || (s => (s || '').toLowerCase());
    data = data.filter(item => {
        const clanMatch = !searchClan ||
            fold(item.clan).includes(searchClan) ||
            fold(item.kartica).includes(searchClan) ||
            (item.email && fold(item.email).includes(searchClan));

        const knjigaMatch = !searchKnjiga ||
            fold(item.knjiga).includes(searchKnjiga);

        return clanMatch && knjigaMatch;
    });

    // SORTIRANJE
    data.sort((a, b) => {
        switch(sortValue) {
            case 'datum_asc':
                return new Date(parseDate(a.datum)) - new Date(parseDate(b.datum));
            case 'datum_desc':
                return new Date(parseDate(b.datum)) - new Date(parseDate(a.datum));
            case 'clan_asc':
                return a.clan.localeCompare(b.clan, 'sr');
            case 'clan_desc':
                return b.clan.localeCompare(a.clan, 'sr');
            case 'knjiga_asc':
                return a.knjiga.localeCompare(b.knjiga, 'sr');
            case 'knjiga_desc':
                return b.knjiga.localeCompare(a.knjiga, 'sr');
            default:
                return 0;
        }
    });

    // Ažuriraj tabelu
    updateTable(tabName, data);
}

function parseDate(dateStr) {
    // Format: "16.02.2025 14:30" ili "16.02.2025"
    const parts = dateStr.trim().split(' ')[0].split('.');
    if (parts.length === 3) {
        return `${parts[2]}-${parts[1]}-${parts[0]}`;
    }
    return dateStr;
}

function updateTable(tabName, data) {
    const tbody = document.querySelector(`#tab_${tabName} tbody`);
    const emptyState = document.querySelector(`#tab_${tabName} .empty-state`);
    const tableResponsive = document.querySelector(`#tab_${tabName} .table-responsive`);

    if (!tbody) return;

    // Očisti tabelu
    tbody.innerHTML = '';

    if (data.length === 0) {
        // Prikaži prazan state
        if (tableResponsive) tableResponsive.style.display = 'none';
        if (emptyState) {
            emptyState.style.display = 'block';
            // Proveri da li je pretraga aktivna
            const searchClan = document.getElementById('searchClan')?.value || '';
            const searchKnjiga = document.getElementById('searchKnjiga')?.value || '';
            
            if (searchClan || searchKnjiga) {
                // Ako je pretraga aktivna, prikaži odgovarajuću poruku
                emptyState.innerHTML = `
                    <div class="search-empty-state">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <h4>Nema rezultata pretrage</h4>
                        <p>Pokušajte sa drugačijim kriterijumima pretrage.</p>
                    </div>
                `;
            } else {
                // Originalna poruka ako nema pretrage
                const originalMessage = getOriginalEmptyMessage(tabName);
                emptyState.innerHTML = originalMessage;
            }
        }
    } else {
        // Prikaži tabelu
        if (tableResponsive) tableResponsive.style.display = 'block';
        if (emptyState) emptyState.style.display = 'none';

        // Dodaj redove
        data.forEach(item => {
            const clonedRow = item.element.cloneNode(true);
            reattachEventListeners(clonedRow, tabName);
            tbody.appendChild(clonedRow);
        });
    }

    // Ažuriraj brojač u tabu
    updateTabCount(tabName, data.length);
}

function getOriginalEmptyMessage(tabName) {
    const messages = {
        'na_cekanju': `
            <span class="empty-icon"><i class="fa-solid fa-envelopes-bulk"></i></span>
            <h3>Nema zahteva na čekanju</h3>
            <p>Trenutno nema novih zahteva za rezervaciju.</p>
        `,
        'odobrene': `
            <span class="empty-icon"><i class="fa-solid fa-circle-check"></i></span>
            <h3>Nema odobrenih rezervacija</h3>
            <p>Sve odobrene rezervacije su već preuzete ili su istekle.</p>
        `,
        'odbijene': `
            <span class="empty-icon"><i class="fa-solid fa-circle-check"></i></span>
            <h3>Nema odbijenih rezervacija</h3>
            <p>Još niste odbili nijednu rezervaciju.</p>
        `,
        'preuzete': `
            <span class="empty-icon"><i class="fa-solid fa-circle-check"></i></span>
            <h3>Nema zaduženih knjiga</h3>
            <p>Trenutno nema članova koji su preuzeli knjige.</p>
        `,
        'kasnjenja': `
            <span class="empty-icon"><i class="fa-solid fa-circle-check"></i></span>
            <h3>Nema kašnjenja</h3>
            <p>Svi članovi su vratili knjige na vreme! </p>
        `
    };
    
    return messages[tabName] || '';
}

function reattachEventListeners(row, tabName) {
    const rezervacijaId = row.getAttribute('data-id');
    
    // Odobri dugme (Na čekanju)
    const odobriBtn = row.querySelector('.btn-approve');
    if (odobriBtn) {
        odobriBtn.onclick = () => odobriRezervaciju(rezervacijaId);
    }

    // Odbij dugme (Na čekanju)
    const odbijBtn = row.querySelector('.btn-reject');
    if (odbijBtn) {
        odbijBtn.onclick = () => odbijRezervaciju(rezervacijaId);
    }

    // Zaduži dugme (Odobrene)
    const zaduziBtn = row.querySelector('.btn-zaduzi');
    if (zaduziBtn) {
        const daniInput = row.querySelector(`#dani_${rezervacijaId}`);
        zaduziBtn.onclick = () => zaduziKnjigu(rezervacijaId, daniInput?.value || 14);
    }

    // Quick buttons za dane
    row.querySelectorAll('.btn-dana-quick').forEach(btn => {
        const targetValue = btn.getAttribute('onclick').match(/value=(\d+)/)[1];
        btn.onclick = () => {
            const input = row.querySelector(`#dani_${rezervacijaId}`);
            if (input) input.value = targetValue;
        };
    });

    // Vrati dugme (Preuzete & Kašnjenja)
    const vratiBtn = row.querySelector('.btn-vrati');
    if (vratiBtn) {
        if (tabName === 'kasnjenja') {
            const zaduzivanjeId = row.getAttribute('data-id');
            vratiBtn.onclick = () => vratiZaduzenje(zaduzivanjeId);
        } else {
            vratiBtn.onclick = () => vratiKnjigu(rezervacijaId);
        }
    }

    // Obriši dugme (Odbijene)
    const obrisiBtn = row.querySelector('.btn-delete-rezervacija');
    if (obrisiBtn) {
        obrisiBtn.onclick = () => obrisiRezervaciju(rezervacijaId, 'odbijena');
    }
}

function updateTabCount(tabName, count) {
    const tabBtn = document.querySelector(`button[onclick="showTab('${tabName}')"]`);
    if (tabBtn) {
        const countSpan = tabBtn.querySelector('.tab-count');
        if (countSpan) {
            countSpan.textContent = count;
        }
    }
}

function resetFilters() {
    document.getElementById('searchClan').value = '';
    document.getElementById('searchKnjiga').value = '';
    document.getElementById('sortRezervacije').value = 'datum_desc';
    applyFilters();
}

/* potvrdiOdjavu je globalizovan u notifications.js */

function showArhivaTab(tabId, btn) {
    document.querySelectorAll('.arhiva-tab-content').forEach(t => t.style.display = 'none');
    document.querySelectorAll('.arhiva-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).style.display = 'block';
    btn.classList.add('active');
}

</script>
@endpush
