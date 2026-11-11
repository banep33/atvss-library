<?php

namespace Tests\Feature;

use App\Models\Knjiga;
use App\Models\PrimerakKnjige;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test rendera svih stranica koje koriste Blade layout.
 *
 * Layout (layouts/app, layouts/admin) drži <head>, header, footer i skripte;
 * ako se neka stranica razveže od njega ili joj se izgubi @section('content'),
 * ovde puca odmah. Provera broja fioka mobilnog menija je regresioni gard:
 * pre prelaska na layout svaka stranica je imala SVOJU kopiju fioke sa istim
 * id-jem kao ona iz header partiala.
 */
class LayoutRenderTest extends TestCase
{
    use RefreshDatabase;

    private function korisnik(string $role): User
    {
        return User::create([
            'ime' => 'Test', 'prezime' => 'Korisnik',
            'email' => $role . '@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('tajna123'),
            'role' => $role, 'telefon' => '+381600000000', 'adresa' => 'Nis',
            'broj_clanske_karte' => 'CLAN-0001',
        ]);
    }

    private function knjiga(): Knjiga
    {
        $k = Knjiga::create([
            'in_broj' => '1', 'signatura' => '1', 'datum' => '2026-01-01',
            'autor' => 'Autor', 'naziv_knjige' => 'Naslov',
            'mesto_izdavanja' => 'Nis', 'godina_izdavanja' => 2020,
            'izdavac' => 'Izdavac', 'nacin_nabavke' => 'Kupovina',
            'slika' => 'images/default.png',
        ]);
        PrimerakKnjige::create(['knjiga_id' => $k->id, 'inventarski_broj' => '1', 'signatura' => '1', 'status' => 'slobodna']);

        return $k;
    }

    /** @test */
    public function clanske_stranice_se_renderuju(): void
    {
        $clan = $this->korisnik('clan');
        $k = $this->knjiga();

        foreach (['/', '/knjige/' . $k->id, '/profil', '/notifikacije/moja', '/zaduzene', '/info'] as $url) {
            $r = $this->actingAs($clan)->get($url);
            $this->assertSame(200, $r->status(), "Pao: {$url}");
            $r->assertSee('<header>', false);
            $r->assertSee('<footer>', false);
            // Fioka mobilnog menija sme da postoji TAČNO jednom (ranije dva puta).
            $this->assertSame(1, substr_count($r->getContent(), 'id="mobileNavDrawer"'), "Duplirana fioka: {$url}");
        }
    }

    /** @test */
    public function admin_stranice_se_renderuju(): void
    {
        $admin = $this->korisnik('admin');
        $this->knjiga();

        foreach (['/admin/rezervacije', '/admin/clanovi', '/admin/zanrovi', '/admin/inventarna-knjiga', '/admin/knjige/import'] as $url) {
            $r = $this->actingAs($admin)->get($url);
            $this->assertSame(200, $r->status(), "Pao: {$url}");
            $r->assertSee('<header>', false);
            $r->assertSee('<footer>', false);
        }
    }

    /**
     * Potpis tima nosi OBE varijante logotipa — belu za tamnu temu i tamnu za
     * svetlu. Prikaz bira CSS po klasi `dark` na <html>, pa markup mora da
     * sadrži oba <img> taga; ranije je postojala samo bela varijanta, koja je
     * u svetloj temi morala da stoji na tamnoj „pločici".
     *
     * @test
     */
    public function podnozje_nosi_obe_varijante_logotipa_tima(): void
    {
        $clan = $this->korisnik('clan');

        $r = $this->actingAs($clan)->get('/profil');

        $r->assertSee('footer-powered__logo--za-tamnu', false);
        $r->assertSee('footer-powered__logo--za-svetlu', false);
        $r->assertSee('images/vtsappsteam-logo.png', false);
        $r->assertSee('images/apps-team-dark.png', false);
    }
}
