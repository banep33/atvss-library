@extends('layouts.app')

@section('title', 'Biblioteka - Knjige')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/knjige-index.css') }}">
@endpush

@section('content')

{{-- ═══════════════════════════════════════════════════════════════════
     KATALOG — jedna kartica, četiri celine jedna ispod druge:
       1. pretraga i filtriranje,
       2. najčitanije knjige,
       3. najnovije knjige,
       4. ostale knjige.

     Ranije su to bile TRI odvojene kartice (kontrole, „ostale knjige",
     pa featured sekcije), pri čemu su featured stajale ISPOD ostalih i
     imale sopstveni vodoravni skrol. Sada je sve u jednom okviru, u
     traženom redosledu, a skroluje se cela stranica.

     Paginacije nema: katalog je mali (stotine naslova), pa se prikazuju
     sve knjige odjednom (`per_page` u knjige-index.js).
     ═══════════════════════════════════════════════════════════════════ --}}
<div class="katalog">
<div class="katalog-kartica">

    {{-- 1. PRETRAGA I FILTRIRANJE — u istoj kartici, iznad naslova prve
         sekcije; `.controls-slot` skida sopstveni okvir bloka (admin.css)
         da se dve kartice ne ugnežđuju. --}}
    <div class="controls-slot">
        <div class="controls-wrapper">
            <div class="controls-grid">
                <div class="control-group">
                    <label>Pretraga po naslovu / ključnoj reči</label>
                    <input type="text" id="searchInput" placeholder="Unesite naziv ili ključnu reč...">
                </div>

                <div class="control-group">
                    <label>Pretraga po autoru</label>
                    <input type="text" id="searchAutor" placeholder="Unesite ime autora...">
                </div>

                <div class="control-group">
                    <label>Filter po žanru</label>
                    <select id="filterZanr">
                        <option value="">Svi žanrovi</option>
                    </select>
                </div>

                <div class="control-group">
                    <label>Sortiranje</label>
                    <select id="sortSelect">
                        <option value="">Bez sortiranja</option>
                        <option value="year_asc">Godina rastuće </option>
                        <option value="year_desc">Godina opadajuće </option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- REZULTATI PRETRAGE — zamenjuju sve tri sekcije ispod dok je bilo koji
         filter aktivan, da se ista knjiga ne pojavi dva puta. --}}
    <div class="katalog-sekcija" id="searchResultsSection" style="display:none;">
        <div class="katalog-sekcija__zaglavlje">
            <h2><i class="fa-solid fa-magnifying-glass"></i> Rezultati pretrage</h2>
            <span class="search-results-count" id="searchResultsCount"></span>
        </div>

        <div id="searchResultsLoading" style="display:none;">Učitavanje...</div>
        <div id="searchResultsEmpty" style="display:none;">Nema pronađenih knjiga.</div>

        <div id="searchResultsContent" style="display:none;">
            <div class="book-grid" id="searchResultsGrid"></div>
        </div>
    </div>

    {{-- 2. i 3. NAJČITANIJE pa NAJNOVIJE

         Obe sekcije su ISTAKNUTE: stoje u sopstvenom okviru unutar kartice
         kataloga (`.katalog-sekcija--istaknuta`), u istim tonovima kao i
         ostatak aplikacije — bez sopstvene boje podloge. Vezu sa oznakama
         „Najčitanija" / „Najnovija" na karticama u „Ostalim knjigama" nose
         sami bedževi.

         Kartice su ovde MANJE nego u „Ostalim knjigama" (`.featured-grid`,
         ~140px) i stoje u JEDNOM redu — kada ne stanu u širinu sekcije, red
         se skroluje levo-desno umesto da se prelama nadole. --}}
    <div id="featuredSections">

        <div class="katalog-sekcija katalog-sekcija--istaknuta">
            <div class="katalog-sekcija__zaglavlje">
                <h2><i class="fa-solid fa-fire"></i> Najčitanije knjige</h2>
            </div>
            <div class="featured-grid" id="najcitanijeGrid">
                <div class="featured-loading">Učitavanje...</div>
            </div>
        </div>

        <div class="katalog-sekcija katalog-sekcija--istaknuta">
            <div class="katalog-sekcija__zaglavlje">
                <h2><i class="fa-solid fa-star"></i> Najnovije knjige</h2>
                @if($user && $user->isAdminOrBibliotekar())
                <button class="featured-manage-btn" onclick="openNajnovijeModal()">
                    <i class="fa-solid fa-pen-to-square"></i> Upravljaj
                </button>
                @endif
            </div>
            <div class="featured-grid" id="najnovijeGrid">
                <p class="featured-empty">Nema dodatih knjiga.</p>
            </div>
        </div>

    </div>

    {{-- 4. OSTALE KNJIGE --}}
    <div class="katalog-sekcija" id="booksSection">
        <div class="katalog-sekcija__zaglavlje">
            <h2><i class="fa-solid fa-book"></i> Ostale knjige</h2>
        </div>

        <div id="loading">Učitavanje knjiga...</div>
        <div id="no-results">Nema pronađenih knjiga.</div>

        <div id="content" style="display:none;">
            <div class="book-grid" id="bookGrid"></div>
        </div>
    </div>

</div>
</div>

<!-- MODAL ZA UPRAVLJANJE NAJNOVIJIM -->
<div id="najnovijeModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h2>Upravljaj najnovijim knjigama</h2>
            <button class="close-btn" onclick="closeNajnovijeModal()">&times;</button>
        </div>

        <div style="margin-bottom: 16px;">
            <input type="text" id="najnovijeSearch" placeholder="Pretraži knjige..." 
                style="width:100%; padding:10px; border:1px solid #ddd; border-radius:8px; font-size:14px;">
        </div>

        <div id="najnovijeListaKnjiga" style="max-height: 450px; overflow-y: auto;">
            <div class="featured-loading">Učitavanje knjiga...</div>
        </div>

        <div class="form-actions" style="margin-top: 16px;">
            <button class="btn-cancel" onclick="closeNajnovijeModal()">Zatvori</button>
        </div>
    </div>
</div>

<!--footer-->

@endsection

@push('scripts')
<script>
    const isAdmin = {{ $user && $user->isAdminOrBibliotekar() ? 'true' : 'false' }};
    const userId = {{ $user ? $user->id : 'null' }};
    // Da li je posetilac prijavljen — gost NE vidi status dostupnosti na kartici
    // (pre nego što klikne na knjigu). E-knjiga oznaka ostaje (nije dostupnost).
    const jeUlogovan = {{ $user ? 'true' : 'false' }};

/* potvrdiOdjavu je globalizovan u notifications.js */
</script>
<script src="{{ asset_v('js/knjige-index.js') }}"></script>
@endpush
