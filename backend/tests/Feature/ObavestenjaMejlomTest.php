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
use App\Services\RezervacijaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Obaveštenja e-poštom uz ona u aplikaciji.
 *
 * Oba kanala izlaze iz istog mesta (NotifikacijaService), pa se ovde proverava
 * da mejl nosi ISTU rečenicu koja je upisana u tabelu `notifikacije` — i,
 * važnije, da ne može da obori posao: slanje se dešava unutar transakcije
 * koja odobrava rezervaciju.
 *
 * Podsetnici o rokovima su u ObavestenjaPodsetniciTest.
 */
class ObavestenjaMejlomTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['obavestenja.mejl' => true]);
    }

    private function clan(): User
    {
        return User::create([
            'ime' => 'Pera', 'prezime' => 'Perić',
            'email' => 'pera@example.com',
            'password' => Hash::make('tajna123'),
            'role' => 'clan', 'telefon' => '+381600000000', 'adresa' => 'Nis',
            'broj_clanske_karte' => 'CLAN-0001',
        ]);
    }

    private function knjigaSaPrimerkom(): Knjiga
    {
        $k = Knjiga::create([
            'in_broj' => '1', 'signatura' => '1', 'datum' => '2026-01-01',
            'autor' => 'Ivo Andrić', 'naziv_knjige' => 'Na Drini ćuprija',
            'mesto_izdavanja' => 'Beograd', 'godina_izdavanja' => 1945,
            'izdavac' => 'Prosveta', 'nacin_nabavke' => 'Kupovina',
        ]);

        PrimerakKnjige::create([
            'knjiga_id' => $k->id, 'inventarski_broj' => '1',
            'signatura' => '1', 'status' => PrimerakStatus::Slobodna->value,
        ]);

        return $k;
    }

    private function rezervacija(User $clan, Knjiga $knjiga): Rezervacija
    {
        return Rezervacija::create([
            'user_id'           => $clan->id,
            'knjiga_id'         => $knjiga->id,
            'status'            => RezervacijaStatus::NaCekanju,
            'datum_rezervacije' => now()->subDays(2),
        ]);
    }

    /** @test */
    public function odobrenje_salje_mejl_uz_obavestenje_u_aplikaciji(): void
    {
        $clan        = $this->clan();
        $rezervacija = $this->rezervacija($clan, $this->knjigaSaPrimerkom());

        app(RezervacijaService::class)->odobri($rezervacija);

        // Kanal 1 — u aplikaciji
        $notifikacija = Notifikacija::where('rezervacija_id', $rezervacija->id)->firstOrFail();
        $this->assertSame(TipObavestenja::RezervacijaOdobrena->value, $notifikacija->tip);
        $this->assertStringContainsString('„Na Drini ćuprija“', $notifikacija->poruka);
        $this->assertStringContainsString(
            'koju ste poslali dana ' . now()->subDays(2)->format('d.m.Y.') . ' odobrena.',
            $notifikacija->poruka
        );
        $this->assertStringContainsString('Rok za preuzimanje knjige u biblioteci je 5 dana.', $notifikacija->poruka);

        // Kanal 2 — ISTA rečenica na e-poštu člana
        Mail::assertQueued(ObavestenjeMail::class, function (ObavestenjeMail $mail) use ($clan, $notifikacija) {
            return $mail->hasTo($clan->email)
                && $mail->tip === TipObavestenja::RezervacijaOdobrena
                && $mail->poruka === $notifikacija->poruka;
        });
    }

    /** @test */
    public function odbijanje_nosi_datum_slanja_zahteva(): void
    {
        $clan        = $this->clan();
        $rezervacija = $this->rezervacija($clan, $this->knjigaSaPrimerkom());

        app(RezervacijaService::class)->odbij($rezervacija);

        $notifikacija = Notifikacija::where('rezervacija_id', $rezervacija->id)->firstOrFail();
        $this->assertSame(TipObavestenja::RezervacijaOdbijena->value, $notifikacija->tip);
        $this->assertStringContainsString(
            'koju ste poslali dana ' . now()->subDays(2)->format('d.m.Y.') . ' odbijena.',
            $notifikacija->poruka
        );

        Mail::assertQueued(ObavestenjeMail::class);
    }

    /** @test */
    public function iskljucen_prekidac_gasi_mejl_ali_ne_i_obavestenje_u_aplikaciji(): void
    {
        config(['obavestenja.mejl' => false]);

        $clan        = $this->clan();
        $rezervacija = $this->rezervacija($clan, $this->knjigaSaPrimerkom());

        app(RezervacijaService::class)->odobri($rezervacija);

        $this->assertDatabaseHas('notifikacije', ['rezervacija_id' => $rezervacija->id]);
        Mail::assertNothingQueued();
    }

    /** @test */
    public function pad_slanja_mejla_ne_obara_odobrenje_rezervacije(): void
    {
        $clan        = $this->clan();
        $rezervacija = $this->rezervacija($clan, $this->knjigaSaPrimerkom());

        // Server za slanje pada — istekla lozinka, nema mreže, svejedno.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP nedostupan'));

        app(RezervacijaService::class)->odobri($rezervacija);

        // Posao biblioteke je odrađen uprkos padu pogodnosti.
        $rezervacija->refresh();
        $this->assertSame(RezervacijaStatus::Odobrena, $rezervacija->status);
        $this->assertNotNull($rezervacija->primerak_id);
        $this->assertDatabaseHas('notifikacije', ['rezervacija_id' => $rezervacija->id]);
        $this->assertDatabaseHas('primerci_knjiga', [
            'id'     => $rezervacija->primerak_id,
            'status' => PrimerakStatus::Rezervisana->value,
        ]);
    }

    /** @test */
    public function naslov_mejla_je_isti_tekst_koji_stoji_i_u_telu(): void
    {
        foreach (TipObavestenja::cases() as $tip) {
            $mail = new ObavestenjeMail(ime: 'Pera Perić', tip: $tip, poruka: 'Tekst poruke.');

            $this->assertSame($tip->naslov(), $mail->envelope()->subject);
            $this->assertStringContainsString($tip->naslov(), $mail->render());
        }
    }

    /** @test */
    public function telo_mejla_nosi_obracanje_poruku_i_pozdrav(): void
    {
        $mail = new ObavestenjeMail(
            ime:    'Pera Perić',
            tip:    TipObavestenja::RezervacijaOdobrena,
            poruka: 'Obaveštavamo vas da je rezervacija odobrena.',
        );

        $html = $mail->render();

        $this->assertStringContainsString('Poštovani/a Pera Perić,', $html);
        $this->assertStringContainsString('Obaveštavamo vas da je rezervacija odobrena.', $html);
        $this->assertStringContainsString('Srdačan pozdrav,', $html);
        $this->assertStringContainsString('Ovo je automatska poruka, na nju se ne odgovara.', $html);
        $this->assertStringContainsString(config('obavestenja.naziv_biblioteke'), $html);
    }

    /** @test */
    public function traka_u_mejlu_prati_boju_obavestenja(): void
    {
        $boje = [
            TipObavestenja::RezervacijaOdobrena->value     => '#48bb78', // zeleno
            TipObavestenja::RezervacijaUskoroIstice->value => '#ed8936', // narandžasto
            TipObavestenja::RezervacijaOdbijena->value     => '#f56565', // crveno
        ];

        foreach ($boje as $tip => $boja) {
            $html = (new ObavestenjeMail(
                ime: 'Pera',
                tip: TipObavestenja::from($tip),
                poruka: 'Tekst.',
            ))->render();

            $this->assertStringContainsString($boja, $html, "Pogrešna boja za {$tip}");
        }
    }

    /** @test */
    public function logotip_putuje_sa_porukom_a_ne_kao_link_na_sajt(): void
    {
        $this->assertFileExists(public_path('images/akademija-logo-mejl.png'));

        $html = (new ObavestenjeMail(
            ime: 'Pera',
            tip: TipObavestenja::RezervacijaOdobrena,
            poruka: 'Tekst.',
        ))->render();

        /* Ovo je bitno svojstvo: slika je UNUTAR poruke, ne skida se sa sajta.
           Sa udaljene adrese se ne bi videla dok aplikacija radi lokalno, a i
           na serveru je većina klijenata blokira dok korisnik ne klikne
           „prikaži slike".

           Oblik zavisi od toga kako se poruka gradi: pri stvarnom slanju
           Symfony pravi prilog i upisuje `cid:`, a render() (isti put koji
           koristi pregled u pregledaču) ugrađuje sliku kao data: URI. Oba su
           ispravna — pogrešno bi bilo `src="http…"`. */
        $this->assertMatchesRegularExpression('/src="(cid:|data:image)/', $html);
        $this->assertStringNotContainsString('<img src="http', $html);
    }
}
