@extends('layouts.admin')

@section('title', 'Uvoz knjiga')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/admin.css') }}">
@endpush

@section('content')

<div class="admin-container">

    

    {{-- Rezultat uvoza --}}
    @if(session('rezultat'))
        @php $r = session('rezultat') @endphp
        <div class="admin-card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h2><i class="fa-solid fa-circle-check"></i> Rezultat uvoza</h2>
            </div>
            <div style="padding: 1.5rem;">
                <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 1rem; border-radius: 8px; color: #155724; margin-bottom: 1rem;">
                    <p style="margin: 0 0 0.5rem 0;"><i class="fa-solid fa-book"></i> Uvezeno knjiga: <strong>{{ $r['knjige'] }}</strong></p>
                    <p style="margin: 0;"><i class="fa-solid fa-copy"></i> Uvezeno primeraka: <strong>{{ $r['primerci'] }}</strong></p>
                </div>

                @if(count($r['greske']) > 0)
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 1rem; border-radius: 8px; color: #856404;">
                        <strong><i class="fa-solid fa-triangle-exclamation"></i> Greške ({{ count($r['greske']) }}):</strong>
                        <ul style="margin-top: 0.5rem;">
                            @foreach($r['greske'] as $greska)
                                <li>{{ $greska }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Forma --}}
    <div class="admin-card">
        <div class="card-header">
            <h2><i class="fa-solid fa-upload"></i> Učitaj Excel fajl</h2>
        </div>
        <div style="padding: 2rem;">

            <div class="info-box" style="margin-bottom: 2rem;">
                <h3><i class="fa-solid fa-circle-info"></i> Format Excel fajla</h3>
                <ul>
                    <li>Kolona A: <strong>In. Broj</strong> — učitava se iz fajla, ono što piše u excelu prikazuje se na stranici</li>
                    <li>Kolona B: <strong>Signatura</strong></li>
                    <li>Kolona C: <strong>Datum</strong> — opciono</li>
                    <li>Kolona D: <strong>Autor</strong></li>
                    <li>Kolona E: <strong>Naziv knjige</strong></li>
                    <li>Kolona F: <strong>Mesto izdavanja, godina</strong></li>
                    <li>Kolona G: <strong>Izdavač</strong></li>
                    <li>Kolona H: <strong>Način nabavke</strong> — opciono</li>
                    <li>Kolona I: <strong>ISBN</strong> — opciono</li>
                    
                </ul>
            </div>

            {{-- novalidate: proveru obaveznih polja radi proveriObavezna() iz
                     notifications.js, pa poruka stoji ispod polja i prati dizajn
                     aplikacije umesto podrazumevanog „balončića" pregledača. --}}
                <form action="/admin/knjige/import" method="POST" enctype="multipart/form-data"
                      id="uvozForm" novalidate onsubmit="potvrdiUvoz(event)">
                @csrf

                @error('fajl')
                    <div style="background: #f8d7da; padding: 0.75rem; border-radius: 6px; color: #721c24; margin-bottom: 1rem;">
                        <i class="fa-solid fa-exclamation-triangle"></i> {{ $message }}
                    </div>
                @enderror

                <div style="margin-bottom: 1.5rem;">
                    <div class="uvoz-fajl-zaglavlje">
                        <label for="fajlInput">Excel fajl (.xlsx)</label>
                        <button
                            type="button"
                            id="obrisiBtn"
                            class="btn-ukloni-fajl"
                            onclick="obrisiFile()"
                            title="Ukloni izabranu datoteku"
                        >
                            <i class="fa-solid fa-xmark"></i> Ukloni datoteku
                        </button>
                    </div>

                    {{-- Polje je sopstveni prikaz, a ne podrazumevani izgled
                         <input type="file">: tekst dugmeta („Choose File") ne
                         može da se promeni, a i ostaje na jeziku pregledača.
                         Sam input je zato nevidljiv, ali OSTAJE u rasporedu —
                         proveriObavezna() preskače polja kojima je offsetParent
                         null, pa bi `display: none` ugasio poruku o obaveznom
                         polju. --}}
                    <div class="uvoz-fajl-polje" id="fajlPolje">
                        <input
                            type="file"
                            name="fajl"
                            id="fajlInput"
                            class="uvoz-fajl-input"
                            accept=".xlsx,.xls"
                            required
                            onchange="prikaziIks(this)"
                        >
                        <label for="fajlInput" class="uvoz-fajl-dugme">
                            <i class="fa-solid fa-upload"></i> Učitaj datoteku
                        </label>
                        <span class="uvoz-fajl-naziv" id="nazivDatoteke">Nije izabrana datoteka</span>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-file-import"></i> Uvezi knjige
                </button>
            </form>
        </div>
    </div>

    <div class="info-box">
        <h3><i class="fa-solid fa-triangle-exclamation"></i> Napomena</h3>
        <ul>
            <li>Inventarski brojevi se <strong>učitavaju iz excel fajla</strong> i nastavljaju od poslednjeg u bazi.</li>
            <li>Uvoz je siguran — postojeće knjige se <strong>ne menjaju</strong>.</li>
            <li>Nakon uvoza možeš dodati žanrove i slike za svaku knjigu pojedinačno.</li>
        </ul>
    </div>

</div>

<!--footer-->

@endsection

@push('scripts')
<script>
/* potvrdiOdjavu je globalizovan u notifications.js */

async function potvrdiUvoz(e) {
    if (e) e.preventDefault();

    /* Bez izabranog fajla nema šta da se potvrđuje — poruka ide ISPOD polja,
       isto kao na prijavi i u formi za dodavanje knjige. */
    const forma = document.getElementById('uvozForm');
    if (typeof proveriObavezna === 'function' && !proveriObavezna(forma, { dijalog: false })) {
        return;
    }

    const input = document.getElementById('fajlInput');
    const nazivFajla = input.files.length > 0 ? input.files[0].name : 'izabrana datoteka';

    const ok = await confirmDialog({
        type: 'primary',
        title: 'Uvoz knjiga',
        message: 'Da li želite da uvezete knjige iz fajla:\n\n"' + nazivFajla + '"',
        confirmText: 'Uvezi',
        cancelText: 'Otkaži'
    });

    if (ok) document.getElementById('uvozForm').submit();
}

function prikaziIks(input) {
    const btn    = document.getElementById('obrisiBtn');
    const polje  = document.getElementById('fajlPolje');
    const naziv  = document.getElementById('nazivDatoteke');
    const imaFajl = input.files && input.files.length > 0;

    // inline-flex zato sto .btn-ukloni-fajl flex-om drzi ikonicu i tekst u redu
    btn.style.display = imaFajl ? 'inline-flex' : 'none';

    /* Sa izabranom datotekom u polju ostaje samo njeno ime; dugme „Učitaj
       datoteku" se vraća kada se datoteka ukloni. */
    polje.classList.toggle('ima-datoteku', imaFajl);
    naziv.textContent = imaFajl ? input.files[0].name : 'Nije izabrana datoteka';
}

function obrisiFile() {
    const input = document.getElementById('fajlInput');

    input.value = '';
    prikaziIks(input);
    input.focus();
}
</script>
@endpush
