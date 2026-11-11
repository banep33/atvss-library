<?php

namespace App\Console\Commands;

use App\Enums\TipObavestenja;
use App\Models\Zaduzivanje;
use App\Services\NotifikacijaService;
use App\Support\PorukeObavestenja;
use Illuminate\Console\Command;

/**
 * „Rok pozajmice je istekao. Molimo vas da knjigu vratite."
 *
 * ZAKAZANO NA SVAKI SAT, a ne jednom dnevno — i to je jedina stvar koju treba
 * razumeti o ovoj komandi.
 *
 * Rok vraćanja nosi i sat: knjiga zadužena u 14:00 na sedam dana ističe sedmog
 * dana U 14:00. Dnevni prolaz u osam ujutru tada zatiče rok koji JOŠ NIJE
 * prošao, pa obaveštenje ode tek sutradan — osmog dana. Član bi dobio poruku
 * da je rok „istekao danas" dan pošto je stvarno istekao.
 *
 * Sa satnim prolazom istek se uhvati unutar sat vremena od trenutka kada se
 * dogodi, pa poruka stiže istog dana i tekst je tačan.
 *
 * Ponavljanja nema: NotifikacijaService vezuje obaveštenje za zaduženje
 * (firstOrCreate po paru zaduženje + tip), pa dvadeset četiri prolaza dnevno
 * pošalju najviše jednu poruku po pozajmici.
 *
 * Podsetnik „ističe sutra" je u zasebnoj komandi (obavestenja:podsetnici) i
 * ostaje dnevni: on se vezuje za datum, pa nema šta da se dobije češćim
 * proveravanjem, a član ne treba da dobija poštu usred noći.
 */
class IstekliRokoviPozajmice extends Command
{
    protected $signature   = 'obavestenja:istekle-pozajmice';
    protected $description = 'Javlja članovima da je rok pozajmice istekao (u aplikaciju i na mejl)';

    public function __construct(
        private NotifikacijaService $notifikacije
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /* Uzima SVE nevraćene knjige kojima je rok prošao, ne samo one kojima
           je istekao baš danas: jedan preskočen prolaz (ugašen server preko
           vikenda) inače bi značio da član nikada ne dobije obaveštenje.
           Tekst poruke sam bira između „istekao danas" i „istekao dana …",
           pa ostaje tačan i kada kasni.

           `datum_vracanja IS NULL` je uslov „knjiga još nije vraćena" — ko je
           vratio na vreme, ne dobija ništa. */
        $zaduzivanja = Zaduzivanje::query()
            ->whereNull('datum_vracanja')
            ->whereNotNull('rok_vracanja')
            ->where('rok_vracanja', '<=', now())
            ->with(['user', 'primerak.knjiga'])
            ->get();

        foreach ($zaduzivanja as $zaduzivanje) {
            $this->notifikacije->posaljiZaZaduzivanje(
                $zaduzivanje,
                TipObavestenja::PozajmicaIstekla,
                PorukeObavestenja::pozajmicaIstekla(
                    $zaduzivanje->primerak?->knjiga?->naziv_knjige,
                    $zaduzivanje->datum_izdavanja,
                    $zaduzivanje->rok_vracanja,
                ),
            );
        }

        $this->info("Nevraćenih knjiga sa isteklim rokom: {$zaduzivanja->count()}");

        return self::SUCCESS;
    }
}
