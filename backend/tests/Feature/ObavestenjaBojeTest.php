<?php

namespace Tests\Feature;

use App\Enums\TipObavestenja;
use App\Models\Notifikacija;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Boja obaveštenja u aplikaciji.
 *
 * Ista podela (App\Enums\TipObavestenja::boja) mora da se vidi na tri mesta:
 * na brojaču u zaglavlju, na spisku obaveštenja i na traci u mejlu. Ovde su
 * prva dva; traka u mejlu je u ObavestenjaMejlomTest.
 */
class ObavestenjaBojeTest extends TestCase
{
    use RefreshDatabase;

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

    private function obavestenje(User $clan, TipObavestenja $tip, int $preMinuta = 0): Notifikacija
    {
        $n = Notifikacija::create([
            'user_id'   => $clan->id,
            'tip'       => $tip->value,
            'poruka'    => 'Tekst obaveštenja.',
            'procitano' => false,
        ]);

        // created_at se popunjava automatski; za redosled ga pomeramo unazad.
        if ($preMinuta > 0) {
            $n->forceFill(['created_at' => now()->subMinutes($preMinuta)])->saveQuietly();
        }

        return $n;
    }

    /** @test */
    public function svaki_tip_ima_boju(): void
    {
        // Nov tip bez upisane boje bi u match-u bacio izuzetak — ovaj test to
        // hvata pre nego što obori spisak obaveštenja korisniku.
        foreach (TipObavestenja::cases() as $tip) {
            $this->assertContains($tip->boja(), ['uspeh', 'upozorenje', 'greska'], $tip->value);
            $this->assertNotSame('', $tip->naslov(), $tip->value);
        }
    }

    /** @test */
    public function brojac_u_zaglavlju_nosi_boju_najnovijeg_neprocitanog(): void
    {
        $clan = $this->clan();

        // Ključ je `->value`, a ne sam enum: PHP nizovi ne primaju enum kao ključ.
        $ocekivano = [
            TipObavestenja::RezervacijaOdobrena->value     => 'uspeh',
            TipObavestenja::RezervacijaOdbijena->value     => 'greska',
            TipObavestenja::RezervacijaIstekla->value      => 'upozorenje',
            TipObavestenja::RezervacijaUskoroIstice->value => 'upozorenje',
            TipObavestenja::PozajmicaUskoroIstice->value   => 'upozorenje',
            TipObavestenja::PozajmicaIstekla->value        => 'greska',
        ];

        foreach ($ocekivano as $tip => $boja) {
            Notifikacija::query()->delete();
            $this->obavestenje($clan, TipObavestenja::from($tip));

            $r = $this->actingAs($clan)->get('/');

            $r->assertOk();
            $r->assertSee('header-btn__badge header-btn__badge--' . $boja, false, "Pogrešna boja za {$tip}");
        }
    }

    /** @test */
    public function kod_vise_neprocitanih_boju_daje_poslednje_pristiglo(): void
    {
        $clan = $this->clan();

        // Starije: odbijena rezervacija (crveno). Novije: odobrena (zeleno).
        $this->obavestenje($clan, TipObavestenja::RezervacijaOdbijena, preMinuta: 30);
        $this->obavestenje($clan, TipObavestenja::RezervacijaOdobrena);

        $r = $this->actingAs($clan)->get('/');

        // Brojač odgovara na pitanje „šta se upravo desilo".
        $r->assertSee('header-btn__badge--uspeh', false);
        $r->assertDontSee('header-btn__badge--greska', false);
        $r->assertSee('>2</span>', false);
    }

    /** @test */
    public function procitana_obavestenja_ne_uticu_na_boju(): void
    {
        $clan = $this->clan();

        $procitano = $this->obavestenje($clan, TipObavestenja::RezervacijaOdobrena);
        $procitano->update(['procitano' => true]);

        $this->obavestenje($clan, TipObavestenja::PozajmicaUskoroIstice, preMinuta: 10);

        $r = $this->actingAs($clan)->get('/');

        $r->assertSee('header-btn__badge--upozorenje', false);
        $r->assertSee('>1</span>', false);
    }

    /** @test */
    public function spisak_obavestenja_boji_svaki_red_po_tipu(): void
    {
        $clan = $this->clan();

        $this->obavestenje($clan, TipObavestenja::RezervacijaOdobrena,   preMinuta: 30);
        $this->obavestenje($clan, TipObavestenja::PozajmicaUskoroIstice, preMinuta: 20);
        $this->obavestenje($clan, TipObavestenja::PozajmicaIstekla,      preMinuta: 10);

        $r = $this->actingAs($clan)->get('/notifikacije/moja');

        $r->assertOk();
        $r->assertSee('notifikacija--uspeh', false);
        $r->assertSee('notifikacija--upozorenje', false);
        $r->assertSee('notifikacija--greska', false);
    }

    /** @test */
    public function nepoznat_tip_ne_obara_spisak(): void
    {
        $clan = $this->clan();

        // Red upisan ručno ili zaostao iz starije verzije aplikacije.
        Notifikacija::create([
            'user_id'   => $clan->id,
            'tip'       => 'nesto_sto_vise_ne_postoji',
            'poruka'    => 'Stara poruka.',
            'procitano' => false,
        ]);

        $r = $this->actingAs($clan)->get('/notifikacije/moja');

        $r->assertOk();
        $r->assertSee('Stara poruka.');
        // Pada na crveno, umesto da izbaci izuzetak.
        $r->assertSee('notifikacija--greska', false);
    }
}
