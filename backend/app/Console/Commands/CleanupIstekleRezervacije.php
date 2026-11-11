<?php

namespace App\Console\Commands;

use App\Enums\PrimerakStatus;
use App\Enums\RezervacijaStatus;
use App\Enums\TipObavestenja;
use App\Support\PorukeObavestenja;
use App\Models\Rezervacija;
use App\Services\NotifikacijaService;
use App\Services\RezervacijaService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CleanupIstekleRezervacije extends Command
{
    protected $signature   = 'rezervacije:cleanup-istekle';
    protected $description = 'Automatski čisti istekle rezervacije i šalje notifikacije';

    public function __construct(
        private NotifikacijaService $notifikacije
    ) {
        parent::__construct();
    }

    // Zakazano u routes/console.php (svakog dana u 08:00).
    public function handle()
    {
        $istekle = $this->obradi();

        $this->info("Obrađeno {$istekle->count()} isteklih rezervacija.");
    }

    /**
     * Prepoznaje odobrene rezervacije kojima je prošao rok preuzimanja
     * (rok_preuzimanja = datum_odobrenja + RezervacijaService::DANA_ZA_PREUZIMANJE)
     * i prevodi ih u status Istekla: oslobađa zaključan primerak i obaveštava
     * korisnika. Rezervacija ostaje u bazi da bi bibliotekar u tabeli
     * „Odobrene rezervacije" video da je rok istekao i ručno uklonio red.
     *
     * Javna metoda jer isti posao radi i dugme „Očisti istekle rezervacije"
     * u administraciji (AdminController::cleanupIstekleRezervacije), pa logika
     * postoji samo na ovom mestu.
     *
     * @return Collection<int, Rezervacija> rezervacije istekle u ovom prolazu
     */
    public function obradi(): Collection
    {
        $istekle = Rezervacija::where('status', RezervacijaStatus::Odobrena)
            ->whereNotNull('rok_preuzimanja')
            ->where('rok_preuzimanja', '<', now())
            ->with(['user', 'knjiga'])
            ->get();

        foreach ($istekle as $rezervacija) {
            DB::transaction(function () use ($rezervacija) {
                // Oslobađa se PRIMERAK TE REZERVACIJE (primerak_id se dodeljuje
                // pri odobravanju), a ne „prvi rezervisani primerak te knjige" —
                // inače bi istek jedne rezervacije mogao da oslobodi primerak
                // zaključan za nekog drugog.
                if ($rezervacija->primerak_id) {
                    DB::table('primerci_knjiga')
                        ->where('id', $rezervacija->primerak_id)
                        ->where('status', PrimerakStatus::Rezervisana)
                        ->update([
                            'status'     => PrimerakStatus::Slobodna,
                            'updated_at' => now(),
                        ]);
                }

                $rezervacija->update(['status' => RezervacijaStatus::Istekla]);

                // posaljiJednom (a ne posalji): istek može da se „otkrije" i iz
                // zakazane komande i iz administracije, a korisnik sme da dobije
                // najviše jedno obaveštenje po rezervaciji.
                $this->notifikacije->posaljiJednom(
                    $rezervacija,
                    TipObavestenja::RezervacijaIstekla,
                    PorukeObavestenja::rezervacijaIstekla(
                        $rezervacija->knjiga?->naziv_knjige,
                        $rezervacija->datum_odobrenja,
                    ),
                );
            });
        }

        return $istekle;
    }
}
