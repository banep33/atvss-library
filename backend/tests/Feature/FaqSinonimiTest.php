<?php

namespace Tests\Feature;

use App\Services\Ai\FaqZnanje;
use App\Services\Ai\TextUtils;
use Tests\TestCase;

/**
 * FAQ sinonimi (Faza B′): deterministička generalizacija nad leksičkim
 * FaqZnanje — u pitanju se varijanta zameni kanonskom rečju koja postoji u
 * FAQ paru, pa parafraza pogodi pravi par bez modela i bez hardvera. Čita
 * pravi few-shot fajl (config ai.faq_primeri_path).
 */
class FaqSinonimiTest extends TestCase
{
    private function spojenaPitanja(array $parovi): string
    {
        return TextUtils::normalize(collect($parovi)->pluck('pitanje')->implode(' | '));
    }

    /** @test */
    public function sinonim_vodi_biblioteku_uparuje_bibliotekara(): void
    {
        $faq  = new FaqZnanje();
        $norm = TextUtils::normalize('Ko je zadužen za biblioteku?');

        // „zaduzen za biblioteku" → „bibliotekar" → parafraza sada prelazi FAQ prag.
        $this->assertGreaterThanOrEqual(
            0.6, $faq->najboljaPokrivenost($norm),
            'sinonim treba da podigne FAQ pokrivenost preko praga za faqIzMd'
        );
        $this->assertStringContainsString('bibliotekar', $this->spojenaPitanja($faq->relevantni($norm)));
    }

    /** @test */
    public function sinonim_sifra_uparuje_par_o_lozinci(): void
    {
        $faq = new FaqZnanje();

        // „šifru" → „lozinka" → pogađa par „Zaboravio sam lozinku…".
        $parovi = $faq->relevantni(TextUtils::normalize('Gde da promenim šifru na nalogu?'));
        $this->assertStringContainsString('lozink', $this->spojenaPitanja($parovi));
    }

    /** @test */
    public function bez_sinonima_pitanje_ostaje_nepromenjeno(): void
    {
        // Kontrola: reč bez sinonima ne menja ponašanje (leksičko jezgro netaknuto).
        $faq  = new FaqZnanje();
        $norm = TextUtils::normalize('Kada radi biblioteka?');
        $this->assertGreaterThanOrEqual(0.5, $faq->najboljaPokrivenost($norm));
    }

    /**
     * Faza 1 — pale FAQ parafraze iz baseline-a (golden/held-out) sada dobijaju
     * DIREKTAN kurirani odgovor (bez modela). Ovo su GENERALIZACIJE preko
     * sinonima + relaksiranog uparivanja, a NE golden pitanja upisana u few-shot
     * (leakage). Prikazuje sve promašaje odjednom.
     *
     * @test
     */
    public function pale_faq_parafraze_sada_imaju_direktan_odgovor(): void
    {
        $faq = new FaqZnanje();

        // Potvrđene generalizacije (Faza 1): parafraza se preko sinonima + filler
        // stop-reči + spojenih upitnih klasa (koji/kako/šta) svodi na tačan skup
        // reči kuriranog para, pa dobija DIREKTAN odgovor bez modela.
        //
        // REZIDUAL (za sledeći prolaz — 3+ različite sadržajne reči ili klasni
        // rub, ne svodi se bezbedno strogim uparivanjem): „sa kim da razgovaram
        // oko knjiga", „košta li me da se učlanim", „smem li više rezervacija",
        // „propada rezervacija ako ne dođem", „povlačenje rezervacije", grupa oko
        // resetovanja lozinke, „koliko dugo da zadržim pozajmljenu knjigu",
        // „prodajete li knjige" (klasa „li").
        $slucajevi = [
            ['Kako se zove osoba koja vodi biblioteku?', ['silvana']],
            ['Ko je zadužen za biblioteku?', ['silvana']],
            ['Koji je postupak za učlanjenje?', ['indeks']],
            ['Interesuje me kako da postanem korisnik biblioteke.', ['indeks']],
            ['Ima li nekih troškova za članstvo?', ['besplat']],
            ['Koliko dana imam da podignem rezervisanu knjigu?', ['5']],
            ['Kako da poništim rezervaciju koju sam napravio?', ['otkaz']],
        ];

        $pali = [];

        foreach ($slucajevi as [$pitanje, $ocekivano]) {
            $odg  = $faq->direktanOdgovor(TextUtils::normalize($pitanje));
            $norm = $odg !== null ? TextUtils::normalize($odg) : '';

            $ok = $odg !== null;
            foreach ($ocekivano as $o) {
                if (!str_contains($norm, TextUtils::normalize($o))) {
                    $ok = false;
                }
            }

            if (!$ok) {
                $pali[] = $pitanje . '  =>  ' . ($odg ?? 'NULL');
            }
        }

        $this->assertSame([], $pali, "Parafraze bez direktnog odgovora:\n" . implode("\n", $pali));
    }
}
