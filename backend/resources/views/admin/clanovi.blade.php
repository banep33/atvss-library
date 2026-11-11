@extends('layouts.admin')

@section('title', 'Upravljanje članovima')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/admin.css') }}">
@endpush

@section('content')

<div class="admin-container">

    <div class="admin-card">
        {{-- Pretraga stoji U KARTICI spiska, iznad naslova sekcije. Ranije je
             bila zasebna kartica iznad ove, pa su dva okvira jedan ispod
             drugog delovala kao dve nepovezane celine. --}}
        <div class="controls-slot">
            <div class="controls-wrapper">
                <div class="controls-grid" style="grid-template-columns: 1fr auto;">
                    <div class="control-group">
                        <label>Pretraga članova</label>
                        <input type="text" id="searchClanovi" placeholder="Ime, prezime ili broj kartice...">
                    </div>
                    <div class="control-group">
                        <button id="resetClanovi" class="btn-secondary">
                            <i class="fa-solid fa-rotate-right"></i> Resetuj
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-header">
            <h2> <i class="fa-duotone fa-solid fa-clipboard-list"></i> Lista članova</h2>
            <button class="btn-add" onclick="openAddModal()">+ Dodaj člana</button>
        </div>

        <div class="table-responsive">
            <table class="data-table data-table--clanovi" data-paginate="10">
                <thead>
                    <tr>
                        <th>Ime i prezime</th>
                        <th>Email</th>
                        <th>Broj kartice</th>
                        <th>Telefon</th>
                        <th>Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($clanovi as $clan)
                    <tr 
                        data-ime="{{ strtolower($clan->ime . ' ' . $clan->prezime) }}"
                        data-kartica="{{ strtolower($clan->broj_clanske_karte ?? '') }}"
                    >
                        <td><strong>{{ $clan->ime }} {{ $clan->prezime }}</strong></td>
                        <td>{{ $clan->email }}</td>
                        <td><code>{{ $clan->broj_clanske_karte }}</code></td>
                        <td>{{ $clan->telefon ?? '/' }}</td>
                        <td>
                            <button class="btn-edit-small" onclick="openEditClanModal({{ $clan->id }}, '{{ $clan->ime }}', '{{ $clan->prezime }}', '{{ $clan->email }}', '{{ $clan->telefon ?? '' }}')">
                                 <i class="fa-solid fa-pen"></i> Izmeni
                            </button>
                            <button class="btn-delete-small" onclick="deleteClan({{ $clan->id }})">
                                <i class="fa-solid fa-trash-can"></i> Obriši
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="pagination-custom">
            {{ $clanovi->links() }}
        </div>
    </div>

</div>

<!-- MODAL ZA DODAVANJE CLANA -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Dodaj novog člana</h2>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>

        {{-- novalidate: proveru radi proveriObavezna() iz notifications.js —
             poruke idu ispod polja na srpskom, umesto engleskog „balončića". --}}
        <form id="clanForm" novalidate>
            <div class="form-grid">
                <div class="form-group">
                    <label>Ime *</label>
                    <input type="text" id="ime" required>
                </div>

                <div class="form-group">
                    <label>Prezime *</label>
                    <input type="text" id="prezime" required>
                </div>

                <div class="form-group full-width">
                    <label>Email *</label>
                    <input type="email" id="email" required>
                </div>

                <div class="form-group full-width">
                    <label>Lozinka *</label>
                    {{-- minlength prati PasswordPolicy (8); ranije je stajalo 6,
                         pa je unos od 6 znakova prolazio klijent a padao na serveru --}}
                    <input type="password" id="password" required minlength="8">
                    <small>Najmanje 8 znakova, bar jedno slovo i jedna cifra</small>
                </div>

                <div class="form-group">
                    <label>Telefon</label>
                    {{-- data-telefon uključuje proveru formata iz notifications.js:
                         bez slova, +381… najviše 13 znakova, 06… najmanje 10.
                         Poruka ide ispod polja, isto kao kod e-pošte. --}}
                    <input type="tel" id="telefon" placeholder="+381..." data-telefon inputmode="tel">
                </div>

                <div class="form-group">
                    <label>Adresa</label>
                    <input type="text" id="adresa">
                </div>
            </div>

            <div class="info-note">
                <i class="fa-solid fa-triangle-exclamation"></i> Broj članske karte biće automatski generisan.
            </div>

            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeModal()">Otkaži</button>
                <button type="submit" class="btn-save">Sačuvaj</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL ZA IZMENU CLANA -->
 <div id="editClanModal" class="modal">
    <div class="modal-content-small">
        <div class="modal-header">
            <h2>Izmeni člana</h2>
            <button class="close-btn" onclick="closeEditClanModal()">&times;</button>
        </div>
        <form id="editClanForm" novalidate>
            <input type="hidden" id="editClanId">
            <div class="form-group">
                <label>Ime <span class="obavezno">*</span></label>
                <input type="text" id="editIme" required>
            </div>
            <div class="form-group">
                <label>Prezime <span class="obavezno">*</span></label>
                <input type="text" id="editPrezime" required>
            </div>
            <div class="form-group">
                <label>Email <span class="obavezno">*</span></label>
                <input type="email" id="editEmail" required>
            </div>
            <div class="form-group">
                <label>Nova lozinka</label>
                <input type="password" id="editPassword" minlength="8">
                <small>Ostavi prazno ako ne menjaš lozinku; inače najmanje 8 znakova,
                       bar jedno slovo i jedna cifra</small>
            </div>
            <div class="form-group">
                <label>Telefon</label>
                <input type="tel" id="editTelefon" placeholder="+381..." data-telefon inputmode="tel">
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeEditClanModal()">Otkaži</button>
                <button type="submit" class="btn-save">Sačuvaj</button>
            </div>
        </form>
    </div>
</div>

<!--footer-->

@endsection

@push('scripts')
<script src="{{ asset_v('js/biblioteka-clanovi.js') }}"></script>
@endpush
