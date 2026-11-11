<?php

namespace Tests\Feature;

use App\Enums\PrimerakStatus;
use App\Enums\RezervacijaStatus;
use App\Models\Knjiga;
use App\Models\Notifikacija;
use App\Models\PrimerakKnjige;
use App\Models\Rezervacija;
use App\Models\User;
use App\Models\Zaduzivanje;
use App\Services\RezervacijaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KADA tačno stiže koje obaveštenje — odigrano sat po sat.
 *
 * Test pušta pravi raspored (satni prolaz za istek, dnevni u 08:05 za
 * podsetnike) kroz izmišljenih devet dana i proverava da svako obaveštenje
 * padne baš onog dana koji je dogovoren:
 *
 *   rezervacija (rok preuzimanja 5 dana, odobrena u 14:00)
 *     dan 0  odobrena — odmah, iz radnje bibliotekara
 *     dan 4  podsetnik „ističe sutra"
 *     dan 5  istekla
 *
 *   pozajmica (rok vraćanja 7 dana, zadužena u 14:00)
 *     dan 6  podsetnik „ističe sutra"
 *     dan 7  rok istekao
 *
 * Ovo je zaključavanje ponašanja, ne kozmetika: dok je istek proveravan
 * jednom dnevno u osam ujutru, obe „istekao" poruke kasnile su tačno jedan
 * dan — rok nosi i sat, pa u osam ujutru još nije bio prošao.
 */
class VremenskaLinijaTest extends TestCase
{
    use RefreshDatabase;

    private function clan(): User
    {
        return User::create([
            'ime' => 'Pera', 'prezime' => 'Perić', 'email' => 'pera@example.com',
            'password' => Hash::make('tajna123'), 'role' => 'clan',
            'broj_clanske_karte' => 'CLAN-001',
        ]);
    }

    private function primerak(string $inv = '1'): PrimerakKnjige
    {
        $k = Knjiga::create([
            'in_broj' => $inv, 'signatura' => $inv, 'datum' => '2026-01-01',
            'autor' => 'Ivo Andrić', 'naziv_knjige' => 'Na Drini ćuprija',
            'mesto_izdavanja' => 'Beograd', 'godina_izdavanja' => 1945,
            'izdavac' => 'Prosveta', 'nacin_nabavke' => 'Kupovina',
        ]);

        return PrimerakKnjige::create([
            'knjiga_id' => $k->id, 'inventarski_broj' => $inv,
            'signatura' => $inv, 'status' => PrimerakStatus::Slobodna->value,
        ]);
    }

    /** Satni prolaz: istek rezervacije i istek pozajmice. */
    private function satniProlaz(): void
    {
        $this->artisan('rezervacije:cleanup-istekle');
        $this->artisan('obavestenja:istekle-pozajmice');
    }

    /** Dnevni prolaz u 08:05: podsetnici „ističe sutra". */
    private function dnevniProlaz(): void
    {
        $this->artisan('obavestenja:podsetnici');
    }

    /** Odigra ceo dan: 24 satna prolaza + dnevni u 08:05. */
    private function odigrajDan(\Carbon\Carbon $pocetakDana): array
    {
        $kad = [];

        for ($h = 0; $h < 24; $h++) {
            $this->travelTo($pocetakDana->copy()->addHours($h));
            $pre = Notifikacija::count();
            $this->satniProlaz();

            if ($h === 8) {
                $this->dnevniProlaz();
            }

            if (Notifikacija::count() > $pre) {
                foreach (Notifikacija::orderBy('id')->skip($pre)->take(50)->pluck('tip') as $t) {
                    $kad[] = $t . ' u ' . str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
                }
            }
        }

        return $kad;
    }

    private function tipovi(): array
    {
        return Notifikacija::orderBy('id')->pluck('tip')->all();
    }

    #[Test]
    public function linija_rezervacije(): void
    {
        Mail::fake();
        config(['obavestenja.mejl' => true]);

        $clan = $this->clan();
        $this->primerak();

        // Dan 0, 14:00 — bibliotekar odobrava rezervaciju.
        $this->travelTo('2026-09-10 14:00:00');

        $rezervacija = Rezervacija::create([
            'user_id' => $clan->id, 'knjiga_id' => 1,
            'status' => RezervacijaStatus::NaCekanju,
            'datum_rezervacije' => now()->subDay(),
        ]);
        app(RezervacijaService::class)->odobri($rezervacija);

        $red = "\nREZERVACIJA — odobrena 10.09. u 14:00, rok preuzimanja 5 dana\n";
        $red .= '  rok_preuzimanja u bazi: ' . $rezervacija->fresh()->rok_preuzimanja . "\n";
        $red .= "  dan 0 (10.09. 14:00): " . implode(', ', $this->tipovi()) . "\n";

        $kad = [];

        for ($d = 1; $d <= 7; $d++) {
            $pocetak = \Carbon\Carbon::parse('2026-09-10 00:00:00')->addDays($d);
            $novo    = $this->odigrajDan($pocetak);

            $red .= "  dan {$d} ({$pocetak->format('d.m.')}): "
                  . ($novo ? '>>> ' . implode(', ', $novo) : '-') . "\n";

            foreach ($novo as $stavka) {
                $kad[$d] = explode(' u ', $stavka)[0];
            }
        }

        $this->assertSame(
            [4 => 'rezervacija_uskoro_istice', 5 => 'rezervacija_istekla'],
            $kad,
            "Vremenska linija rezervacije:\n" . $red
        );
    }

    #[Test]
    public function linija_pozajmice(): void
    {
        Mail::fake();
        config(['obavestenja.mejl' => true]);

        $clan     = $this->clan();
        $primerak = $this->primerak();

        // Dan 0, 14:00 — bibliotekar zadužuje knjigu na 7 dana.
        $this->travelTo('2026-09-10 14:00:00');

        $zaduzivanje = Zaduzivanje::create([
            'user_id'         => $clan->id,
            'primerak_id'     => $primerak->id,
            'datum_izdavanja' => now(),
            'rok_vracanja'    => now()->addDays(7),
        ]);

        $red = "\nPOZAJMICA — zadužena 10.09. u 14:00, rok vraćanja 7 dana\n";
        $red .= '  rok_vracanja u bazi: ' . $zaduzivanje->fresh()->rok_vracanja . "\n";

        $kad = [];

        for ($d = 1; $d <= 9; $d++) {
            $pocetak = \Carbon\Carbon::parse('2026-09-10 00:00:00')->addDays($d);
            $novo    = $this->odigrajDan($pocetak);

            $red .= "  dan {$d} ({$pocetak->format('d.m.')}): "
                  . ($novo ? '>>> ' . implode(', ', $novo) : '-') . "\n";

            foreach ($novo as $stavka) {
                $kad[$d] = explode(' u ', $stavka)[0];
            }
        }

        $this->assertSame(
            [6 => 'pozajmica_uskoro_istice', 7 => 'pozajmica_istekla'],
            $kad,
            "Vremenska linija pozajmice:\n" . $red
        );
    }
}
