{{-- Obaveštenja da je korisnik otkazao rezervaciju (prikazuje se na mestu rezervacije,
     u tabu Na čekanju / Odobrene). Prima $otkazane (kolekcija Rezervacija sa status
     Otkazana i otkazana_vidjena=false). Dugme „Označi kao pročitano" gasi obaveštenje. --}}
@if(isset($otkazane) && $otkazane->count() > 0)
    <div class="otkazivanje-obavestenja">
        @foreach($otkazane as $o)
        <div id="otkazivanje-{{ $o->id }}" class="otkazivanje-poruka">
            <span class="otkazivanje-tekst">
                <i class="fa-solid fa-circle-xmark"></i>
                Korisnik <strong>{{ $o->user->ime ?? '' }} {{ $o->user->prezime ?? '' }}</strong>
                je otkazao rezervaciju za knjigu „<strong>{{ $o->knjiga->naziv_knjige ?? 'N/A' }}</strong>"
                dana {{ $o->updated_at->format('d.m.Y') }} u {{ $o->updated_at->format('H:i') }} sati.
            </span>
            <button type="button" class="btn-otkazivanje-vidjeno" onclick="oznaciOtkazivanjeVidjeno({{ $o->id }})">
                <i class="fa-solid fa-check"></i> Označi kao pročitano
            </button>
        </div>
        @endforeach
    </div>
@endif
