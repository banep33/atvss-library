<?php

namespace App\Services;

use App\Models\Arhiva;
use App\Models\Rezervacija;
use App\Models\Zaduzivanje;
use App\Enums\ArhivaTip;

class ArhivaService
{
    public function arhivirajRezervaciju(
        Rezervacija $rezervacija,
        ArhivaTip $tip,
        ?\Carbon\Carbon $rok_vracanja = null
    ): void {
        Arhiva::create([
            'rezervacija_id'     => $rezervacija->id,
            'user_id'            => $rezervacija->user_id,
            'knjiga_id'          => $rezervacija->knjiga_id,
            'tip'                => $tip->value,
            'naziv_knjige'       => $rezervacija->knjiga->naziv_knjige,
            'autor'              => $rezervacija->knjiga->autor,
            'ime_clana'          => $rezervacija->user->ime . ' ' . $rezervacija->user->prezime,
            'email_clana'        => $rezervacija->user->email,
            'broj_clanske_karte' => $rezervacija->user->broj_clanske_karte,
            'datum_akcije'       => now(),
            'rok_vracanja'       => $rok_vracanja,
        ]);
    }

    public function arhivirajZaduzivanje(
        Zaduzivanje $zaduzivanje,
        ArhivaTip $tip,
        ?\Carbon\Carbon $rok_vracanja = null
    ): void {
        $clan   = $zaduzivanje->user;
        $knjiga = $zaduzivanje->primerak->knjiga;

        Arhiva::create([
            'user_id'            => $zaduzivanje->user_id,
            'knjiga_id'          => $knjiga->id,
            'tip'                => $tip->value,
            'naziv_knjige'       => $knjiga->naziv_knjige,
            'autor'              => $knjiga->autor,
            'ime_clana'          => $clan->ime . ' ' . $clan->prezime,
            'email_clana'        => $clan->email,
            'broj_clanske_karte' => $clan->broj_clanske_karte,
            'datum_akcije'       => now(),
            'rok_vracanja'       => $rok_vracanja,
        ]);
    }
}