<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\PreslovljavanjePisma;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Istek sesije — šta korisnik vidi kada mu sesija „padne".
 *
 * Tri različita puta vode do istog stanja, i sva tri moraju da kažu isto:
 *   1. slanje forme sa zastarelim CSRF tokenom  -> strana errors/419
 *   2. otvaranje zaštićene stranice             -> prijava + poruka o isteku
 *   3. poziv iz JavaScript-a (fetch)            -> JSON sa istom porukom
 *
 * Razliku između „gost" i „istekla sesija" nosi kolačić `bio_prijavljen`
 * (LoginController::TRAG_PRIJAVE) — bez njega bi obe situacije izgledale
 * isto, jer u obe nema prijavljenog korisnika.
 */
class IstekSesijeTest extends TestCase
{
    use RefreshDatabase;

    private function clan(): User
    {
        return User::create([
            'ime' => 'Test', 'prezime' => 'Korisnik',
            'email' => 'clan@example.com',
            'password' => Hash::make('tajna123'),
            'role' => 'clan', 'telefon' => '+381600000000', 'adresa' => 'Nis',
            'broj_clanske_karte' => 'CLAN-0001',
        ]);
    }

    /**
     * Ruta koja baca TokenMismatchException — CSRF middleware se u testovima
     * namerno preskače, pa se izuzetak podiže ručno da bi se proverio
     * rukovalac iz bootstrap/app.php.
     */
    private function rutaSaIsteklimTokenom(string $prefiks = ''): string
    {
        $putanja = $prefiks . '/__test-istekao-token';

        Route::middleware($prefiks === '/api' ? 'api' : 'web')->get($putanja, function () {
            throw new TokenMismatchException();
        });

        return $putanja;
    }

    /** @test */
    public function istekao_csrf_token_vodi_na_stranicu_419(): void
    {
        $r = $this->get($this->rutaSaIsteklimTokenom());

        $r->assertStatus(419);
        $r->assertSee('Sesija je istekla!');
        $r->assertSee('Prijavi se ponovo');
        // Radnja je prijava, a ne povratak na početnu.
        $r->assertSee('href="/login"', false);
        $r->assertDontSee('Page Expired');
    }

    /** @test */
    public function stranica_419_prati_izabrano_pismo(): void
    {
        $r = $this->withUnencryptedCookie(PreslovljavanjePisma::KOLACIC, 'cyr')
            ->get($this->rutaSaIsteklimTokenom());

        $r->assertStatus(419);
        $r->assertSee('Сесија је истекла!');
        $r->assertSee('Пријави се поново');
    }

    /** @test */
    public function istekao_token_na_api_ruti_vraca_json(): void
    {
        $r = $this->getJson($this->rutaSaIsteklimTokenom('/api'));

        $r->assertStatus(419);
        $r->assertJson(['sesija_istekla' => true]);
        $r->assertJsonFragment(['error' => 'Sesija je istekla. Prijavite se ponovo da biste nastavili rad.']);
    }

    /** @test */
    public function zasticena_stranica_bez_traga_prijave_kaze_da_treba_prijava(): void
    {
        $r = $this->get('/profil');

        $r->assertRedirect('/login');
        $r->assertSessionHas('error', 'Morate biti prijavljeni.');
    }

    /** @test */
    public function zasticena_stranica_sa_tragom_prijave_kaze_da_je_sesija_istekla(): void
    {
        $r = $this->withUnencryptedCookie(LoginController::TRAG_PRIJAVE, '1')->get('/profil');

        $r->assertRedirect('/login');
        $r->assertSessionHas('error', 'Sesija je istekla. Prijavite se ponovo da biste nastavili rad.');
        // Poruka se prikazuje jednom — trag se briše da je gost ne bi video ponovo.
        $r->assertCookieExpired(LoginController::TRAG_PRIJAVE);
    }

    /** @test */
    public function prijava_ostavlja_trag_a_odjava_ga_brise(): void
    {
        $clan = $this->clan();

        $prijava = $this->post('/login', [
            'email' => $clan->email,
            'password' => 'tajna123',
        ]);
        // Kolačić nije šifrovan (izuzet u bootstrap/app.php), otud `false`.
        $prijava->assertCookie(LoginController::TRAG_PRIJAVE, '1', false);

        $odjava = $this->actingAs($clan)->post('/logout');
        $odjava->assertCookieExpired(LoginController::TRAG_PRIJAVE);
    }
}
