<?php

namespace Tests\Feature;

use App\Models\Knjiga;
use App\Models\PrimerakKnjige;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresioni gard za zaglavlje i za pretragu unutar kartice sekcije.
 *
 * Pokriva ono što se ne vidi iz koda na prvi pogled:
 *  - logotip akademije ima ČETIRI varijante (dva pisma × dve teme) i server
 *    bira pravu po kolačiću `pismo`, a CSS po temi;
 *  - dugme za pismo ispisuje natpis u pismu NA KOJE prebacuje;
 *  - pretraga i filtriranje stoje u kartici sekcije (`.controls-slot`), a ne
 *    kao zasebna kartica iznad nje.
 */
class ZaglavljeIPretragaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'ime' => 'Ana', 'prezime' => 'Anić',
            'email' => uniqid() . '@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('tajna123'),
            'role' => 'admin',
        ]);
    }

    public function test_zaglavlje_u_latinici_nosi_obe_varijante_logotipa(): void
    {
        $html = $this->actingAs($this->admin())->get('/')->getContent();

        // Latinična varijanta, u obe teme.
        $this->assertStringContainsString('akademija-logo-latinica-crni.svg', $html);
        $this->assertStringContainsString('akademija-logo-beli-latinica.png', $html);
        $this->assertStringContainsString('header-logo--za-svetlu', $html);
        $this->assertStringContainsString('header-logo--za-tamnu', $html);

        // Logotip je link ka početnoj, i kada zaglavlje nema naslov stranice.
        $this->assertStringContainsString('class="title-link"', $html);

        // Natpis dugmeta je u pismu na koje prebacuje — i u zaglavlju
        // (skraćeno) i u mobilnoj fioci (pun naziv).
        $this->assertStringContainsString('>Ћир<', $html);
        $this->assertStringContainsString('js-pismo-naziv">Ћирилица<', $html);

        // Sunce je sopstveni crtež, ne „fa-sun" (koji liči na zupčanik).
        $this->assertStringContainsString('ikona-sunce', $html);
        $this->assertStringNotContainsString('fa-sun', $html);

        // Inventarna knjiga je stavka zaglavlja; potpis tima vodi na sajt tima.
        $this->assertStringContainsString('href="/admin/inventarna-knjiga" class="header-btn"', $html);
        $this->assertStringContainsString('vtsappsteam.webflow.io', $html);
    }

    public function test_zaglavlje_u_cirilici_nosi_cirilicni_logotip_i_natpis(): void
    {
        $html = $this->actingAs($this->admin())
            ->withUnencryptedCookie('pismo', 'cyr')
            ->get('/')
            ->getContent();

        $this->assertStringContainsString('akademija-logo-odseknis-cirilica-crni.svg', $html);
        $this->assertStringContainsString('akademijanis-logo-beo-cirilica.png', $html);
        $this->assertStringContainsString('>Lat<', $html);
        $this->assertStringContainsString('js-pismo-naziv">Latinica<', $html);
    }

    public function test_pretraga_stoji_u_kartici_sekcije(): void
    {
        $admin = $this->admin();

        // Rezervacije: po jedan prihvatnik u svakom tabu koji ima pretragu
        // (na čekanju, odobrene, odbijene, zadužene) — ne i u „Kašnjenjima",
        // „Arhivi" i „Održavanju".
        $html = $this->actingAs($admin)->get('/admin/rezervacije')->getContent();
        $this->assertSame(4, substr_count($html, 'class="controls-slot"'));
        $this->assertStringContainsString('id="rezervacijeKontrole"', $html);

        // Blok pretrage stiže SA SERVERA u kartici prvog (podrazumevano
        // otvorenog) taba. Kada ga je tamo spuštala tek skripta, pregledač bi
        // ga prvo iscrtao iznad kartice — pretraga bi „zatreperila".
        $this->assertMatchesRegularExpression(
            '/id="tab_na_cekanju".*?<div class="controls-slot">\s*<div class="controls-wrapper" id="rezervacijeKontrole"/s',
            $html
        );

        // Članovi: pretraga je u kartici spiska, a telefon ima proveru formata.
        $html = $this->actingAs($admin)->get('/admin/clanovi')->getContent();
        $this->assertStringContainsString('controls-slot', $html);
        $this->assertStringContainsString('data-telefon', $html);

        // Katalog: jedna kartica sa pretragom i sekcijama knjiga.
        $html = $this->actingAs($admin)->get('/')->getContent();
        $this->assertStringContainsString('katalog-kartica', $html);
        $this->assertStringContainsString('controls-slot', $html);
    }

    /**
     * Brojač nepročitanih notifikacija ne sme da nosi opštu klasu `.badge` —
     * njome stranice stilizuju sopstvene plave pločice (admin.css,
     * zaduzene.css), pa je isti brojač na stranici „Zadužene knjige" postajao
     * plava pločica, a drugde ostajao obična cifra.
     */
    public function test_brojac_notifikacija_ne_koristi_opstu_klasu_badge(): void
    {
        $clan = User::create([
            'ime' => 'Milan', 'prezime' => 'Ristić',
            'email' => uniqid() . '@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('tajna123'),
            'role' => 'clan', 'broj_clanske_karte' => 'CLAN-001',
        ]);

        foreach (['/', '/zaduzene', '/notifikacije/moja'] as $url) {
            $html = $this->actingAs($clan)->get($url)->getContent();

            $this->assertStringNotContainsString('id="badge" class="badge"', $html, "opšta klasa na: {$url}");
            $this->assertStringNotContainsString('class="badge"', $html, "opšta klasa na: {$url}");
        }
    }

    public function test_inventarna_knjiga_ima_pretragu_i_kolonu_akcije(): void
    {
        $knjiga = Knjiga::create([
            'in_broj' => '1', 'signatura' => '1', 'datum' => '2026-01-01',
            'autor' => 'Autor', 'naziv_knjige' => 'Naslov',
            'mesto_izdavanja' => 'Niš', 'godina_izdavanja' => 2020,
            'izdavac' => 'Izdavač', 'nacin_nabavke' => 'Kupovina',
        ]);
        PrimerakKnjige::create([
            'knjiga_id' => $knjiga->id, 'inventarski_broj' => '1',
            'signatura' => '1', 'status' => 'slobodna',
        ]);

        $html = $this->actingAs($this->admin())->get('/admin/inventarna-knjiga')->getContent();

        foreach (['pretragaNaslov', 'pretragaAutor', 'pretragaInvBroj'] as $polje) {
            $this->assertStringContainsString($polje, $html, "nedostaje polje pretrage: {$polje}");
        }

        $this->assertStringContainsString('>Akcije<', $html);
        $this->assertStringContainsString('/knjige/' . $knjiga->id, $html);

        // Paginacija ostaje; sopstvenih skrol-traka tabele više nema.
        $this->assertStringContainsString('data-paginate="10"', $html);
        $this->assertStringNotContainsString('top-scroll', $html);
    }

    /**
     * Stanje teme mora da stigne SA PRVIM ISCRTAVANJEM, a ne da ga skripta
     * ispravlja posle njega: u tamnom režimu se na svakoj promeni stranice
     * na tren videla ikonica sunca, pa tek onda mesec.
     */
    public function test_tema_stize_pre_prvog_iscrtavanja(): void
    {
        $admin = $this->admin();

        foreach (['/login', '/', '/admin/rezervacije'] as $ruta) {
            $odgovor = $ruta === '/login'
                ? $this->get($ruta)
                : $this->actingAs($admin)->get($ruta);

            $html = $odgovor->getContent();

            // Režim se upisuje na <html> pre <body>-ja; atribut u markup-u je
            // rezerva ako localStorage nije dostupan.
            $this->assertStringContainsString('data-tema="system"', $html, $ruta);
            $this->assertStringContainsString("d.setAttribute('data-tema',t)", $html, $ruta);

            $polozaj = strpos($html, "d.setAttribute('data-tema',t)");
            $this->assertLessThan(strpos($html, '<body'), $polozaj, "snippet mora u <head>: {$ruta}");
        }

        // Ikonicu bira CSS (sva tri stanja su u markup-u), ne skripta.
        $html = $this->actingAs($admin)->get('/')->getContent();
        foreach (['tema-ikona--svetlo', 'tema-ikona--tamno', 'tema-ikona--sistem'] as $stanje) {
            $this->assertStringContainsString($stanje, $html);
        }
        $this->assertStringNotContainsString('id="themeToolIcon"', $html);
    }
}
