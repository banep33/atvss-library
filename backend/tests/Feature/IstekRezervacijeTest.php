<?php

namespace Tests\Feature;

use App\Console\Commands\CleanupIstekleRezervacije;
use App\Enums\PrimerakStatus;
use App\Enums\RezervacijaStatus;
use App\Models\Knjiga;
use App\Models\Notifikacija;
use App\Models\PrimerakKnjige;
use App\Models\Rezervacija;
use App\Models\User;
use App\Services\RezervacijaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Istek odobrene rezervacije: rok preuzimanja se računa pri odobravanju
 * (datum_odobrenja + 5 dana, kolona `rok_preuzimanja`), a zakazana komanda
 * rezervacije:cleanup-istekle prevodi protekle rezervacije u status Istekla,
 * oslobađa primerak i šalje TAČNO JEDNO obaveštenje korisniku.
 */
class IstekRezervacijeTest extends TestCase
{
    use RefreshDatabase;

    private function clan(string $email = 'clan@example.com'): User
    {
        return User::create([
            'ime'      => 'Pera',
            'prezime'  => 'Perić',
            'email'    => $email,
            'password' => bcrypt('tajna123'),
            'role'     => 'clan',
        ]);
    }

    private function knjigaSaPrimerkom(int $inventarskiBroj = 1001): array
    {
        $knjiga = Knjiga::create(['naziv_knjige' => 'Na Drini ćuprija', 'autor' => 'Ivo Andrić']);

        $primerak = PrimerakKnjige::create([
            'knjiga_id'        => $knjiga->id,
            'inventarski_broj' => $inventarskiBroj,
            'status'           => PrimerakStatus::Slobodna,
        ]);

        return [$knjiga, $primerak];
    }

    /** @test */
    public function odobravanje_upisuje_rok_preuzimanja_pet_dana_od_odobrenja(): void
    {
        [$knjiga] = $this->knjigaSaPrimerkom();
        $clan     = $this->clan();

        $rezervacija = app(RezervacijaService::class)->kreiraj($clan->id, $knjiga->id);
        app(RezervacijaService::class)->odobri($rezervacija);

        $rezervacija->refresh();

        $this->assertSame(RezervacijaStatus::Odobrena, $rezervacija->status);
        $this->assertNotNull($rezervacija->datum_odobrenja);
        $this->assertSame(
            $rezervacija->datum_odobrenja->copy()
                ->addDays(RezervacijaService::DANA_ZA_PREUZIMANJE)
                ->toDateTimeString(),
            $rezervacija->rok_preuzimanja->toDateTimeString()
        );
    }

    /** @test */
    public function protekao_rok_prevodi_rezervaciju_u_isteklu_i_oslobadja_primerak(): void
    {
        [$knjiga, $primerak] = $this->knjigaSaPrimerkom();
        $clan                = $this->clan();

        $rezervacija = app(RezervacijaService::class)->kreiraj($clan->id, $knjiga->id);
        app(RezervacijaService::class)->odobri($rezervacija);

        // Primerak je pri odobravanju zaključan za ovu rezervaciju.
        $this->assertSame(PrimerakStatus::Rezervisana, $primerak->refresh()->status);

        // Rok pomeramo u prošlost i puštamo zakazanu komandu.
        $rezervacija->refresh()->update(['rok_preuzimanja' => now()->subMinute()]);
        $this->artisan('rezervacije:cleanup-istekle')->assertSuccessful();

        $this->assertSame(RezervacijaStatus::Istekla, $rezervacija->refresh()->status);
        $this->assertSame(PrimerakStatus::Slobodna, $primerak->refresh()->status);
    }

    /** @test */
    public function korisnik_dobija_tacno_jedno_obavestenje_o_isteku(): void
    {
        [$knjiga] = $this->knjigaSaPrimerkom();
        $clan     = $this->clan();

        $rezervacija = app(RezervacijaService::class)->kreiraj($clan->id, $knjiga->id);
        app(RezervacijaService::class)->odobri($rezervacija);
        $rezervacija->refresh()->update(['rok_preuzimanja' => now()->subMinute()]);

        // Dva prolaza: zakazana komanda i ručno dugme u administraciji.
        $this->artisan('rezervacije:cleanup-istekle')->assertSuccessful();
        app(CleanupIstekleRezervacije::class)->obradi();

        $this->assertSame(1, Notifikacija::where('rezervacija_id', $rezervacija->id)
            ->where('tip', 'rezervacija_istekla')
            ->count());
    }

    /** @test */
    public function rezervacija_u_roku_ostaje_odobrena(): void
    {
        [$knjiga, $primerak] = $this->knjigaSaPrimerkom();
        $clan                = $this->clan();

        $rezervacija = app(RezervacijaService::class)->kreiraj($clan->id, $knjiga->id);
        app(RezervacijaService::class)->odobri($rezervacija);

        $this->artisan('rezervacije:cleanup-istekle')->assertSuccessful();

        $this->assertSame(RezervacijaStatus::Odobrena, $rezervacija->refresh()->status);
        $this->assertSame(PrimerakStatus::Rezervisana, $primerak->refresh()->status);
        $this->assertSame(0, Notifikacija::where('tip', 'rezervacija_istekla')->count());
    }

    /** @test */
    public function istek_oslobadja_samo_svoj_primerak(): void
    {
        // Dve rezervacije iste knjige, svaka sa svojim primerkom: istek prve
        // ne sme da oslobodi primerak zaključan za drugu.
        $knjiga = Knjiga::create(['naziv_knjige' => 'Seobe', 'autor' => 'Miloš Crnjanski']);

        $prvi = PrimerakKnjige::create([
            'knjiga_id' => $knjiga->id, 'inventarski_broj' => 2001, 'status' => PrimerakStatus::Slobodna,
        ]);
        $drugi = PrimerakKnjige::create([
            'knjiga_id' => $knjiga->id, 'inventarski_broj' => 2002, 'status' => PrimerakStatus::Slobodna,
        ]);

        $sluzba = app(RezervacijaService::class);
        $rezA   = $sluzba->kreiraj($this->clan('a@example.com')->id, $knjiga->id);
        $sluzba->odobri($rezA);
        $rezB = $sluzba->kreiraj($this->clan('b@example.com')->id, $knjiga->id);
        $sluzba->odobri($rezB);

        // Isteći sme samo rezervacija A.
        $rezA->refresh()->update(['rok_preuzimanja' => now()->subMinute()]);
        $this->artisan('rezervacije:cleanup-istekle')->assertSuccessful();

        $primerakA = PrimerakKnjige::find($rezA->refresh()->primerak_id);
        $primerakB = PrimerakKnjige::find($rezB->refresh()->primerak_id);

        $this->assertSame(RezervacijaStatus::Istekla, $rezA->status);
        $this->assertSame(RezervacijaStatus::Odobrena, $rezB->status);
        $this->assertSame(PrimerakStatus::Slobodna, $primerakA->status);
        $this->assertSame(PrimerakStatus::Rezervisana, $primerakB->status);

        $this->assertNotSame($prvi->id, $drugi->id); // sanity: dva različita primerka
    }

    // ── PRIKAZ BIBLIOTEKARU ───────────────────────────────────────────────

    private function bibliotekar(string $email = 'bib@example.com'): User
    {
        return User::create([
            'ime'      => 'Bib',
            'prezime'  => 'Bibić',
            'email'    => $email,
            'password' => bcrypt('tajna123'),
            'role'     => 'bibliotekar',
        ]);
    }

    private function isteklaRezervacija(string $email, int $inventarskiBroj): Rezervacija
    {
        [$knjiga] = $this->knjigaSaPrimerkom($inventarskiBroj);

        $sluzba      = app(RezervacijaService::class);
        $rezervacija = $sluzba->kreiraj($this->clan($email)->id, $knjiga->id);
        $sluzba->odobri($rezervacija);
        $rezervacija->refresh()->update(['rok_preuzimanja' => now()->subDays(2)]);

        $this->artisan('rezervacije:cleanup-istekle')->assertSuccessful();

        return $rezervacija->refresh();
    }

    /** @test */
    public function tabela_odobrenih_prikazuje_istekli_rok_i_kantu(): void
    {
        $rezervacija = $this->isteklaRezervacija('prikaz@example.com', 5001);

        $html = $this->actingAs($this->bibliotekar())
            ->get('/admin/rezervacije')->assertOk()->getContent();

        $this->assertStringContainsString('Datum isteka', $html);
        $this->assertStringContainsString('deadline isteklo', $html);   // crveni okvir
        $this->assertStringContainsString('Rok je istekao', $html);
        $this->assertStringContainsString(now()->subDays(2)->format('d.m.Y'), $html);
        $this->assertStringContainsString("obrisiRezervaciju({$rezervacija->id}, 'odobrena')", $html);
        // Istekla rezervacija se više ne može zadužiti — primerak je oslobođen.
        $this->assertStringNotContainsString("zaduziKnjigu({$rezervacija->id}", $html);
    }

    /** @test */
    public function uklanjanje_isteklog_reda_brise_zapis_bez_laznog_otkazivanja(): void
    {
        $rezervacija = $this->isteklaRezervacija('brisanje@example.com', 5002);
        $bibliotekar = $this->bibliotekar('bib2@example.com');

        $this->actingAs($bibliotekar)
            ->deleteJson("/api/rezervacije/{$rezervacija->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        // Zapis se briše — ne prepisuje se u „otkazana", pa se ne pojavljuje
        // ni kao obaveštenje da je korisnik otkazao rezervaciju.
        $this->assertDatabaseMissing('rezervacije', ['id' => $rezervacija->id]);

        $html = $this->actingAs($bibliotekar)
            ->get('/admin/rezervacije')->assertOk()->getContent();

        $this->assertStringNotContainsString('je otkazao rezervaciju', $html);
        $this->assertStringNotContainsString("obrisiRezervaciju({$rezervacija->id}, 'odobrena')", $html);

        // Obaveštenje korisniku preživljava brisanje rezervacije.
        $this->assertSame(1, Notifikacija::where('user_id', $rezervacija->user_id)
            ->where('tip', 'rezervacija_istekla')
            ->count());
    }

    /** @test */
    public function nezavrsena_rezervacija_se_ne_moze_obrisati(): void
    {
        [$knjiga] = $this->knjigaSaPrimerkom(5004);

        $sluzba      = app(RezervacijaService::class);
        $rezervacija = $sluzba->kreiraj($this->clan('aktivna@example.com')->id, $knjiga->id);
        $sluzba->odobri($rezervacija);   // rok tek ističe — nije završena

        $this->actingAs($this->bibliotekar('bib4@example.com'))
            ->deleteJson("/api/rezervacije/{$rezervacija->id}")
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('rezervacije', ['id' => $rezervacija->id]);
    }

    /** @test */
    public function uklanjanje_pre_prolaza_komande_ne_ostavlja_zakljucan_primerak(): void
    {
        // Rok je istekao, ali dnevna komanda još nije odradila prolaz —
        // rezervacija je i dalje Odobrena i drži zaključan primerak.
        [$knjiga, $primerak] = $this->knjigaSaPrimerkom(5003);

        $sluzba      = app(RezervacijaService::class);
        $rezervacija = $sluzba->kreiraj($this->clan('rana@example.com')->id, $knjiga->id);
        $sluzba->odobri($rezervacija);
        $rezervacija->refresh()->update(['rok_preuzimanja' => now()->subDay()]);

        $this->assertSame(PrimerakStatus::Rezervisana, $primerak->refresh()->status);

        $this->actingAs($this->bibliotekar('bib3@example.com'))
            ->deleteJson("/api/rezervacije/{$rezervacija->id}")
            ->assertOk();

        $this->assertSame(PrimerakStatus::Slobodna, $primerak->refresh()->status);
        $this->assertDatabaseMissing('rezervacije', ['id' => $rezervacija->id]);
    }
}
