<?php

namespace Tests\Unit;

use App\Models\Knjiga;
use App\Services\Ai\KatalogPretraga;
use App\Services\Ai\TextUtils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KatalogPretraga::brojKnjigaSaRecju — MORFOLOŠKO brojanje (Nalaz 3):
 * padeži iste reči ulaze u broj, substring lažni pogoci ne.
 */
class KatalogPretragaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Knjiga::$ragSyncPaused = true;
    }

    protected function tearDown(): void
    {
        Knjiga::$ragSyncPaused = false;
        parent::tearDown();
    }

    /** @test */
    public function brojanje_hvata_padeze_iste_reci(): void
    {
        Knjiga::create(['naziv_knjige' => 'Beskrajna priča', 'autor' => 'Mihael Ende']);
        Knjiga::create(['naziv_knjige' => 'Priče iz davnina', 'autor' => 'Ivana Brlić-Mažuranić']);
        Knjiga::create(['naziv_knjige' => 'Najlepše priče sveta', 'autor' => 'Grupa autora']);
        Knjiga::create(['naziv_knjige' => 'Princip neizvesnosti', 'autor' => 'Pera Perić']);

        $rezultat = app(KatalogPretraga::class)->brojKnjigaSaRecju('priče');

        // „priča"/„priče" u sva tri oblika, ali NE „Princip".
        $this->assertSame(3, $rezultat['broj']);
        $this->assertCount(3, $rezultat['primeri']);
    }

    /** @test */
    public function brojanje_nema_substring_lazne_pogotke(): void
    {
        Knjiga::create(['naziv_knjige' => 'Vrata tajni', 'autor' => 'Autor A']);
        Knjiga::create(['naziv_knjige' => 'Bratstvo prstena', 'autor' => 'Autor B']);
        Knjiga::create(['naziv_knjige' => 'Rat i mir', 'autor' => 'Lav Tolstoj']);

        $rezultat = app(KatalogPretraga::class)->brojKnjigaSaRecju('rat');

        // Samo „Rat i mir" — ne „vRATa" ni „bRATstvo" (stari substring bag).
        $this->assertSame(1, $rezultat['broj']);
    }

    /** @test */
    public function koren_i_ista_rec_morfologija(): void
    {
        $this->assertSame('pric', TextUtils::koren('pricama'));
        $this->assertSame('pric', TextUtils::koren('price'));
        $this->assertSame('rat', TextUtils::koren('rat'));

        $this->assertTrue(TextUtils::istaRec('prica', 'pricama'));
        $this->assertTrue(TextUtils::istaRec('price', 'prica'));
        $this->assertFalse(TextUtils::istaRec('princip', 'price'));
        $this->assertFalse(TextUtils::istaRec('vrata', 'rat'));
        // Fuzzy bi „prije" (ijekavsko „pre") izjednačio sa „priče" na jednu
        // izmenu — brojanje mora da ostane strogo po korenu.
        $this->assertFalse(TextUtils::istaRec('prije', 'price'));
    }

    /** @test */
    public function tematsko_pitanje_preskace_pogodak_po_zanru(): void
    {
        $zanr = \App\Models\Zanr::create(['naziv' => 'Priče']);
        $knjiga = Knjiga::create(['naziv_knjige' => 'Zlatna knjiga', 'autor' => 'Autor A']);
        $knjiga->zanrovi()->attach($zanr->id);

        $katalog = app(KatalogPretraga::class);
        $norm    = TextUtils::normalize('koje knjige imate na temu priče');

        // Žanrovski pogodak postoji kada pitanje NIJE tematsko…
        $this->assertTrue($katalog->pretrazi($norm, tematsko: false)->isNotEmpty());
        // …a za TEMATSKO pitanje žanr se preskače (tema ≠ žanr).
        $this->assertTrue($katalog->pretrazi($norm, tematsko: true)->isEmpty());
    }
}
