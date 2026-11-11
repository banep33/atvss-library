<?php

namespace Tests\Feature;

use App\Models\Knjiga;
use App\Models\User;
use App\Services\Ai\TextUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Eval-only ruta (Faza D): POST /api/ai/eval/chat — isti pipeline kao guest,
 * ali BEZ throttle-a, da harness (eval/eval_app.py) provuče ceo golden+held-out
 * set brzo. Zaštita: EnsureEvalToken (X-Eval-Token, hash_equals) + uslovna
 * registracija (AI_EVAL_ENABLED). Flag i token su u phpunit.xml (test-only).
 *
 * Koristi se poruka koja daje FIKSNI odgovor (auth-gate za gosta) — ruter
 * vraća poruku pre ijednog poziva mikroservisu/Ollami, pa test ne zavisi od
 * spoljnih servisa (Http::fake je dodatna zaštita).
 */
class EvalRutaTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-eval-token-123';   // = AI_EVAL_TOKEN u phpunit.xml

    protected function setUp(): void
    {
        parent::setUp();
        Knjiga::$ragSyncPaused = true;
        Http::fake();
    }

    protected function tearDown(): void
    {
        Knjiga::$ragSyncPaused = false;
        parent::tearDown();
    }

    /** Poruka koja kod gosta daje fiksni auth-odgovor (bez poziva modelu). */
    private function poruka(): array
    {
        return ['message' => 'Želim da rezervišem knjigu'];
    }

    /** @test */
    public function bez_tokena_vraca_403(): void
    {
        $this->postJson('/api/ai/eval/chat', $this->poruka())
             ->assertStatus(403);
    }

    /** @test */
    public function pogresan_token_vraca_403(): void
    {
        $this->postJson('/api/ai/eval/chat', $this->poruka(), ['X-Eval-Token' => 'pogresan'])
             ->assertStatus(403);
    }

    /** @test */
    public function ispravan_token_prolazi_kroz_pipeline(): void
    {
        $this->postJson('/api/ai/eval/chat', $this->poruka(), ['X-Eval-Token' => self::TOKEN])
             ->assertOk()
             ->assertJson(['reply' => TextUtils::AUTH_REQUIRED]);
    }

    /** @test */
    public function ruta_nije_throttle_ovana(): void
    {
        // Guest ruta (throttle:5,1) bi na 6. zahtevu vratila 429; eval ruta ne.
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/ai/eval/chat', $this->poruka(), ['X-Eval-Token' => self::TOKEN])
                 ->assertOk();
        }
    }

    /** @test */
    public function member_id_meri_pitanje_u_clanskom_rezimu(): void
    {
        // Direktan create (UserFactory referencira User::class bez use-a — pre-postojeći bug).
        $clan = User::create([
            'ime'      => 'Test',
            'prezime'  => 'Clan',
            'email'    => 'test.clan@example.com',
            'password' => Hash::make('tajna123'),
            'role'     => 'clan',
        ]);

        // Kao gost: zahtev za rezervacijom → fiksni auth-gate.
        $this->postJson('/api/ai/eval/chat', ['message' => 'Želim da rezervišem knjigu'],
                        ['X-Eval-Token' => self::TOKEN])
             ->assertOk()->assertJson(['reply' => TextUtils::AUTH_REQUIRED]);

        // Sa member_id: član-režim → auth-gate se NE aktivira (odgovor nije auth poruka).
        $resp = $this->postJson('/api/ai/eval/chat',
                        ['message' => 'Želim da rezervišem knjigu', 'member_id' => $clan->id],
                        ['X-Eval-Token' => self::TOKEN]);
        $this->assertNotSame(TextUtils::AUTH_REQUIRED, $resp->json('reply'));
    }

    /** @test */
    public function ugasen_flag_vraca_404(): void
    {
        // EnsureEvalToken čita config uživo — ugašen flag = ruta kao da ne postoji.
        config(['ai.eval_enabled' => false]);

        $this->postJson('/api/ai/eval/chat', $this->poruka(), ['X-Eval-Token' => self::TOKEN])
             ->assertStatus(404);
    }
}
