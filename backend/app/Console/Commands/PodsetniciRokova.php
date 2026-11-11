<?php

namespace App\Console\Commands;

use App\Enums\RezervacijaStatus;
use App\Enums\TipObavestenja;
use App\Models\Rezervacija;
use App\Models\Zaduzivanje;
use App\Services\NotifikacijaService;
use App\Support\PorukeObavestenja;
use Illuminate\Console\Command;

/**
 * Podsetnici „rok ističe sutra" — dva od šest obaveštenja.
 *
 *   1. rok za PREUZIMANJE rezervisane knjige ističe sutra,
 *   2. rok POZAJMICE ističe sutra.
 *
 * Zakazano JEDNOM DNEVNO ujutru (routes/console.php). Podsetnik se vezuje za
 * DATUM, ne za trenutak — „ističe sutra" je isto tačno u osam ujutru i u
 * podne, pa nema razloga da se proverava češće, a član ne dobija poruku usred
 * noći.
 *
 * Sam ISTEK je druga priča i stoji u zasebnoj komandi
 * (obavestenja:istekle-pozajmice): on se dešava u tačnom trenutku i mora da
 * se hvata na svakih sat vremena.
 *
 * DVA PRAVILA KOJA DRŽE CELU KOMANDU:
 *
 *   • Stanje se proverava U TRENUTKU SLANJA, ne u trenutku zakazivanja. Zato
 *     upiti traže „rezervacija je još odobrena" i „knjiga još nije vraćena" —
 *     član koji je knjigu preuzeo ili vratio juče ne sme da dobije podsetnik.
 *
 *   • Svako obaveštenje ide najviše jednom po događaju. Komanda sme da se
 *     pokrene i ručno, i dvaput u istom danu (posle pada servera), pa se na
 *     ponovljeni prolaz oslanja `firstOrCreate` u NotifikacijaService.
 */
class PodsetniciRokova extends Command
{
    protected $signature   = 'obavestenja:podsetnici';
    protected $description = 'Šalje podsetnike o rokovima preuzimanja i pozajmice (u aplikaciju i na mejl)';

    public function __construct(
        private NotifikacijaService $notifikacije
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dana = max(1, (int) config('obavestenja.dana_pre_isteka', 1));

        $rezervacije = $this->podsetiNaRokPreuzimanja($dana);
        $pozajmice   = $this->podsetiNaRokPozajmice($dana);

        $this->info("Rok preuzimanja ističe sutra: {$rezervacije}");
        $this->info("Rok pozajmice ističe sutra:   {$pozajmice}");

        return self::SUCCESS;
    }

    /**
     * „Rok za preuzimanje knjige … ističe sutra."
     *
     * Samo rezervacije koje su JOŠ ODOBRENE — čim član preuzme knjigu, status
     * postaje Preuzeta i podsetnik nema smisla.
     */
    private function podsetiNaRokPreuzimanja(int $dana): int
    {
        $rezervacije = Rezervacija::query()
            ->where('status', RezervacijaStatus::Odobrena)
            ->whereNotNull('rok_preuzimanja')
            ->whereDate('rok_preuzimanja', now()->addDays($dana)->toDateString())
            ->with(['user', 'knjiga'])
            ->get();

        foreach ($rezervacije as $rezervacija) {
            $this->notifikacije->posaljiJednom(
                $rezervacija,
                TipObavestenja::RezervacijaUskoroIstice,
                PorukeObavestenja::rezervacijaUskoroIstice(
                    $rezervacija->knjiga?->naziv_knjige,
                    $rezervacija->datum_rezervacije,
                    $rezervacija->rok_preuzimanja,
                ),
            );
        }

        return $rezervacije->count();
    }

    /**
     * „Rok pozajmice knjige … ističe sutra."
     *
     * `datum_vracanja IS NULL` je uslov „knjiga još nije vraćena" — vraćena
     * pozajmica se ne podseća ni na šta.
     */
    private function podsetiNaRokPozajmice(int $dana): int
    {
        $zaduzivanja = $this->nevracenaZaduzivanja()
            ->whereDate('rok_vracanja', now()->addDays($dana)->toDateString())
            ->get();

        foreach ($zaduzivanja as $zaduzivanje) {
            $this->notifikacije->posaljiZaZaduzivanje(
                $zaduzivanje,
                TipObavestenja::PozajmicaUskoroIstice,
                PorukeObavestenja::pozajmicaUskoroIstice(
                    $zaduzivanje->primerak?->knjiga?->naziv_knjige,
                    $zaduzivanje->datum_izdavanja,
                    $zaduzivanje->rok_vracanja,
                ),
            );
        }

        return $zaduzivanja->count();
    }

    /** Zaduženja koja su još kod člana, sa svime što poruka traži. */
    private function nevracenaZaduzivanja()
    {
        return Zaduzivanje::query()
            ->whereNull('datum_vracanja')
            ->whereNotNull('rok_vracanja')
            ->with(['user', 'primerak.knjiga']);
    }
}
