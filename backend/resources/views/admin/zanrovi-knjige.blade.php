@extends('layouts.admin')

@section('title', 'Knjige - ' . $zanr->naziv)

@push('styles')
    <link rel="stylesheet" href="{{ asset_v('css/admin.css') }}">
@endpush

@section('content')

<div class="admin-container">
    <div class="header-actions">
            <a href="/admin/zanrovi" class="header-btn"> <i class="fa-solid fa-arrow-left"></i> Nazad na žanrove</a>
        </div>
    <div class="admin-card">
        
        <div class="card-header">
            <h2><i class="fa-solid fa-book"></i> Knjige u žanru: {{ $zanr->naziv }}</h2>
        </div>

        @if($knjige->isEmpty())
            <div class="empty-state">
                <span class="empty-icon"><i class="fa-solid fa-book"></i></span>
                <h3>Nema knjiga u ovom žanru</h3>
            </div>
        @else
            <div class="table-scroll zanr-knjige-scroll">
                <table class="zanr-knjige-tabela" data-paginate="10">
                    <thead>
                        <tr>
                            <th style="min-width:100px;">Inventarski broj</th>
                            <th style="min-width:90px;">Signatura</th>
                            <th style="min-width:100px;">Datum</th>
                            <th style="min-width:160px;">Autor</th>
                            <th style="min-width:180px;">Naziv knjige</th>
                            <th style="min-width:150px;">Mesto i godina izdavanja</th>
                            <th style="min-width:110px;">Izdavač</th>
                            <th style="min-width:110px;">Način nabavke</th>
                            <th style="min-width:140px;">ISBN</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($knjige as $knjiga)
                        <tr>
                            <td class="nowrap">{{ $knjiga->inventarski_broj }}</td>
                            <td class="nowrap">{{ $knjiga->signatura }}</td>
                            <td class="nowrap">{{ \Carbon\Carbon::parse($knjiga->datum_unosa)->format('d.m.Y') }}</td>
                            <td>{{ $knjiga->autor }}</td>
                            <td>{{ $knjiga->naziv_knjige }}</td>
                            <td class="nowrap">{{ trim(implode(', ', array_filter([$knjiga->mesto_izdavanja, $knjiga->godina_izdavanja]))) }}</td>
                            <td>{{ $knjiga->izdavac }}</td>
                            <td class="nowrap">{{ $knjiga->nacin_nabavke }}</td>
                            <td class="nowrap">{{ $knjiga->isbn }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<!--footer-->

@endsection
