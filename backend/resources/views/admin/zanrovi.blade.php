@extends('layouts.admin')

@section('title', 'Upravljanje žanrovima')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/admin.css') }}">
@endpush

@section('content')

<div class="admin-container">

    <div class="admin-card">
        <div class="card-header">
            <h2><i class="fa-solid fa-book"></i> Lista žanrova</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <input 
                    type="text" 
                    id="searchZanr" 
                    placeholder="Pretraži žanrove..." 
                    style="padding: 8px 16px; border: 1px solid #cbd5e0; border-radius: 6px; font-size: 14px; font-family: 'Inter', sans-serif; width: 220px;"
                    oninput="filterZanrovi()"
                >
                <button class="btn-add" onclick="openAddModal()">+ Dodaj žanr</button>
            </div>
        </div>

        @if($zanrovi->isEmpty())
        <div class="empty-state">
            <span class="empty-icon"><i class="fa-solid fa-book"></i></span>
            <h3>Nema dodanih žanrova</h3>
            <p>Kliknite na "Dodaj žanr" da biste kreirali prvi žanr.</p>
        </div>
        @else
        {{-- Žanrovi stižu iz kontrolera već poređani abecedno (srpska latinica).
             data-paginate uključuje zajedničku klijentsku paginaciju
             (public/js/pagination.js): „Prikaži N zapisa" + numerisani pager. --}}
        <div class="table-responsive">
            <table class="data-table" data-paginate="10">
                <thead>
                    <tr>
                        <th>Žanr</th>
                        <th>Broj knjiga u žanru</th>
                        <th>Akcije</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($zanrovi as $zanr)
                    <tr data-naziv="{{ mb_strtolower($zanr->naziv, 'UTF-8') }}">
                        <td><strong>{{ $zanr->naziv }}</strong></td>
                        <td>{{ $zanr->knjige_count }}</td>
                        <td>
                            <div class="table-akcije">
                                <a href="{{ route('zanrovi.knjige', $zanr->id) }}" class="btn-view-small">
                                    <i class="fa-solid fa-eye"></i> Pogledaj
                                </a>
                                <button class="btn-delete-small" onclick="deleteZanr({{ $zanr->id }}, @js($zanr->naziv))">
                                    <i class="fa-solid fa-trash-can"></i> Obriši
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

</div>

<!-- MODAL ZA DODAVANJE ZANRA -->
<div id="addModal" class="modal">
    <div class="modal-content-small">
        <div class="modal-header">
            <h2>Dodaj novi žanr</h2>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>

        <form id="zanrForm">
            <div class="form-group">
                <label>Naziv žanra *</label>
                <input type="text" id="naziv" placeholder="npr. Naučna fantastika" required autofocus>
            </div>

            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeModal()">Otkaži</button>
                <button type="submit" class="btn-save">Sačuvaj</button>
            </div>
        </form>
    </div>
</div>

<!--footer-->

@endsection

@push('scripts')
<script src="{{ asset_v('js/knjige-zanrovi.js') }}"></script>
@endpush
