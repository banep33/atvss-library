<?php

namespace Tests\Unit;

use App\Models\Knjiga;
use App\Models\Zanr;
use App\Services\Ai\SystemPromptService;
use App\Services\Ai\TextUtils;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SystemPromptService — jedan objedinjen prompt sa celim RAG kontekstom
 * (pogoci, preporuke, agregati, nalog člana).
 */
class SystemPromptServiceTest extends TestCase
{
    private function knjiga(int $id, string $naziv, string $autor): Knjiga
    {
        $knjiga = new Knjiga(['naziv_knjige' => $naziv, 'autor' => $autor, 'opis' => 'Opis knjige.']);
        $knjiga->id = $id;
        $knjiga->setRelation('zanrovi', collect([new Zanr(['naziv' => 'Programiranje'])]));
        $knjiga->slobodni_primerci_count = 1;
        $knjiga->ukupno_primeraka_count  = 2;

        return $knjiga;
    }

    /** Minimalan validan kontekst — pojedinačni testovi menjaju šta im treba. */
    private function ctx(array $izmene = []): array
    {
        return array_merge([
            'knjige'      => collect(),
            'preporuke'   => collect(),
            'agregati'    => ['knjiga' => 358, 'primeraka' => 500, 'slobodnih' => 400, 'nedostupnih' => 100],
            'najnovije'   => null,
            'najcitanije' => null,
            'count_rec'   => null,
            'clan'        => null,
        ], $izmene);
    }

    #[Test]
    public function naucena_sekcija_se_dodaje_kad_ima_parova(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx([
            'nauceni' => [['pitanje' => 'Koliko je članarina?', 'odgovor' => 'Članarina je besplatna.']],
        ]));

        $this->assertStringContainsString('SLIČNA RANIJE VIĐENA PITANJA', $prompt);
        $this->assertStringContainsString('Koliko je članarina?', $prompt);
        $this->assertStringContainsString('Članarina je besplatna.', $prompt);
    }

    #[Test]
    public function naucena_sekcija_dedupuje_par_koji_je_vec_u_faq_sekciji(): void
    {
        // Isti par i u faq_primeri i u nauceni → naučena sekcija je prazna
        // (nema duplikata koji troše num_ctx).
        $prompt = (new SystemPromptService)->build($this->ctx([
            'faq_primeri' => [['pitanje' => 'Kada radi biblioteka?', 'odgovor' => 'Od 08 do 16.']],
            'nauceni'     => [['pitanje' => 'Kada radi biblioteka?', 'odgovor' => 'Od 08 do 16.']],
        ]));

        $this->assertStringNotContainsString('SLIČNA RANIJE VIĐENA PITANJA', $prompt);
    }

    #[Test]
    public function bez_naucenih_parova_nema_naucene_sekcije(): void
    {
        $this->assertStringNotContainsString(
            'SLIČNA RANIJE VIĐENA PITANJA',
            (new SystemPromptService)->build($this->ctx())
        );
    }

    #[Test]
    public function prompt_sadrzi_pronadjene_knjige_kao_linkove_bez_internog_scorea(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx([
            'knjige' => collect([
                ['knjiga' => $this->knjiga(7, 'Uvod u programiranje', 'Pera Peric'), 'score' => 0.83],
            ]),
        ]));

        $this->assertStringContainsString('[Uvod u programiranje](/knjige/7)', $prompt);
        $this->assertStringContainsString('Pera Peric', $prompt);
        $this->assertStringContainsString('slobodno primeraka: 1 od 2', $prompt);
        // Interni score se NE šalje modelu — prepisivao ga je u odgovore
        // ("Relevantnost: 1.00", eval za_kod.md); rangiranje nosi redosled.
        // 'relevantnost:' sa dvotačkom = format podatka; sama reč sme da
        // postoji u PRAVILU koje modelu zabranjuje da je pominje.
        $this->assertStringNotContainsString('relevantnost:', $prompt);
        // Primer navođenja mora da traži i autora uz vezu (naslov = link, autor = tekst u zagradi).
        $this->assertStringContainsString('[Uvod u programiranje](/knjige/7) (Pera Peric)', $prompt);
    }

    #[Test]
    public function prompt_sadrzi_broj_i_spisak_zanrova(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx([
            'agregati' => [
                'knjiga'      => 358,
                'primeraka'   => 500,
                'slobodnih'   => 400,
                'nedostupnih' => 100,
                'zanrovi'     => [
                    ['naziv' => 'Roman', 'broj_knjiga' => 120],
                    ['naziv' => 'Poezija', 'broj_knjiga' => 30],
                ],
            ],
        ]));

        $this->assertStringContainsString('Žanrova u katalogu: 2', $prompt);
        $this->assertStringContainsString('Roman (120)', $prompt);
        $this->assertStringContainsString('Poezija (30)', $prompt);
    }

    #[Test]
    public function prazan_kontekst_dobija_eksplicitnu_napomenu_da_knjiga_nema(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx());

        $this->assertStringContainsString('Nijedna knjiga iz kataloga nije pronađena', $prompt);
    }

    #[Test]
    public function bez_pogodaka_a_sa_preporukama_prompt_trazi_preporucivanje(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx([
            'preporuke'     => collect([
                ['knjiga' => $this->knjiga(3, 'Koreni', 'Dobrica Cosic'), 'score' => 0.61],
            ]),
            'preporuke_tip' => 'knjiga',
        ]));

        $this->assertStringContainsString('TRAŽENO NIJE PRONAĐENO — PREPORUKE', $prompt);
        $this->assertStringContainsString('[Koreni](/knjige/3)', $prompt);
        $this->assertStringContainsString('Ukoliko želite', $prompt);
        $this->assertStringNotContainsString('Nijedna knjiga iz kataloga nije pronađena', $prompt);
    }

    #[Test]
    public function zanrovske_preporuke_pominju_srodni_zanr(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx([
            'preporuke'     => collect([
                ['knjiga' => $this->knjiga(3, 'Koreni', 'Dobrica Cosic'), 'score' => 0.0],
            ]),
            'preporuke_tip' => 'zanr',
            'srodni_zanr'   => 'Roman',
        ]));

        $this->assertStringContainsString('nemamo knjige', $prompt);
        $this->assertStringContainsString('iz žanra Roman', $prompt);
        $this->assertStringNotContainsString('relevantnost:', $prompt);
    }

    #[Test]
    public function prompt_sadrzi_fiksna_pravila_i_podatke_o_biblioteci(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx());

        $this->assertStringContainsString(TextUtils::NOT_UNDERSTOOD, $prompt);
        $this->assertStringContainsString(SystemPromptService::NEMA_INFORMACIJE, $prompt);
        $this->assertStringContainsString(SystemPromptService::VAN_TEME, $prompt);
        $this->assertStringContainsString('Aleksandra Medvedeva 20', $prompt);
        $this->assertStringContainsString('08:00 do 16:00', $prompt);
    }

    #[Test]
    public function prompt_sadrzi_agregatno_stanje_kataloga(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx());

        $this->assertStringContainsString('Ukupno različitih knjiga: 358', $prompt);
        $this->assertStringContainsString('slobodnih 400', $prompt);
    }

    #[Test]
    public function prompt_sadrzi_stanje_naloga_clana(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx([
            'clan' => [
                'rezervacija' => ['knjiga' => 'Koreni', 'status' => 'Odobrena', 'rok_preuzimanja' => '10.07.2026.'],
                'zaduzenje'   => null,
            ],
        ]));

        $this->assertStringContainsString('STANJE NALOGA', $prompt);
        $this->assertStringContainsString('Koreni', $prompt);
        $this->assertStringContainsString('Odobrena', $prompt);
        $this->assertStringContainsString('nema zaduženu knjigu', $prompt);
    }

    #[Test]
    public function prompt_sadrzi_najcitanije_kada_je_intencija_prepoznata(): void
    {
        $prompt = (new SystemPromptService)->build($this->ctx([
            'najcitanije' => collect([
                ['id' => 5, 'naziv' => 'Na Drini ćuprija', 'autor' => 'Ivo Andrić', 'slobodno' => 2, 'broj_zaduzenja' => 14],
            ]),
        ]));

        $this->assertStringContainsString('NAJČITANIJE KNJIGE', $prompt);
        $this->assertStringContainsString('[Na Drini ćuprija](/knjige/5) (Ivo Andrić)', $prompt);
        $this->assertStringContainsString('broj zaduženja: 14', $prompt);
    }

    #[Test]
    public function prazna_najcitanije_lista_trazi_odgovor_da_statistike_nema(): void
    {
        // Intencija prepoznata (kolekcija, ne null), ali podataka nema —
        // model NE sme da bira „najčitaniju" knjigu po svom nahođenju.
        $prompt = (new SystemPromptService)->build($this->ctx([
            'najcitanije' => collect(),
        ]));

        $this->assertStringContainsString('Statistika čitanosti još ne postoji', $prompt);
        $this->assertStringContainsString('NEMOJ', $prompt);
    }
}
