<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regresioni gard za „lepljivo" podnožje.
 *
 * Podnožje je nekada bilo posao svake stranice: knjige/show ga je lepio
 * `position: fixed`, zadužene i admin `position: absolute` uz 150–200px
 * `padding-bottom` na body-ju. Na uskim ekranima mobile-nav.css vraćao ga je
 * u tok, pa je taj rezervisani prostor ostajao kao prazna traka ispod njega —
 * tačno ono što se videlo na „Zadužene knjige" i „Moj profil".
 *
 * Sada postoji tačno jedno pravilo `footer`, u core.css: `position: sticky`
 * sa `top: 100vh` nad body-jem visokim bar 100vh. Ovaj test čuva taj dogovor.
 */
class PodnozjeStilTest extends TestCase
{
    /** Stylesheet-ovi stranica koje nose zajedničko podnožje. */
    private const STRANICE = [
        'admin.css', 'info.css', 'knjige-index.css', 'knjige-show.css',
        'mobile-nav.css', 'not.css', 'profil.css', 'zaduzene.css',
    ];

    private function css(string $ime): string
    {
        return (string) file_get_contents(public_path('css/' . $ime));
    }

    /** Tela svih pravila za dati selektor u jednom fajlu. */
    private function pravila(string $css, string $selektor): array
    {
        preg_match_all('/(?<![\w-])' . $selektor . '\s*\{([^}]*)\}/s', $css, $pogodci);

        return $pogodci[1];
    }

    /** @test */
    public function core_drzi_lepljivo_podnozje(): void
    {
        $core = $this->css('core.css');

        $body = $this->pravila($core, 'body');
        $this->assertNotEmpty($body, 'core.css nema pravilo za body.');

        $this->assertMatchesRegularExpression(
            '/min-height:\s*100vh/',
            implode('', $body),
            'body u core.css više nije visok bar koliko ekran — podnožje neće imati dokle da padne.'
        );

        // Flex/grid na <body>-ju bi ukinuo razvlačenje omotača koji se
        // centriraju sa `margin: 0 auto`: takav element se skuplja oko
        // sadržaja, pa se katalog svede na širinu najšire kartice, a na
        // uskom ekranu izađe iz ekrana (vodoravni skrol cele stranice).
        $this->assertDoesNotMatchRegularExpression(
            '/display:\s*(flex|grid)/',
            implode('', $body),
            'body u core.css je postao flex/grid — omotači sa `margin: 0 auto` se tada skupljaju.'
        );

        $footer = implode('', $this->pravila($core, 'footer'));
        $this->assertMatchesRegularExpression(
            '/position:\s*sticky/',
            $footer,
            'Pravilo `footer` u core.css više nije lepljivo.'
        );
        $this->assertMatchesRegularExpression(
            '/top:\s*100vh/',
            $footer,
            'Bez `top: 100vh` lepljivo podnožje se ne spušta na donju ivicu ekrana.'
        );
    }

    /** @test */
    public function stranice_ne_pozicioniraju_podnozje_same(): void
    {
        foreach (self::STRANICE as $ime) {
            foreach ($this->pravila($this->css($ime), 'footer') as $telo) {
                $this->assertDoesNotMatchRegularExpression(
                    '/position:/',
                    $telo,
                    "{$ime}: podnožje se opet pozicionira samo — ostaviti to core.css-u."
                );
            }
        }
    }

    /** @test */
    public function stranice_ne_rezervisu_prostor_ispod_podnozja(): void
    {
        foreach (self::STRANICE as $ime) {
            foreach ($this->pravila($this->css($ime), 'body') as $telo) {
                $this->assertDoesNotMatchRegularExpression(
                    '/padding-bottom:\s*(?!0)/',
                    $telo,
                    "{$ime}: body opet rezerviše prostor ispod podnožja — to je bila praznina "
                    . 'između podnožja i donje ivice ekrana.'
                );
            }
        }
    }
}
