<?php

namespace Tests\Unit;

use App\Support\Pismo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Paritet preslovljavanja PHP ↔ JS.
 *
 * Korpus resources/normalization/pismo-golden.json je snimljen tako što je
 * PRAVI public/js/pismo.js pokrenut kroz node nad skupom stvarnih tekstova
 * iz aplikacije i nizom ivičnih slučajeva (digrafi, velika slova, nazivi
 * fajlova, oznake, reči sa ustaljenim oblikom).
 *
 * Zašto je bitno: od Faze 3 stranicu preslovljava SERVER (App\Support\Pismo),
 * a prebacivanje pisma bez reload-a i dalje radi JS. Ako se dve strane
 * raziđu, korisnik bi posle klika video drugačiji tekst nego posle refresh-a.
 *
 * REGENERISANJE korpusa (kad se pravila NAMERNO menjaju u OBA fajla):
 *   node scripts/pismo-golden.cjs resources/normalization/pismo-korpus.json \
 *        resources/normalization/pismo-golden.json
 */
class PismoParitetTest extends TestCase
{
    /** @return array<int, array{ulaz: string, cyr: string}> */
    private function golden(): array
    {
        $putanja = \dirname(__DIR__, 2) . '/resources/normalization/pismo-golden.json';

        $this->assertFileExists($putanja, 'Nedostaje korpus za paritet preslovljavanja.');

        $korpus = json_decode((string) file_get_contents($putanja), true);

        $this->assertIsArray($korpus);
        $this->assertNotEmpty($korpus);

        return $korpus;
    }

    #[Test]
    public function php_preslovljava_isto_kao_pismo_js(): void
    {
        $pismo = new Pismo();

        foreach ($this->golden() as $slucaj) {
            $this->assertSame(
                $slucaj['cyr'],
                $pismo->uCirilicu($slucaj['ulaz']),
                'Razlika u odnosu na pismo.js za ulaz: ' . $slucaj['ulaz']
            );
        }
    }

    #[Test]
    public function html_preslovljava_samo_vidljiv_tekst(): void
    {
        $pismo = new Pismo();

        $html = '<div class="knjiga" id="prva">'
              . '<h1 title="Naslov knjige">Zadužene knjige</h1>'
              . '<script>var poruka = "Sacuvano";</script>'
              . '<style>.a { content: "Test" }</style>'
              . '<input placeholder="Pretraga" value="Ne dirati">'
              . '<span data-no-tr>VTŠApps team</span>'
              . '<a href="/knjige/1">Detalji</a>'
              . '</div>';

        $rez = $pismo->preslovljajHtml($html);

        $this->assertStringContainsString('Задужене књиге', $rez);      // tekst
        $this->assertStringContainsString('title="Наслов књиге"', $rez); // title atribut
        $this->assertStringContainsString('placeholder="Претрага"', $rez);
        $this->assertStringContainsString('var poruka = "Sacuvano"', $rez); // skripta netaknuta
        $this->assertStringContainsString('content: "Test"', $rez);         // stil netaknut
        $this->assertStringContainsString('class="knjiga" id="prva"', $rez); // atributi netaknuti
        $this->assertStringContainsString('href="/knjige/1"', $rez);
        $this->assertStringContainsString('value="Ne dirati"', $rez);
        $this->assertStringContainsString('>VTŠApps team<', $rez);          // data-no-tr
        $this->assertStringContainsString('>Детаљи<', $rez);
    }

    #[Test]
    public function e_adrese_i_url_ovi_ostaju_u_latinici(): void
    {
        $pismo = new Pismo();

        $html = '<p>silvana.bogicevic@akademijanis.edu.rs</p><p>https://akademijanis.edu.rs</p>';
        $rez  = $pismo->preslovljajHtml($html);

        $this->assertStringContainsString('silvana.bogicevic@akademijanis.edu.rs', $rez);
        $this->assertStringContainsString('https://akademijanis.edu.rs', $rez);
    }

    #[Test]
    public function doctype_komentari_i_skripte_ostaju_netaknuti(): void
    {
        $pismo = new Pismo();

        $html = "<!DOCTYPE html>
"
              . "<!-- Napomena sa > znakom unutra -->
"
              . "<script>for (var i = 0; i < 3; i++) { red(i); }</script>
"
              . "<p>Zadužene knjige</p>";

        $rez = $pismo->preslovljajHtml($html);

        // DOCTYPE i komentar idu doslovno
        $this->assertStringContainsString('<!DOCTYPE html>', $rez);
        $this->assertStringContainsString('<!-- Napomena sa > znakom unutra -->', $rez);

        /* „i < 3" u skripti izgleda kao početak taga; ako se tokenizuje,
           proguta `</script>` i ostatak stranice ostane nepreslovljen. */
        $this->assertStringContainsString('for (var i = 0; i < 3; i++)', $rez);
        $this->assertStringContainsString('Задужене књиге', $rez);
    }

    #[Test]
    public function html_entiteti_ostaju_netaknuti(): void
    {
        $pismo = new Pismo();

        /* Dugme za zatvaranje modala piše se kao &times;. Preslovljen entitet
           („&тимес;") prestaje da bude entitet i korisnik vidi taj tekst. */
        $rez = $pismo->preslovljajHtml(
            '<button class="close-btn">&times;</button>'
            . '<p>Dodaj&nbsp;knjigu &#215; sada</p>'
        );

        $this->assertStringContainsString('>&times;<', $rez);
        $this->assertStringContainsString('&nbsp;', $rez);
        $this->assertStringContainsString('&#215;', $rez);
        $this->assertStringNotContainsString('&тимес;', $rez);
        $this->assertStringContainsString('Додај', $rez);   // tekst oko entiteta se i dalje preslovljava
    }
}
