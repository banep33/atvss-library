<?php

namespace App\Services;

use App\Models\{Rezervacija, PrimerakKnjige, Zaduzivanje};
use Illuminate\Support\Facades\DB;
use App\Enums\RezervacijaStatus;
use App\Enums\PrimerakStatus;
use App\Enums\ArhivaTip;
use App\Enums\TipObavestenja;
use App\Support\PorukeObavestenja;
use App\Services\FeaturedKnjigeService;

class RezervacijaService
{
    // Koliko dana korisnik ima da preuzme odobrenu rezervaciju. Datum isteka
    // se čuva u postojećoj koloni `rok_preuzimanja` (rezervacije.rok_preuzimanja).
    public const DANA_ZA_PREUZIMANJE = 5;

    public function __construct(
        private ArhivaService       $arhiva,
        private NotifikacijaService $notifikacije,
        private FeaturedKnjigeService $featured  // DODATO
    ) {}

    public function kreiraj(int $userId, int $knjigaId): Rezervacija
    {
        $postojeca = Rezervacija::where('user_id', $userId)
            ->where('knjiga_id', $knjigaId)
            ->whereIn('status', [RezervacijaStatus::NaCekanju, RezervacijaStatus::Odobrena])
            ->first();

        if ($postojeca) {
            throw new \Exception('Već imate aktivnu rezervaciju za ovu knjigu.');
        }

        // provera aktivnog zaduzenja
        $aktivnoZaduzenje = Zaduzivanje::where('user_id', $userId)
            ->whereNull('datum_vracanja')
            ->exists();

        if ($aktivnoZaduzenje) {
            throw new \Exception('Ne možete rezervisati knjigu dok ne vratite trenutno zaduženu knjigu.');
        }

        // Provera aktivne rezervacije za BILO KOJU knjigu
        $aktivnaRezervacija = Rezervacija::where('user_id', $userId)
            ->whereIn('status', [RezervacijaStatus::NaCekanju, RezervacijaStatus::Odobrena])
            ->exists();

        if ($aktivnaRezervacija) {
            throw new \Exception('Ne možete rezervisati više od jedne knjige istovremeno.');
        }

        $rezervacija = Rezervacija::create([
            'user_id'           => $userId,
            'knjiga_id'         => $knjigaId,
            'status' => RezervacijaStatus::NaCekanju,
            'datum_rezervacije' => now(),
        ]);

        $rezervacija->load('user', 'knjiga');
        $this->arhiva->arhivirajRezervaciju($rezervacija, ArhivaTip::Zahtev);

        return $rezervacija;
    }

    public function odobri(Rezervacija $rezervacija): void
    {
        DB::transaction(function () use ($rezervacija) {
        // 1. Zaključaj slobodan primerak za ovu knjgu – niko drugi ne može
        // da pročita/izmeni ovaj red dok transakcija ne završi
        $primerak = PrimerakKnjige::where('knjiga_id', $rezervacija->knjiga_id)
            ->where('status', PrimerakStatus::Slobodna)
            ->whereDoesntHave('aktivnoZaduzivanje')
            ->lockForUpdate()   // ← ključna izmena
            ->first();

        // 2. Ako nema slobodnog primerka, odmah bacamo izuzetak
        // pre nego što išta promenimo
        if (!$primerak) {
            throw new \Exception('Nema slobodnih primeraka za ovu knjigu. Rezervacija ne može biti odobrena.');
        }

        // 3. Tek sada menjamo status rezervacije. Rok isteka se računa IZ
        // datuma odobrenja (isti trenutak, ne dva odvojena now()), pa je
        // rok_preuzimanja uvek tačno DANA_ZA_PREUZIMANJE dana posle njega.
        $odobreno = now();

        $rezervacija->update([
            'status'          => RezervacijaStatus::Odobrena,
            'datum_odobrenja' => $odobreno,
            'rok_preuzimanja' => $odobreno->copy()->addDays(self::DANA_ZA_PREUZIMANJE),
            'primerak_id'     => $primerak->id,
        ]);

        // 4. Zaključavamo primerak
        $primerak->update(['status' => PrimerakStatus::Rezervisana]);

        $rezervacija->load('user', 'knjiga');
        $this->arhiva->arhivirajRezervaciju($rezervacija, ArhivaTip::Odobrena);
        $this->notifikacije->posalji(
            $rezervacija,
            TipObavestenja::RezervacijaOdobrena,
            PorukeObavestenja::rezervacijaOdobrena(
                $rezervacija->knjiga?->naziv_knjige,
                $rezervacija->datum_rezervacije,
                self::DANA_ZA_PREUZIMANJE,
            ),
        );
        });
    }

    public function odbij(Rezervacija $rezervacija): void
    {
        DB::transaction(function () use ($rezervacija) {
            if ($rezervacija->status === RezervacijaStatus::Odobrena && $rezervacija->primerak_id) {
                DB::table('primerci_knjiga')
                    ->where('id', $rezervacija->primerak_id)  // ← direktno po ID-u
                    ->update([
                        'status'     => PrimerakStatus::Slobodna,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('rezervacije')
                ->where('id', $rezervacija->id)
                ->update(['status' => RezervacijaStatus::Odbijena, 'updated_at' => now()]);

            $rezervacija->load('user', 'knjiga');
            $this->arhiva->arhivirajRezervaciju($rezervacija, ArhivaTip::Odbijena);
            $this->notifikacije->posalji(
                $rezervacija,
                TipObavestenja::RezervacijaOdbijena,
                PorukeObavestenja::rezervacijaOdbijena(
                    $rezervacija->knjiga?->naziv_knjige,
                    $rezervacija->datum_rezervacije,
                ),
            );
        });
    }

    public function zaduzi(Rezervacija $rezervacija, $primerak, int $dana): void
    {
    DB::transaction(function () use ($rezervacija, $primerak, $dana) {
        DB::table('rezervacije')->where('id', $rezervacija->id)->update([
            'primerak_id'       => $primerak->id,
            'status' => RezervacijaStatus::Preuzeta,
            'datum_preuzimanja' => now(),
            'updated_at'        => now(),
        ]);

        DB::table('primerci_knjiga')->where('id', $primerak->id)->update([
            'status'     => PrimerakStatus::Zaduzena,
            'updated_at' => now(),
        ]);

        DB::table('zaduzivanja')->insert([
            'user_id'         => $rezervacija->user_id,
            'primerak_id'     => $primerak->id,
            'datum_izdavanja' => now(),
            'rok_vracanja'    => now()->addDays($dana),
            'datum_vracanja'  => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $rezervacija->load('user', 'knjiga');
        $this->arhiva->arhivirajRezervaciju($rezervacija, ArhivaTip::Zaduzena);
        $this->featured->clearCache(); // ← DODATO
    });
}

public function vrati(Rezervacija $rezervacija, ?Zaduzivanje $zaduzivanje): void
    {
    DB::transaction(function () use ($rezervacija, $zaduzivanje) {
        DB::table('zaduzivanja')
            ->where('primerak_id', $rezervacija->primerak_id)
            ->where('user_id', $rezervacija->user_id)
            ->whereNull('datum_vracanja')
            ->update(['datum_vracanja' => now(), 'updated_at' => now()]);

        DB::table('primerci_knjiga')
            ->where('id', $rezervacija->primerak_id)
            ->update(['status' => PrimerakStatus::Slobodna, 'updated_at' => now()]);

        DB::table('rezervacije')
            ->where('id', $rezervacija->id)
            ->update(['vracena' => true, 'datum_vracanja' => now(), 'updated_at' => now()]);

        $rezervacija->load('user', 'knjiga');
        $this->arhiva->arhivirajRezervaciju($rezervacija, ArhivaTip::Vracena, $zaduzivanje?->rok_vracanja);
    });
    }  
}