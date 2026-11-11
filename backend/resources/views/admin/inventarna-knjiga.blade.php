@extends('layouts.admin')

@section('title', 'Inventarna knjiga')

@push('styles')
    {{-- admin.css, kao i ostale bibliotekarske stranice: odatle dolaze
         `.controls-grid` / `.control-group` (pretraga), `.btn-secondary` i
         `.btn-view-small`, i `body { padding-top }` ispod fiksnog zaglavlja.
         Ranije je ovde stajao knjige-index.css, iz kog je stranica koristila
         samo to poslednje pravilo. --}}
    <link rel="stylesheet" href="{{ asset_v('css/admin.css') }}">
    {{-- Obrazac za dodavanje/izmenu knjige (partials/knjiga-form-modal) —
         isti stilovi koje koristi i stranica knjige. --}}
    <link rel="stylesheet" href="{{ asset_v('css/knjige-modal.css') }}">
        <style>
            /* ═══════════════════════════════════════════════════════════════
               INVENTARNA KNJIGA

               Tabela ima trinaest kolona, pa je ranije stajala u kontejneru
               fiksne visine sa DVA sopstvena skrola — vodoravnim (levo-desno)
               i uspravnim. To je značilo da točkić miša pomera sadržaj samo
               dok je pokazivač nad tabelom, i da se do desnih kolona stiže
               zasebnom trakom.

               Sada kontejner zauzima CELU širinu stranice, pa sve kolone
               staju bez vodoravnog skrola, a uspravnog svog nema — skroluje
               se cela stranica, bilo gde na njoj. Paginacija ostaje
               (public/js/pagination.js, `data-paginate`).
               ═══════════════════════════════════════════════════════════════ */
            html, body {
                margin: 0;
            }

            /* ZAGLAVLJE — samo na ovoj stranici.

               Ovo je jedina stranica koja ide preko CELE širine prozora, pa
               se i zaglavlje pušta do ivica: `.header-content` je u core.css
               ograničen na 1200px i centriran, zbog čega je logotip stajao
               uvučen u odnosu na sadržaj ispod sebe. Bočno odstojanje ostaje
               isto kao kod omotača (32px), pa se logotip poravnava sa levom
               ivicom kartice — kao na ostalim stranicama.

               Pošto kontejner ide do ivica, nema praznog pojasa sa strane iz
               kog alatke (pismo, tema, nalog) na ostalim stranicama uzimaju
               svoje mesto — one su apsolutno zakačene uz desnu ivicu
               zaglavlja. Zato linkovi ovde ne idu uz desnu ivicu, nego odmah
               uz logotip; inače bi im se poslednji („Inventarna knjiga",
               „Info") podvukli ispod alatki. Rezervisano mesto iz core.css
               (`padding-right` ispod 1500px) tada nema šta da radi. */
            .header-content {
                max-width: none;
                justify-content: flex-start;
            }

            header .header-actions {
                margin-left: 12px;
                padding-right: 0;
            }

            body {
                display: flex;
                flex-direction: column;
                min-height: 100vh;
            }

            /* Puna širina prozora, sa istim bočnim odstojanjem koje imaju i
               ostale stranice. `flex: 1` gura podnožje na dno. */
            .inventarna-wrapper {
                width: 100%;
                margin: 32px auto;
                padding: 0 32px;
                flex: 1;
            }

            .inventarna-card {
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: 12px;
                padding: 32px;
                box-shadow: 0 1px 3px var(--shadow-color);
            }

            /* Naslov i „Dodaj novu knjigu" u istom redu — dugme je preseljeno
               sa početne strane ovamo, jer se knjige unose i vode odavde. */
            .inventarna-zaglavlje {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                flex-wrap: wrap;
                margin-bottom: 24px;
            }

            .inventarna-title {
                font-size: 22px;
                font-weight: 600;
                color: var(--text-strong);
                display: flex;
                align-items: center;
                gap: 10px;
            }

            /* Bez `overflow` i bez zadate visine: tabela raste koliko joj
               treba, a skroluje je stranica. Klasa i dalje postoji jer je
               pagination.js koristi kao sidro za svoje trake. */
            .table-scroll {
                border: 1px solid var(--border);
                border-radius: 8px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                /* Fiksni raspored: širine diktira zaglavlje (ispod), pa
                   duga polja ne razvlače tabelu preko širine ekrana. */
                table-layout: fixed;
                font-size: 13px;
            }

            thead th {
                background: var(--surface-subtle);
                color: var(--text-muted);
                font-weight: 600;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                padding: 12px 10px;
                border-bottom: 2px solid var(--border);
                text-align: center;
            }

            tbody tr {
                border-bottom: 1px solid var(--border);
                transition: background 0.15s;
            }

            tbody tr:hover {
                background: var(--surface-hover);
            }

            tbody tr:last-child {
                border-bottom: none;
            }

            tbody td {
                padding: 10px;
                color: var(--text);
                text-align: center;
                vertical-align: middle;
                /* Dug tekst se prelama umesto da gura tabelu u širinu. */
                overflow-wrap: anywhere;
            }

            /* Širine kolona — zbir je 100%. Naziv knjige i autor dobijaju
               najviše prostora, jer su jedini sa dužim tekstom. */
            .kol-red      { width: 3%; }
            .kol-inv      { width: 7%; }
            .kol-sig      { width: 7%; }
            .kol-datum    { width: 7%; }
            .kol-autor    { width: 12%; }
            .kol-naziv    { width: 17%; }
            .kol-izdanje  { width: 10%; }
            .kol-izdavac  { width: 9%; }
            .kol-nabavka  { width: 8%; }
            .kol-isbn     { width: 9%; }
            .kol-zanr     { width: 10%; }
            .kol-akcije   { width: 9%; }

            /* ── AKCIJE ──────────────────────────────────────────────────
               Tri radnje nad knjigom u jednoj ćeliji: pogledaj (okce),
               izmeni (olovka) i obriši (kantica). Samo ikonice — natpisi
               ne bi stali u kolonu široku 9%, a značenje nose `title` i
               `aria-label` (čitač ekrana, i oblačić na prelazak mišem).

               Član (koji takođe vidi inventarnu knjigu) dobija samo okce.

               Svaka ikonica STALNO nosi svoju boju — i znak i ivicu: plava
               za pregled, narandžasta za izmenu, crvena za brisanje. Ranije
               su bile sive dok se mišem ne pređe preko njih, pa se po izgledu
               nije videlo koja šta radi dok se ne proba.

               Pošto je boja sada zatečeno stanje, prelazak mišem mora da se
               razlikuje jače nego samo promenom nijanse: dugme se popuni
               svojom bojom, a znak postane beo. */
            .akcije-grupa {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            }

            .akcija-btn {
                /* --akcija-boja postavlja svaki modifikator ispod; ovde stoji
                   neutralna vrednost, da dugme bez modifikatora ne ostane bez
                   ivice. */
                --akcija-boja: var(--text-muted);

                width: 30px;
                height: 30px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--akcija-boja);
                border-radius: 6px;
                background: var(--surface);
                color: var(--akcija-boja);
                font-size: 13px;
                cursor: pointer;
                text-decoration: none;
                transition: background 0.15s, color 0.15s, border-color 0.15s;
            }

            .akcija-btn:hover,
            .akcija-btn:focus-visible {
                background: var(--akcija-boja);
                color: #fff;
            }

            .akcija-btn--pogledaj { --akcija-boja: var(--brand); }
            .akcija-btn--izmeni   { --akcija-boja: var(--warning); }
            .akcija-btn--obrisi   { --akcija-boja: var(--danger); }

            .red-broj {
                color: var(--text-faint);
                font-size: 13px;
            }

            .empty-state {
                text-align: center;
                padding: 60px 20px;
                color: var(--text-subtle);
                font-size: 16px;
            }

            .ukupno {
                margin-top: 16px;
                font-size: 14px;
                color: var(--text-subtle);
                text-align: right;
            }

            /* Red koji pretraga sakriva. Inline `display:none` bi pagination.js
               protumačio kao filter — i to je upravo ono što nam treba. */
            .nema-rezultata td {
                padding: 40px 20px;
                color: var(--text-subtle);
            }

            /* Uski ekrani: trinaest kolona nikako ne staje, pa tu tabela
               ipak dobija vodoravni skrol — ali samo tamo. */
            @media (max-width: 1100px) {
                .inventarna-wrapper,
                .header-content {
                    padding: 0 16px;
                }

                .inventarna-card {
                    padding: 20px;
                }

                .table-scroll {
                    overflow-x: auto;
                }

                table {
                    table-layout: auto;
                    min-width: 1000px;
                }
            }
        </style>
@endpush

@section('content')

<div class="inventarna-wrapper">
    <div class="inventarna-card">

        {{-- Pretraga stoji iznad naslova sekcije, u istoj kartici — isto kao
             na rezervacijama i na spisku članova. Okvir joj skida
             `.controls-slot` iz core.css. --}}
        <div class="controls-slot">
            <div class="controls-wrapper">
                <div class="controls-grid" style="grid-template-columns: 1fr 1fr 1fr auto;">
                    <div class="control-group">
                        <label>Pretraga po naslovu</label>
                        <input type="text" id="pretragaNaslov" placeholder="Naziv knjige...">
                    </div>
                    <div class="control-group">
                        <label>Pretraga po autoru</label>
                        <input type="text" id="pretragaAutor" placeholder="Ime autora...">
                    </div>
                    <div class="control-group">
                        <label>Pretraga po inventarskom broju</label>
                        <input type="text" id="pretragaInvBroj" placeholder="Inventarski broj...">
                    </div>
                    <div class="control-group">
                        <button id="resetPretrage" class="btn-secondary">
                            <i class="fa-solid fa-rotate-right"></i> Resetuj
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="inventarna-zaglavlje">
            <div class="inventarna-title">
                <i class="fa-solid fa-book-open"></i> Inventarna knjiga
            </div>

            @if($user && $user->isAdminOrBibliotekar())
            {{-- Dodavanje knjige je preseljeno sa početne strane ovamo: nova
                 knjiga se prvo zavodi u inventarnu knjigu, pa se tek onda
                 pojavljuje u katalogu. --}}
            <button type="button" class="btn-add" onclick="openAddModal()">
                + Dodaj novu knjigu
            </button>
            @endif
        </div>

        @if($primerci->isEmpty())
            <div class="empty-state">Nema knjiga u sistemu.</div>
        @else
            <div class="table-scroll" id="tableScroll">
                <table data-paginate="10">
                    <thead>
                        <tr>
                            <th class="kol-red">#</th>
                            <th class="kol-inv">Inv. broj</th>
                            <th class="kol-sig">Signatura</th>
                            <th class="kol-datum">Datum uvoza</th>
                            <th class="kol-autor">Autor</th>
                            <th class="kol-naziv">Naslov</th>
                            <th class="kol-izdanje">Mesto i godina izdavanja</th>
                            <th class="kol-izdavac">Izdavač</th>
                            <th class="kol-nabavka">Način nabavke</th>
                            <th class="kol-isbn">ISBN</th>
                            <th class="kol-zanr">Žanr</th>
                            <th class="kol-akcije">Akcije</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($primerci as $index => $primerak)
                        @php($knjiga = $primerak->knjiga)
                        {{-- data-* nose vrednosti po kojima pretraga poredi, pa
                             skripta ne mora da čita ćelije (a i ne bi smela:
                             prikaz može biti preslovljen u ćirilicu). --}}
                        <tr data-naslov="{{ $knjiga?->naziv_knjige ?? '' }}"
                            data-autor="{{ $knjiga?->autor ?? '' }}"
                            data-inv="{{ $primerak->inventarski_broj ?? '' }}">
                            <td class="red-broj">{{ $index + 1 }}</td>
                            <td>{{ $primerak->inventarski_broj ?: '-' }}</td>
                            <td>{{ $primerak->signatura ?: '-' }}</td>
                            <td>{{ $knjiga?->datum ? \Carbon\Carbon::parse($knjiga->datum)->format('d.m.Y') : '-' }}</td>
                            <td>{{ $knjiga?->autor ?? '-' }}</td>
                            <td>{{ $knjiga?->naziv_knjige ?? '-' }}</td>
                            <td>{{ $knjiga?->izdanje_prikaz ?: '-' }}</td>
                            <td>{{ $knjiga?->izdavac ?? '-' }}</td>
                            <td>{{ $primerak->nacin_nabavke ?? $knjiga?->nacin_nabavke ?? '-' }}</td>
                            <td>{{ $knjiga?->isbn ?? '-' }}</td>
                            <td>
                                {{ $knjiga ? ($knjiga->zanrovi->pluck('naziv')->join(', ') ?: '-') : '-' }}
                            </td>
                            <td>
                                @if($knjiga)
                                    <div class="akcije-grupa">
                                        <a href="/knjige/{{ $knjiga->id }}"
                                           class="akcija-btn akcija-btn--pogledaj"
                                           title="Pogledaj knjigu"
                                           aria-label="Pogledaj knjigu">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>

                                        @if($user && $user->isAdminOrBibliotekar())
                                        <button type="button"
                                                class="akcija-btn akcija-btn--izmeni"
                                                onclick="openEditModal({{ $knjiga->id }})"
                                                title="Izmeni podatke o knjizi"
                                                aria-label="Izmeni podatke o knjizi">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>

                                        {{-- Briše CELU knjigu, dakle i sve njene
                                             primerke — i ovaj i sve ostale redove
                                             iste knjige. Naziv ide u potvrdu da
                                             bibliotekar vidi šta briše. --}}
                                        <button type="button"
                                                class="akcija-btn akcija-btn--obrisi"
                                                onclick="obrisiKnjigu({{ $knjiga->id }}, @js($knjiga->naziv_knjige))"
                                                title="Obriši knjigu"
                                                aria-label="Obriši knjigu">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                        @endif
                                    </div>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                        @endforeach
                        <tr id="nemaRezultata" class="nema-rezultata" style="display:none;">
                            <td colspan="12">
                                <i class="fa-solid fa-magnifying-glass prazna-ikona"></i>
                                Nema primeraka koji odgovaraju pretrazi.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="ukupno">Ukupno primeraka: <strong>{{ $primerci->count() }}</strong></p>
        @endif
    </div>
</div>

@if($user && $user->isAdminOrBibliotekar())
    @include('partials.knjiga-form-modal')
@endif

<!--footer-->

@endsection

@push('scripts')
@if($user && $user->isAdminOrBibliotekar())
{{-- Dodavanje, izmena i brisanje knjige — zajednički obrazac (isti koji
     koristi i stranica knjige). Tabela je iscrtana na serveru, pa se posle
     svake uspešne izmene stranica ponovo učitava; katalog na početnoj
     strani podatke ionako uvek povlači sa API-ja, pa je i tamo stanje
     odmah usklađeno. --}}
<script src="{{ asset_v('js/knjiga-forma.js') }}"></script>
<script>
    window.KnjigaForma.posleCuvanja  = () => setTimeout(() => location.reload(), 800);
    window.KnjigaForma.posleBrisanja = () => setTimeout(() => location.reload(), 800);
</script>
@endif

<script>
/* potvrdiOdjavu je globalizovan u notifications.js */

/* Povratak dugmetom „nazad" (npr. sa stranice knjige, gde su izmena i
   brisanje takođe mogući) vraća stranicu iz keša pregledača, bez ponovnog
   izvršavanja skripti i bez novog upita serveru — tabela bi tada prikazivala
   staro stanje. `persisted` je upravo taj slučaj. */
window.addEventListener('pageshow', (e) => {
    if (e.persisted) location.reload();
});

/* Pretraga po naslovu, autoru i inventarskom broju.

   Red se sakriva inline stilom `display:none` — pagination.js baš to smatra
   „spoljnim filterom", pa preostale redove sam prestrani i osveži brojeve.
   Poređenje ide preko foldSearch() iz pismo.js: nezavisno je od pisma
   (latinica/ćirilica) i od dijakritika, isto kao pretraga na ostalim
   stranicama. */
document.addEventListener('DOMContentLoaded', () => {
    const polja = ['pretragaNaslov', 'pretragaAutor', 'pretragaInvBroj'].map(id => document.getElementById(id));
    const redovi = Array.from(document.querySelectorAll('#tableScroll tbody tr[data-naslov]'));
    const nemaRezultata = document.getElementById('nemaRezultata');
    if (!redovi.length) return;

    function filtriraj() {
        const fold = window.foldSearch || (s => (s || '').toLowerCase());
        const [naslov, autor, inv] = polja.map(p => fold(p?.value || ''));

        let vidljivih = 0;

        redovi.forEach(red => {
            const poklapa =
                (!naslov || fold(red.dataset.naslov).includes(naslov)) &&
                (!autor  || fold(red.dataset.autor).includes(autor)) &&
                (!inv    || fold(red.dataset.inv).includes(inv));

            red.style.display = poklapa ? '' : 'none';
            if (poklapa) vidljivih++;
        });

        if (nemaRezultata) nemaRezultata.style.display = vidljivih === 0 ? '' : 'none';

        // Paginacija ne posmatra promenu stila pojedinačnih redova, pa se
        // preslaganje traži izričito.
        if (window.CoworkPagination) window.CoworkPagination.refresh();
    }

    polja.forEach(p => p?.addEventListener('input', filtriraj));

    document.getElementById('resetPretrage')?.addEventListener('click', () => {
        polja.forEach(p => { if (p) p.value = ''; });
        filtriraj();
    });
});
</script>
@endpush
