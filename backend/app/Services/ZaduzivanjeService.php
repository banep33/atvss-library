<?php

namespace App\Services;

use App\Models\{Zaduzivanje, PrimerakKnjige, Rezervacija};
use App\Enums\PrimerakStatus;
use App\Enums\RezervacijaStatus;
use Illuminate\Support\Facades\DB;
use App\Enums\ArhivaTip;
use App\Services\FeaturedKnjigeService;


class ZaduzivanjeService
{
    public function __construct(
        private ArhivaService $arhiva,
        private FeaturedKnjigeService $featured  // ← DODATO
    ) {}

    public function zaduzi(int $userId, int $primerakId, string $rokVracanja): void
    {
        $primerak = PrimerakKnjige::findOrFail($primerakId);

        if (!in_array($primerak->status, [PrimerakStatus::Slobodna, PrimerakStatus::Rezervisana])) {
            throw new \Exception('Primerak nije dostupan za zadužavanje.');
        }

        DB::transaction(function () use ($userId, $primerak, $rokVracanja) {
            $zaduzivanje = Zaduzivanje::create([
                'user_id'         => $userId,
                'primerak_id'     => $primerak->id,
                'datum_izdavanja' => now(),
                'rok_vracanja'    => $rokVracanja,
            ]);

            $primerak->update(['status' => PrimerakStatus::Zaduzena]);

            $rezervacija = Rezervacija::where('user_id', $userId)
                ->where('knjiga_id', $primerak->knjiga_id)
                ->whereIn('status', [RezervacijaStatus::NaCekanju, RezervacijaStatus::Odobrena, RezervacijaStatus::Preuzeta])
                ->first();

            if ($rezervacija) {
                $rezervacija->update([
                    'status'            => RezervacijaStatus::Preuzeta,
                    'primerak_id'       => $primerak->id,
                    'datum_preuzimanja' => now(),
                    'datum_odobrenja'   => $rezervacija->datum_odobrenja ?? now(),
                    'vracena'           => false,
                ]);
            } else {
                Rezervacija::create([
                    'user_id'           => $userId,
                    'knjiga_id'         => $primerak->knjiga_id,
                    'primerak_id'       => $primerak->id,
                    'status'            => RezervacijaStatus::Preuzeta,
                    'datum_rezervacije' => now(),
                    'datum_odobrenja'   => now(),
                    'datum_preuzimanja' => now(),
                    'vracena'           => false,
                ]);
            }

            $zaduzivanje->load('user', 'primerak.knjiga');
            $this->arhiva->arhivirajZaduzivanje($zaduzivanje, ArhivaTip::Zaduzena);
            $this->featured->clearCache(); // ← DODATO
        });
    }

    public function vrati(Zaduzivanje $zaduzivanje): void
    {
        if ($zaduzivanje->datum_vracanja) {
            throw new \Exception('Ovo zadužavanje je već vraćeno.');
        }

        $primerak = $zaduzivanje->primerak;
        if (!$primerak) {
            throw new \Exception('Primerak nije pronađen.');
        }

        DB::transaction(function () use ($zaduzivanje, $primerak) {
            $zaduzivanje->update(['datum_vracanja' => now()]);
            $primerak->update(['status' => PrimerakStatus::Slobodna]);

            Rezervacija::where('primerak_id', $primerak->id)
                ->where('user_id', $zaduzivanje->user_id)
                ->whereIn('status', [RezervacijaStatus::Preuzeta, RezervacijaStatus::Vracena])
                ->update([
                    'status'         => RezervacijaStatus::Vracena,
                    'vracena'        => true,
                    'datum_vracanja' => now(),
                    'updated_at'     => now(),
                ]);

            $zaduzivanje->load('user', 'primerak.knjiga');
            $this->arhiva->arhivirajZaduzivanje($zaduzivanje, ArhivaTip::Vracena, $zaduzivanje->rok_vracanja);
            
        });
    }
}