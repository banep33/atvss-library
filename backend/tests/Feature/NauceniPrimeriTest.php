<?php

namespace Tests\Feature;

use App\Models\NauceniPrimer;
use App\Services\Ai\NauceniPrimeriService;
use App\Services\Ai\RagClient;
use App\Services\Ai\TextUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Naučeni primeri (Faza B): semantičko preuzimanje + `bot:nauci` leakage gard.
 * RagClient (embed/health) je mockovan — testovi ne zavise od živog RAG servisa.
 */
class NauceniPrimeriTest extends TestCase
{
    use RefreshDatabase;

    private function dodajPrimer(string $pitanje, array $embNorm): void
    {
        NauceniPrimer::create([
            'pitanje'      => $pitanje,
            'odgovor'      => 'Odgovor na: ' . $pitanje,
            'pitanje_norm' => TextUtils::normalize($pitanje),
            'embedding'    => $embNorm,     // već L2-normalizovan (unit vektor)
            'izvor'        => 'test',
            'aktivan'      => true,
        ]);
    }

    /** @test */
    public function retrieve_vraca_najslicniji_par_iznad_praga(): void
    {
        // Upit se embeduje u [1,0]; par A ([1,0]) ima cosine 1.0, par B ([0,1]) 0.0.
        $this->mock(RagClient::class, fn ($m) =>
            $m->shouldReceive('embedDocuments')->once()->andReturn([[1.0, 0.0]]));

        $this->dodajPrimer('Kada radi biblioteka', [1.0, 0.0]);
        $this->dodajPrimer('Ko je bibliotekar', [0.0, 1.0]);

        $rez = app(NauceniPrimeriService::class)->retrieve('u koje vreme je otvoreno');

        $this->assertCount(1, $rez);
        $this->assertSame('Kada radi biblioteka', $rez[0]['pitanje']);
    }

    /** @test */
    public function retrieve_ispod_praga_ne_vraca_nista(): void
    {
        // Par pod uglom sa cosine 0.5 (< prag 0.75) prema upitu [1,0].
        $this->mock(RagClient::class, fn ($m) =>
            $m->shouldReceive('embedDocuments')->andReturn([[1.0, 0.0]]));

        $this->dodajPrimer('Nesrodno pitanje', [0.5, sqrt(3) / 2]);

        $this->assertSame([], app(NauceniPrimeriService::class)->retrieve('nešto'));
    }

    /** @test */
    public function direktan_odgovor_okida_na_jasnog_pobednika(): void
    {
        $this->mock(RagClient::class, fn ($m) =>
            $m->shouldReceive('embedDocuments')->andReturn([[1.0, 0.0]]));
        $this->dodajPrimer('Kada radi biblioteka', [1.0, 0.0]);          // cosine 1.0
        $this->dodajPrimer('Ko je bibliotekar', [0.30, sqrt(1 - 0.09)]); // cosine 0.30

        config(['ai.nauceni_direktno_prag' => 0.85, 'ai.nauceni_direktno_margina' => 0.03]);

        $this->assertSame(
            'Odgovor na: Kada radi biblioteka',
            app(NauceniPrimeriService::class)->direktanOdgovor('u koje vreme je otvoreno')
        );
    }

    /** @test */
    public function direktan_odgovor_ne_okida_na_zbijene_skorove(): void
    {
        // top 0.86, drugi 0.845 → margina 0.015 < 0.03 → null (ne rizikuj pogrešan par).
        $this->mock(RagClient::class, fn ($m) =>
            $m->shouldReceive('embedDocuments')->andReturn([[1.0, 0.0]]));
        $this->dodajPrimer('A', [0.86, sqrt(1 - 0.86 * 0.86)]);
        $this->dodajPrimer('B', [0.845, sqrt(1 - 0.845 * 0.845)]);

        config(['ai.nauceni_direktno_prag' => 0.85, 'ai.nauceni_direktno_margina' => 0.03]);

        $this->assertNull(app(NauceniPrimeriService::class)->direktanOdgovor('x'));
    }

    /** @test */
    public function direktan_odgovor_ne_okida_ispod_praga(): void
    {
        // Jedini par ima cosine 0.80 < prag 0.85 → null.
        $this->mock(RagClient::class, fn ($m) =>
            $m->shouldReceive('embedDocuments')->andReturn([[1.0, 0.0]]));
        $this->dodajPrimer('A', [0.80, 0.6]);

        config(['ai.nauceni_direktno_prag' => 0.85, 'ai.nauceni_direktno_margina' => 0.03]);

        $this->assertNull(app(NauceniPrimeriService::class)->direktanOdgovor('x'));
    }

    /** @test */
    public function bot_nauci_odbija_golden_pitanja_leakage(): void
    {
        // G001 iz eval/golden/golden.json — NE sme da uđe u nauceni_primeri.
        $golden = 'U koje vreme mogu da svratim u biblioteku radnim danom?';

        $tmp = tempnam(sys_get_temp_dir(), 'fs') . '.md';
        file_put_contents(
            $tmp,
            "**P: {$golden}**\nO: Neki odgovor.\n\n**P: Koje je tvoje omiljeno jelo?**\nO: Sarma.\n"
        );
        config(['ai.faq_primeri_path' => $tmp]);

        $this->mock(RagClient::class, function ($m) {
            $m->shouldReceive('health')->andReturn(['model' => 'nomic-embed-text', 'dim' => 3]);
            $m->shouldReceive('embedDocuments')
              ->andReturnUsing(fn (array $t) => array_map(fn () => [1.0, 0.0, 0.0], $t));
        });

        $this->artisan('bot:nauci')->assertSuccessful();

        $this->assertDatabaseMissing('nauceni_primeri',
            ['pitanje_norm' => TextUtils::normalize($golden)]);
        $this->assertDatabaseHas('nauceni_primeri',
            ['pitanje_norm' => TextUtils::normalize('Koje je tvoje omiljeno jelo?')]);

        @unlink($tmp);
    }

    /** @test */
    public function sinhronizuj_dedupuje_po_normalizovanom_pitanju(): void
    {
        $this->mock(RagClient::class, fn ($m) =>
            $m->shouldReceive('embedDocuments')
              ->andReturnUsing(fn (array $t) => array_map(fn () => [1.0, 0.0], $t)));

        $svc = app(NauceniPrimeriService::class);
        $svc->sinhronizuj([['pitanje' => 'Kada radi biblioteka?', 'odgovor' => 'Verzija 1']]);
        // Isto pitanje (modulo velika slova) → updateOrCreate ažurira, ne dodaje.
        $svc->sinhronizuj([['pitanje' => 'kada RADI biblioteka?', 'odgovor' => 'Verzija 2']]);

        $this->assertSame(1, NauceniPrimer::count());
        $this->assertSame('Verzija 2', NauceniPrimer::first()->odgovor);
    }
}
