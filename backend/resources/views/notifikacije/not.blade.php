@extends('layouts.app')

@section('title', 'Moje notifikacije')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/not.css') }}">
@endpush

@section('content')

<div class="notifikacije-wrapper">

    {{-- Naslov, poruke i povratak stoje u jednoj kartici — isti okvir koji
         nose i ostale sekcije u aplikaciji (.admin-card, .book-page…).
         Klasa .notifikacije-card je već postojala u not.css, ali se nigde
         nije koristila. --}}
    <div class="notifikacije-card">

    <h1>Moje notifikacije</h1>

    @if($notifikacije->isEmpty())
        <div class="empty-state">
            <span><i class="fa-solid fa-envelopes-bulk"></i> Nemate notifikacija.</span>
        </div>
    @else
        <ul class="notifikacije-list">
            @foreach($notifikacije as $n)
            @php
                /* Boja poruke dolazi iz istog mesta kao boja brojača u
                   zaglavlju i trake u mejlu, pa su sva tri uvek usklađena.
                   `iz()` na nepoznat tip vrati null — takav red ispadne crven
                   umesto da obori ceo spisak. */
                $boja = \App\Enums\TipObavestenja::iz($n->tip)?->boja() ?? 'greska';
            @endphp
            <li id="notifikacija-{{ $n->id }}" class="notifikacija--{{ $boja }}">
                <span>{{ $n->poruka }}</span>
                <div>
                    <span class="datum">{{ $n->created_at->format('d.m.Y H:i') }}</span>
                    @if(!$n->procitano)
                    <button class="btn-procitano" onclick="oznaciProcitano({{ $n->id }})">Označi kao pročitano</button>
                    @endif
                </div>
            </li>
            @endforeach
        </ul>
    @endif
    
    <a href="/" class="back-link"><i class="fa-solid fa-arrow-left"></i> Povratak na početnu</a>

    </div>
</div>

@endsection

@push('scripts')
<script>
/* potvrdiOdjavu je globalizovan u notifications.js */
</script>

<script src="{{ asset_v('js/korisnik-not.js') }}"></script>
@endpush
