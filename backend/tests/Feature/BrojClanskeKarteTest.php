<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Generisanje broja članske karte pri dodavanju člana.
 *
 * Format je CLAN-001 (tri cifre). Broj se računa kao NAJVEĆI postojeći + 1;
 * raniji `count() + 1` je posle brisanja člana davao broj koji već postoji, a
 * kolona `broj_clanske_karte` je unique — dodavanje bi puklo na bazi.
 */
class BrojClanskeKarteTest extends TestCase
{
    use RefreshDatabase;

    private function bibliotekar(): User
    {
        return User::create([
            'ime' => 'Test', 'prezime' => 'Bibliotekar',
            'email' => 'bibliotekar@example.com',
            'password' => Hash::make('tajna123'),
            'role' => 'bibliotekar',
        ]);
    }

    private function clan(string $karta, string $email): User
    {
        return User::create([
            'ime' => 'Clan', 'prezime' => $karta,
            'email' => $email,
            'password' => Hash::make('tajna123'),
            'role' => 'clan',
            'broj_clanske_karte' => $karta,
        ]);
    }

    private function dodaj(User $kaoKo, string $email): string
    {
        $odgovor = $this->actingAs($kaoKo)->postJson('/api/clanovi', [
            'ime'      => 'Novi',
            'prezime'  => 'Clan',
            'email'    => $email,
            'password' => 'lozinka123',
        ]);

        $odgovor->assertStatus(201);

        return $odgovor->json('data.broj_clanske_karte');
    }

    #[Test]
    public function prvi_clan_dobija_tri_cifre(): void
    {
        $this->assertSame('CLAN-001', $this->dodaj($this->bibliotekar(), 'prvi@example.com'));
    }

    #[Test]
    public function broj_se_nastavlja_na_postojece_clanove(): void
    {
        $bibliotekar = $this->bibliotekar();
        $this->clan('CLAN-001', 'a@example.com');
        $this->clan('CLAN-002', 'b@example.com');

        $this->assertSame('CLAN-003', $this->dodaj($bibliotekar, 'treci@example.com'));
    }

    #[Test]
    public function stari_cetvorocifreni_zapis_se_racuna_kao_broj(): void
    {
        $bibliotekar = $this->bibliotekar();
        $this->clan('CLAN-001', 'a@example.com');
        $this->clan('CLAN-0003', 'b@example.com');   // zapis iz ranije verzije

        $this->assertSame('CLAN-004', $this->dodaj($bibliotekar, 'cetvrti@example.com'));
    }

    #[Test]
    public function brisanje_clana_ne_pravi_sudar_brojeva(): void
    {
        $bibliotekar = $this->bibliotekar();
        $this->clan('CLAN-001', 'a@example.com');
        $srednji = $this->clan('CLAN-002', 'b@example.com');
        $this->clan('CLAN-003', 'c@example.com');

        $srednji->delete();

        /* Sa `count() + 1` ovde bi ispalo CLAN-003 (već postoji) i dodavanje
           bi puklo na unique ograničenju. */
        $this->assertSame('CLAN-004', $this->dodaj($bibliotekar, 'novi@example.com'));
    }
}
