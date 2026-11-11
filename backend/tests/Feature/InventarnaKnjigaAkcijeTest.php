<?php

namespace Tests\Feature;

use App\Enums\PrimerakStatus;
use App\Models\Knjiga;
use App\Models\PrimerakKnjige;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Akcije nad knjigom sa stranice inventarne knjige.
 *
 * Kolona „Akcije" nosi tri ikonice — pogledaj, izmeni i obriši — a dugme
 * „Dodaj novu knjigu" stoji uz naslov (preseljeno sa početne strane). Sve
 * tri radnje idu preko istih /api/knjige ruta koje koristi i stranica
 * knjige, pa se ovde proverava i da brisanje zaista prolazi: knjiga
 * zavedena u inventarnoj knjizi UVEK ima bar jedan primerak, a raniji
 * gard je odbijao brisanje svake takve knjige.
 */
class InventarnaKnjigaAkcijeTest extends TestCase
{
    use RefreshDatabase;

    private function korisnik(string $role): User
    {
        return User::create([
            'ime' => 'Test', 'prezime' => 'Korisnik',
            'email' => $role . '@example.com',
            'password' => Hash::make('tajna123'),
            'role' => $role, 'telefon' => '+381600000000', 'adresa' => 'Nis',
            'broj_clanske_karte' => 'CLAN-0001',
        ]);
    }

    private function knjiga(string $status = 'slobodna'): Knjiga
    {
        $k = Knjiga::create([
            'in_broj' => '1', 'signatura' => '1', 'datum' => '2026-01-01',
            'autor' => 'Autor', 'naziv_knjige' => 'Naslov',
            'mesto_izdavanja' => 'Nis', 'godina_izdavanja' => 2020,
            'izdavac' => 'Izdavac', 'nacin_nabavke' => 'Kupovina',
        ]);

        PrimerakKnjige::create([
            'knjiga_id' => $k->id, 'inventarski_broj' => '1',
            'signatura' => '1', 'status' => $status,
        ]);

        return $k;
    }

    /** @test */
    public function stranica_nosi_dugme_za_dodavanje_i_tri_ikonice_akcija(): void
    {
        $bibliotekar = $this->korisnik('bibliotekar');
        $k = $this->knjiga();

        $r = $this->actingAs($bibliotekar)->get('/admin/inventarna-knjiga');
        $r->assertOk();

        // Dodavanje je preseljeno sa početne strane ovamo, uz naslov.
        $r->assertSee('Dodaj novu knjigu');
        $r->assertSee('openAddModal()', false);
        $r->assertSee('id="bookModal"', false);

        // Tri radnje, samo ikonice.
        $r->assertSee('href="/knjige/' . $k->id . '"', false);
        $r->assertSee('fa-eye', false);
        $r->assertSee('openEditModal(' . $k->id . ')', false);
        $r->assertSee('fa-pen', false);
        $r->assertSee('obrisiKnjigu(' . $k->id . ',', false);
        $r->assertSee('fa-trash-can', false);
    }

    /** @test */
    public function pocetna_strana_vise_ne_nosi_dugme_za_dodavanje(): void
    {
        $bibliotekar = $this->korisnik('bibliotekar');

        $r = $this->actingAs($bibliotekar)->get('/');
        $r->assertOk();
        $r->assertDontSee('Dodaj novu knjigu');
        $r->assertDontSee('openAddModal()', false);
        $r->assertDontSee('id="bookModal"', false);
    }

    /** @test */
    public function clan_vidi_samo_ikonicu_za_pregled(): void
    {
        $clan = $this->korisnik('clan');
        $k = $this->knjiga();

        $r = $this->actingAs($clan)->get('/admin/inventarna-knjiga');
        $r->assertOk();

        $r->assertSee('fa-eye', false);
        $r->assertDontSee('openEditModal(', false);
        $r->assertDontSee('obrisiKnjigu(', false);
        // Natpis dugmeta se pominje i u komentaru unutar <style>, pa se
        // odsustvo proverava preko markupa, a ne preko teksta.
        $r->assertDontSee('openAddModal()', false);
        $r->assertDontSee('id="bookModal"', false);
    }

    /** @test */
    public function brisanje_knjige_uklanja_i_njene_primerke(): void
    {
        $bibliotekar = $this->korisnik('bibliotekar');
        $k = $this->knjiga();

        $r = $this->actingAs($bibliotekar)->deleteJson('/api/knjige/' . $k->id);

        $r->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('knjige', ['id' => $k->id]);
        $this->assertDatabaseMissing('primerci_knjiga', ['knjiga_id' => $k->id]);
    }

    /** @test */
    public function zaduzena_knjiga_se_ne_brise(): void
    {
        $bibliotekar = $this->korisnik('bibliotekar');
        $k = $this->knjiga(PrimerakStatus::Zaduzena->value);

        $r = $this->actingAs($bibliotekar)->deleteJson('/api/knjige/' . $k->id);

        $r->assertStatus(422)->assertJson(['success' => false]);
        $this->assertDatabaseHas('knjige', ['id' => $k->id]);
    }

    /** @test */
    public function dodavanje_knjige_pravi_trazeni_broj_primeraka(): void
    {
        $bibliotekar = $this->korisnik('bibliotekar');

        // Broj primeraka je polje obrasca koje se ranije nije ni slalo
        // serveru, pa je svaka nova knjiga dobijala tačno jedan primerak.
        $r = $this->actingAs($bibliotekar)->postJson('/api/knjige', [
            'in_broj' => '7', 'signatura' => '7', 'datum' => '2026-01-01',
            'autor' => 'Autor', 'naziv_knjige' => 'Nova',
            'mesto_izdavanja' => 'Nis', 'godina_izdavanja' => 2021,
            'izdavac' => 'Izdavac', 'nacin_nabavke' => 'Kupovina',
            'broj_primeraka' => 3,
        ]);

        $r->assertCreated()->assertJson(['success' => true]);

        $knjiga = Knjiga::where('naziv_knjige', 'Nova')->firstOrFail();
        $this->assertSame(3, $knjiga->primerci()->count());
    }
}
