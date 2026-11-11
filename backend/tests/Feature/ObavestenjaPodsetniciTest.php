<?php

namespace Tests\Feature;

use App\Enums\PrimerakStatus;
use App\Enums\RezervacijaStatus;
use App\Enums\TipObavestenja;
use App\Mail\ObavestenjeMail;
use App\Models\Knjiga;
use App\Models\Notifikacija;
use App\Models\PrimerakKnjige;
use App\Models\Rezervacija;
use App\Models\User;
use App\Models\Zaduzivanje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Zakazana komanda `obavestenja:podsetnici` — tri obaveštenja o rokovima.
 *
 * Težište nije na tome da poruka ode, nego na tome da NE ode kad ne treba:
 * član koji je knjigu u međuvremenu preuzeo ili vratio ne sme da dobije
 * podsetnik, a ponovno pokretanje komande ne sme da pošalje drugu poruku o
 * istom događaju.
 */
class ObavestenjaPodsetniciTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['obavestenja.mejl' => true]);
    }

    private function clan(string $sufiks = '1'): User
    {
        return User::create([
            'ime' => 'Pera', 'prezime' => 'Perić',
            'email' => "pera{$sufiks}@example.com",
            'password' => Hash::make('tajna123'),
            'role' => 'clan', 'telefon' => '+381600000000', 'adresa' => 'Nis',
            'broj_clanske_karte' => "CLAN-000{$sufiks}",
        ]);
    }

    private function primerak(string $inv = '1'): PrimerakKnjige
    {
        $knjiga = Knjiga::create([
            'in_broj' => $inv, 'signatura' => $inv, 'datum' => '2026-01-01',
            'autor' => 'Ivo Andrić', 'naziv_knjige' => 'Na Drini ćuprija',
            'mesto_izdavanja' => 'Beograd', 'godina_izdavanja' => 1945,
            'izdavac' => 'Prosveta', 'nacin_nabavke' => 'Kupovina',
        ]);

        return PrimerakKnjige::create([
            'knjiga_id' => $knjiga->id, 'inventarski_broj' => $inv,
            'signatura' => $inv, 'status' => PrimerakStatus::Zaduzena->value,
        ]);
    }

    private function odobrenaRezervacija(User $clan, ?string $rokPreuzimanja = null): Rezervacija
    {
        $primerak = $this->primerak();

        return Rezervacija::create([
            'user_id'           => $clan->id,
            'knjiga_id'         => $primerak->knjiga_id,
            'primerak_id'       => $primerak->id,
            'status'            => RezervacijaStatus::Odobrena,
            'datum_rezervacije' => now()->subDays(4),
            'datum_odobrenja'   => now()->subDays(4),
            'rok_preuzimanja'   => $rokPreuzimanja ?? now()->addDay(),
        ]);
    }

    private function zaduzivanje(User $clan, string $rokVracanja, ?string $vraceno = null, string $inv = '1'): Zaduzivanje
    {
        return Zaduzivanje::create([
            'user_id'         => $clan->id,
            'primerak_id'     => $this->primerak($inv)->id,
            'datum_izdavanja' => now()->subDays(10),
            'rok_vracanja'    => $rokVracanja,
            'datum_vracanja'  => $vraceno,
        ]);
    }

    /**
     * Ceo dnevni + satni posao oko rokova.
     *
     * Dve komande, jer se pokreću različitom učestalošću: podsetnik „ističe
     * sutra" jednom dnevno ujutru, a istek roka na svaki sat (rok nosi i sat,
     * pa bi dnevni prolaz istek uhvatio tek sutradan).
     */
    private function pokreni(): void
    {
        $this->artisan('obavestenja:podsetnici')->assertSuccessful();
        $this->artisan('obavestenja:istekle-pozajmice')->assertSuccessful();
    }

    // ═══════════════════════════════════════════════════════════════════
    // ROK PREUZIMANJA REZERVACIJE
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function podsetnik_da_rok_preuzimanja_istice_sutra(): void
    {
        $clan = $this->clan();
        $rez  = $this->odobrenaRezervacija($clan, now()->addDay());

        $this->pokreni();

        $n = Notifikacija::where('tip', TipObavestenja::RezervacijaUskoroIstice->value)->firstOrFail();
        $this->assertSame($clan->id, $n->user_id);
        $this->assertSame($rez->id, $n->rezervacija_id);
        $this->assertStringContainsString(
            'rok za preuzimanje knjige „Na Drini ćuprija“ koju ste rezervisali dana '
            . now()->subDays(4)->format('d.m.Y.') . ' ističe sutra, ' . now()->addDay()->format('d.m.Y.'),
            $n->poruka
        );

        Mail::assertQueued(ObavestenjeMail::class, fn ($m) => $m->tip === TipObavestenja::RezervacijaUskoroIstice);
    }

    /** @test */
    public function nema_podsetnika_ako_je_clan_vec_preuzeo_knjigu(): void
    {
        $clan = $this->clan();
        $rez  = $this->odobrenaRezervacija($clan, now()->addDay());

        // Preuzeta = knjiga je već kod člana; podsećanje na preuzimanje nema smisla.
        $rez->update(['status' => RezervacijaStatus::Preuzeta]);

        $this->pokreni();

        $this->assertDatabaseCount('notifikacije', 0);
        Mail::assertNothingQueued();
    }

    /** @test */
    public function nema_podsetnika_za_rok_koji_nije_sutra(): void
    {
        $clan = $this->clan();
        $this->odobrenaRezervacija($clan, now()->addDays(3));

        $this->pokreni();

        $this->assertDatabaseCount('notifikacije', 0);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ROK POZAJMICE
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function podsetnik_da_rok_pozajmice_istice_sutra(): void
    {
        $clan        = $this->clan();
        $zaduzivanje = $this->zaduzivanje($clan, now()->addDay()->toDateString());

        $this->pokreni();

        $n = Notifikacija::where('tip', TipObavestenja::PozajmicaUskoroIstice->value)->firstOrFail();
        $this->assertSame($zaduzivanje->id, $n->zaduzivanje_id);
        $this->assertStringContainsString(
            'rok pozajmice knjige „Na Drini ćuprija“ koju ste zadužili dana '
            . now()->subDays(10)->format('d.m.Y.') . ' ističe sutra, ' . now()->addDay()->format('d.m.Y.'),
            $n->poruka
        );

        Mail::assertQueued(ObavestenjeMail::class, fn ($m) => $m->tip === TipObavestenja::PozajmicaUskoroIstice);
    }

    /** @test */
    public function istekao_rok_pozajmice_trazi_da_se_knjiga_vrati(): void
    {
        $clan = $this->clan();
        $this->zaduzivanje($clan, now()->toDateString());

        $this->pokreni();

        $n = Notifikacija::where('tip', TipObavestenja::PozajmicaIstekla->value)->firstOrFail();
        $this->assertStringContainsString('je istekao danas.', $n->poruka);
        $this->assertStringContainsString(
            'Molimo vas da knjigu vratite u biblioteku u što kraćem periodu, hvala unapred!',
            $n->poruka
        );

        Mail::assertQueued(ObavestenjeMail::class, fn ($m) => $m->tip === TipObavestenja::PozajmicaIstekla);
    }

    /** @test */
    public function zakasneli_prolaz_ne_tvrdi_da_je_rok_istekao_danas(): void
    {
        // Server nije radio nekoliko dana; poruka i dalje mora da ode, ali
        // „danas" bi bilo netačno.
        $clan = $this->clan();
        $this->zaduzivanje($clan, now()->subDays(3)->toDateString());

        $this->pokreni();

        $n = Notifikacija::where('tip', TipObavestenja::PozajmicaIstekla->value)->firstOrFail();
        $this->assertStringContainsString('je istekao dana ' . now()->subDays(3)->format('d.m.Y.'), $n->poruka);
        $this->assertStringNotContainsString('danas', $n->poruka);
    }

    /** @test */
    public function vracena_knjiga_ne_dobija_nijedno_obavestenje(): void
    {
        $clan = $this->clan();

        // Rok bi bio i „sutra" i „prošao", ali knjiga je vraćena.
        $this->zaduzivanje($clan, now()->addDay()->toDateString(), now()->toDateString(), '1');
        $this->zaduzivanje($clan, now()->subDay()->toDateString(), now()->toDateString(), '2');

        $this->pokreni();

        $this->assertDatabaseCount('notifikacije', 0);
        Mail::assertNothingQueued();
    }

    // ═══════════════════════════════════════════════════════════════════
    // PONOVLJENO POKRETANJE
    // ═══════════════════════════════════════════════════════════════════

    /** @test */
    public function ponovno_pokretanje_ne_salje_isto_obavestenje_dva_puta(): void
    {
        $clan = $this->clan();
        $this->odobrenaRezervacija($clan, now()->addDay());
        $this->zaduzivanje($clan, now()->addDay()->toDateString(), null, '2');
        $this->zaduzivanje($clan, now()->subDay()->toDateString(), null, '3');

        $this->pokreni();
        $this->pokreni();
        $this->pokreni();

        // Tri različita događaja → tačno tri obaveštenja i tri mejla.
        $this->assertDatabaseCount('notifikacije', 3);
        Mail::assertQueuedCount(3);
    }

    /** @test */
    public function dva_clana_sa_zaduzenjima_dobijaju_svaki_svoje(): void
    {
        // Zaduženje ne mora da ima rezervaciju iza sebe. Dok su se takva
        // obaveštenja vezivala samo za rezervaciju, sva su padala na
        // rezervacija_id = NULL i drugi član bi „nasledio" tuđe obaveštenje.
        $prvi  = $this->clan('1');
        $drugi = $this->clan('2');

        $this->zaduzivanje($prvi,  now()->addDay()->toDateString(), null, '1');
        $this->zaduzivanje($drugi, now()->addDay()->toDateString(), null, '2');

        $this->pokreni();

        $this->assertDatabaseCount('notifikacije', 2);
        $this->assertSame(1, Notifikacija::where('user_id', $prvi->id)->count());
        $this->assertSame(1, Notifikacija::where('user_id', $drugi->id)->count());
        Mail::assertQueuedCount(2);
    }
}
