<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `asset_v()` dodaje verziju u URL statičkog fajla.
 *
 * Bez toga produkcijski nginx (`Cache-Control: max-age=31536000, immutable`)
 * zadržava staru verziju CSS-a i JS-a i posle deploy-a — `immutable` znači da
 * pregledač neće ni pitati ima li novija. Ovi testovi čuvaju to da ne isklizne:
 * ako neko vrati običan `asset()` u <link>/<script>, poslednji test pada.
 */
class AssetVerzijaTest extends TestCase
{
    #[Test]
    public function postojeci_fajl_dobija_verziju_po_vremenu_izmene(): void
    {
        $url = asset_v('css/core.css');

        $this->assertStringContainsString('/css/core.css?v=', $url);
        $this->assertStringEndsWith(
            '?v=' . filemtime(public_path('css/core.css')),
            $url,
            'verzija mora da bude vreme izmene fajla'
        );
    }

    #[Test]
    public function verzija_se_menja_kada_se_fajl_promeni(): void
    {
        $putanja = public_path('_test-asset-verzija.css');
        file_put_contents($putanja, '/* a */');
        touch($putanja, 1_700_000_000);

        try {
            $prvi = asset_v('_test-asset-verzija.css');
            $this->assertStringEndsWith('?v=1700000000', $prvi);
        } finally {
            @unlink($putanja);
        }
    }

    #[Test]
    public function nepostojeci_fajl_ostaje_bez_verzije(): void
    {
        $url = asset_v('css/ne-postoji-' . uniqid() . '.css');

        $this->assertStringNotContainsString('?v=', $url);
    }

    #[Test]
    public function renderovana_stranica_nosi_verziju_na_svakom_css_i_js(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        preg_match_all('#(?:href|src)="[^"]*/(?:css|js)/[^"]+#', $html, $pogoci);

        $this->assertNotEmpty($pogoci[0], 'stranica mora da učitava bar jedan CSS/JS');

        foreach ($pogoci[0] as $tag) {
            $this->assertStringContainsString('?v=', $tag, "bez verzije: {$tag}");
        }
    }
}
