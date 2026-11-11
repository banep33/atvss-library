<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Svakog ponedeljka u ponoć osvežava se keš sa najčitanijim knjigama
Schedule::command('najcitanije:osvezi')
    ->weeklyOn(1, '00:00');


/* ── ISTEK ROKA — NA SVAKI SAT ────────────────────────────────────────────
   Rokovi nose i sat: rezervacija odobrena u 14:00 ističe petog dana U 14:00,
   knjiga zadužena u 14:00 ističe sedmog dana u isto vreme. Dnevni prolaz u
   osam ujutru tada zatiče rok koji još nije prošao, pa istek uhvati tek
   sutradan — član dobije poruku dan pošto se stvarno desilo, i uz to čeka
   čitav dan da se primerak oslobodi.

   Satni prolaz hvata istek unutar sat vremena. Ponavljanja nema: čišćenje
   menja status rezervacije pa je drugi put ne vidi, a obaveštenje o pozajmici
   se vezuje za zaduženje (firstOrCreate), pa 24 prolaza dnevno pošalju
   najviše jednu poruku. */
Schedule::command('rezervacije:cleanup-istekle')->hourly();
Schedule::command('obavestenja:istekle-pozajmice')->hourlyAt(5);

/* ── PODSETNICI „ROK ISTIČE SUTRA" — JEDNOM DNEVNO ─────────────────────────
   Ovi se vezuju za DATUM, ne za trenutak: „ističe sutra" je isto tačno u osam
   ujutru i u podne. Češće proveravanje ne bi donelo ništa, a poruka bi umela
   da stigne članu usred noći. */
Schedule::command('obavestenja:podsetnici')->dailyAt('08:05');