@extends('layouts.app')

@section('title', 'Zadužene knjige')

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/zaduzene.css') }}">
@endpush

@section('content')

<!-- ========================= -->
<!-- ZADUZENE KNJIGE -->
<!-- ========================= -->

<main class="admin-container">

    <div class="admin-card">

        <div class="card-header">
            <h2>Zadužene knjige</h2>
        </div>

        @if(!$zaduzivanje)
            <div class="empty-state">
                <i class="fa-solid fa-book empty-icon"></i>
                <h3>Nema aktivnog zaduženja</h3>
                <p>Trenutno nemate nijednu zaduženu knjigu</p>
            </div>
        @else

        @php
            $rok = $zaduzivanje->rok_vracanja;
            $now = now();
            $sati = $now->diffInHours($rok, false);
            $dana = $now->diffInDays($rok, false);
        @endphp

        <div class="table-responsive">

            <table class="data-table" data-paginate="10">

                <thead>
                    <tr>
                        <th>Knjiga</th>
                        <th>Datum zaduženja</th>
                        <th>Rok vraćanja</th>
                    </tr>
                </thead>

                <tbody>

                    <tr>
                        <td>
                            <div class="book-cell">
                                <strong>{{ $zaduzivanje->primerak->knjiga->naziv_knjige }}</strong>
                                <small>{{ $zaduzivanje->primerak->knjiga->autor }}</small>
                            </div>
                        </td>

                        <td>
                            {{ $zaduzivanje->datum_izdavanja->format('d.m.Y') }}
                        </td>

                        <td class="deadline-cell"
                            data-rok="{{ $rok }}"
                            onmouseover="prikaziPreostaloVreme(this)">

                            <span class="rok-badge
                                {{ $sati < 0 ? 'rok-isteklo' :
                                  ($dana <= 2 ? 'rok-kritican' :
                                  ($dana <= 5 ? 'rok-upozorenje' : 'rok-ok')) }}">

                                {{ $rok->format('d.m.Y') }}

                                @if($dana <= 2 && $sati >= 0)
                                    <small>⚠ Blizi se rok</small>
                                @endif

                            </span>
                        </td>

                    </tr>

                </tbody>

            </table>

        </div>

        @endif
        <a href="/" class="back-link"><i class="fa-solid fa-arrow-left"></i> Povratak na početnu</a>

    </div>

</main>

<!-- ========================= -->
<!-- FOOTER -->
<!-- ========================= -->

@endsection

@push('scripts')
<script>
/* potvrdiOdjavu je globalizovan u notifications.js */
</script>

<script src="{{ asset_v('js/zaduzene.js') }}"></script>
@endpush
