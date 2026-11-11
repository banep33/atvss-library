<?php

namespace Tests\Unit;

use App\Models\Knjiga;
use App\Services\Ai\IntentClassifier;
use App\Services\Ai\Namera;
use App\Services\Ai\TextUtils;
use App\Services\Ai\TipNamere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IntentClassifier — klasifikacija namere za sve tipove pitanja iz
 * specifikacija (bib-pravila.md, pitanja-za-katalog.md) + razrešavanje
 * nastavaka razgovora (zahtev 6).
 */
class IntentClassifierTest extends TestCase
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

    private function klasifikuj(string $pitanje, array $history = []): Namera
    {
        return app(IntentClassifier::class)
            ->klasifikuj(TextUtils::normalize($pitanje), $pitanje, $history);
    }

    // ── DATUMSKI OPSEG ──────────────────────────────────────────────────────

    /** @test */
    public function datumski_opseg_pre_nedelju_dana(): void
    {
        $n = $this->klasifikuj('Koje knjige su zadužene pre nedelju dana?');

        $this->assertSame(TipNamere::DatumskiOpseg, $n->tip);
        $this->assertSame(['dana' => 7], $n->datumskiOpseg);
    }

    /** @test */
    public function datumski_opseg_broj_dana_i_nedelja(): void
    {
        $this->assertSame(['dana' => 2], $this->klasifikuj('koje knjige su zadužene pre 2 dana')->datumskiOpseg);
        $this->assertSame(['dana' => 14], $this->klasifikuj('zadužene knjige u poslednje dve nedelje')->datumskiOpseg);
        $this->assertSame(['dana' => 1], $this->klasifikuj('koje knjige su zadužene juče')->datumskiOpseg);
    }

    /** @test */
    public function datumski_opseg_preko_mesec_dana_je_preko_limita(): void
    {
        $this->assertSame(
            ['preko_limita' => true],
            $this->klasifikuj('koje knjige su zadužene pre tri meseca')->datumskiOpseg
        );
    }

    /** @test */
    public function licno_datumsko_pitanje_nije_arhivski_upit(): void
    {
        $this->assertNull($this->klasifikuj('koje sam knjige zadužio prošle nedelje')->datumskiOpseg);
    }

    /** @test */
    public function datumski_izraz_bez_pomena_zaduzenja_nije_arhivski_upit(): void
    {
        $this->assertNull($this->klasifikuj('šta je bilo pre nedelju dana')->datumskiOpseg);
    }

    /** @test */
    public function nastavak_nasledjuje_datumski_kontekst(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Koje knjige su zadužene pre 2 dana?'],
            ['role' => 'assistant', 'content' => 'U periodu od 05.07.2026. do danas zabeleženo je 1 zaduženje.'],
        ];

        $n = $this->klasifikuj('a za prošlu nedelju?', $history);

        $this->assertSame(TipNamere::DatumskiOpseg, $n->tip);
        $this->assertSame(['dana' => 7], $n->datumskiOpseg);
    }

    // ── STATISTIKA UČESTALOSTI ──────────────────────────────────────────────

    /** @test */
    public function statistika_ucestalosti_zaduzivanja(): void
    {
        $this->assertTrue($this->klasifikuj('koliko često se zadužuju knjige')->statistika);
        $this->assertTrue($this->klasifikuj('koliko se nedeljno zaduži knjiga')->statistika);
    }

    /** @test */
    public function pitanje_o_pravilima_nije_statistika(): void
    {
        $this->assertFalse($this->klasifikuj('koliko puta mogu da rezervišem knjigu')->statistika);
    }

    // ── NAJTRAŽENIJE / NAJČEŠĆE IZDAVANE (Nalaz 5 — sve formulacije) ────────

    /** @test */
    public function najtrazenije_prepoznaje_sve_formulacije(): void
    {
        $formulacije = [
            'koja knjiga se najviše traži',
            'koja knjiga se najčešće izdaje',
            'koja je najčitanija knjiga',
            'koja knjiga ima najviše zaduženja',
            'najizdavanija knjiga u biblioteci',
            'koja knjiga se najviše pozajmljuje',
            'koja knjiga ima najveći broj zaduženja',
            'najviše puta zaduživana knjiga',
        ];

        foreach ($formulacije as $pitanje) {
            $this->assertTrue(
                $this->klasifikuj($pitanje)->najcitanije,
                "Nije prepoznato kao najtraženije: „{$pitanje}”"
            );
        }
    }

    /** @test */
    public function pitanje_o_limitu_zaduzenja_nije_najtrazenije(): void
    {
        $this->assertFalse($this->klasifikuj('koliko najviše knjiga mogu da zadužim')->najcitanije);
    }

    // ── BROJANJE REČI U NASLOVU ─────────────────────────────────────────────

    /** @test */
    public function brojanje_reci_u_naslovu(): void
    {
        $this->assertSame('drama', $this->klasifikuj('koliko naslova sadrži reč "drama"')->countRec);
        $this->assertSame('prica', $this->klasifikuj('koliko knjiga ima reč priča u naslovu')->countRec);
        $this->assertSame(TipNamere::BrojanjeReci, $this->klasifikuj('koliko naslova sadrži reč "drama"')->tip);
    }

    // ── AKCIONI ZAHTEV (auth-gate) ──────────────────────────────────────────

    /** @test */
    public function akcioni_zahtev_prepoznat(): void
    {
        $this->assertTrue($this->klasifikuj('želim da rezervišem knjigu')->akcioniZahtev);
        $this->assertTrue($this->klasifikuj('rezerviši mi knjigu Prokleta avlija')->akcioniZahtev);
    }

    /** @test */
    public function pitanje_o_postupku_nije_akcioni_zahtev(): void
    {
        $this->assertFalse($this->klasifikuj('kako da rezervišem knjigu')->akcioniZahtev);
    }

    // ── KO JE X / KO JE ZADUŽIO ─────────────────────────────────────────────

    /** @test */
    public function ko_je_osoba_izvlaci_frazu(): void
    {
        $n = $this->klasifikuj('ko je Nikola Tesla');

        $this->assertSame('nikola tesla', $n->koJe);
        $this->assertFalse($n->koJeZaduzio);
    }

    /** @test */
    public function ko_je_zaduzio_je_arhivski_upit_a_ne_pitanje_o_osobi(): void
    {
        $n = $this->klasifikuj('ko je zadužio knjigu Na Drini ćuprija');

        $this->assertTrue($n->koJeZaduzio);
        $this->assertNull($n->koJe);
    }

    // ── TEMA vs ŽANR ────────────────────────────────────────────────────────

    /** @test */
    public function tematsko_pitanje_prepoznato(): void
    {
        $n = $this->klasifikuj('imate li knjige na temu prijateljstva');

        $this->assertTrue($n->tematsko);
        $this->assertFalse($n->agregatno);
    }

    /** @test */
    public function zanr_lista_izvlaci_frazu(): void
    {
        $n = $this->klasifikuj('izlistaj knjige žanra priče');

        $this->assertSame(TipNamere::ZanrLista, $n->tip);
        $this->assertSame('price', $n->zanrListaFraza);

        $this->assertSame('roman', $this->klasifikuj('koje sve knjige imate iz žanra roman')->zanrListaFraza);
    }

    /** @test */
    public function tematsko_pitanje_ne_postaje_zanr_lista(): void
    {
        // „na temu priče" pominje žanrovsku reč, ali TEMA ima prednost.
        $n = $this->klasifikuj('koje sve knjige imate na temu priče');

        $this->assertTrue($n->tematsko);
        $this->assertNotSame(TipNamere::ZanrLista, $n->tip);
    }

    // ── KONVERZACIJSKI KONTEKST ─────────────────────────────────────────────

    /** @test */
    public function trazenje_ostatka_ponavlja_prethodnu_nameru(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'izlistaj knjige žanra romani'],
            ['role' => 'assistant', 'content' => 'U žanru Romani imamo 35 knjiga: … — i još 5 stavki; recite ukoliko želite ostatak spiska.'],
        ];

        $n = $this->klasifikuj('daj mi ostatak spiska', $history);

        $this->assertNotNull($n->ostatakOd);
        $this->assertSame(TipNamere::ZanrLista, $n->ostatakOd->tip);
        $this->assertSame('romani', $n->ostatakOd->zanrListaFraza);
    }

    /** @test */
    public function ostatak_bez_skracenog_spiska_u_prethodnom_odgovoru_se_ignorise(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'izlistaj knjige žanra romani'],
            ['role' => 'assistant', 'content' => 'U žanru Romani imamo 3 knjige: A, B, C.'],
        ];

        $this->assertNull($this->klasifikuj('daj mi ostatak spiska', $history)->ostatakOd);
    }

    /** @test */
    public function kratka_anaforicna_poruka_je_nastavak(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'koje knjige imate od Ive Andrića?'],
            ['role' => 'assistant', 'content' => 'Imamo [Prokleta avlija](/knjige/1) (Ivo Andrić).'],
        ];

        $n = $this->klasifikuj('a taj autor ima još nešto?', $history);

        $this->assertTrue($n->nastavak);
        $this->assertNotNull($n->kontekstNorm);
        $this->assertStringContainsString('andrica', $n->kontekstNorm);
    }

    /** @test */
    public function duga_samostalna_poruka_nije_nastavak(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'kada radi biblioteka?'],
            ['role' => 'assistant', 'content' => 'Radnim danima od 08 do 16.'],
        ];

        $n = $this->klasifikuj('da li u katalogu imate neku knjigu iz oblasti mašinskog inženjerstva za studente', $history);

        $this->assertFalse($n->nastavak);
    }

    // ── GODINA IZDANJA — svi oblici filtra ──────────────────────────────────

    /** @test */
    public function godina_izdanja_prepoznaje_granice_i_opsege(): void
    {
        $this->assertSame(['od' => 2015, 'do' => null], $this->klasifikuj('koje knjige imate izdate posle 2015')->godinaOpseg);
        $this->assertSame(['od' => 2015, 'do' => null], $this->klasifikuj('imate li nešto novije od 2015')->godinaOpseg);
        $this->assertSame(['od' => null, 'do' => 2000], $this->klasifikuj('knjige izdate pre 2000 godine')->godinaOpseg);
        $this->assertSame(['od' => null, 'do' => 2000], $this->klasifikuj('imate li naslove starije od 2000')->godinaOpseg);
        $this->assertSame(['od' => 2010, 'do' => 2020], $this->klasifikuj('daj mi sve knjige između 2010 i 2020')->godinaOpseg);
        $this->assertSame(['od' => 2010, 'do' => 2020], $this->klasifikuj('koje knjige imate od 2010 do 2020')->godinaOpseg);
        $this->assertSame(['od' => 2018, 'do' => 2018], $this->klasifikuj('koje knjige imate iz 2018')->godinaOpseg);

        $this->assertSame(TipNamere::GodinaIzdanja, $this->klasifikuj('koje knjige imate izdate posle 2015')->tip);
    }

    /** @test */
    public function obrnute_granice_opsega_se_zamene(): void
    {
        $this->assertSame(
            ['od' => 2010, 'do' => 2020],
            $this->klasifikuj('koje knjige imate izdate između 2020 i 2010')->godinaOpseg
        );
    }

    /** @test */
    public function tacna_godina_bez_predloga_je_godina_izdanja(): void
    {
        $this->assertSame(['od' => 2018, 'do' => 2018], $this->klasifikuj('koje knjige su izdate 2018. godine')->godinaOpseg);
        $this->assertSame(['od' => 2019, 'do' => 2019], $this->klasifikuj('da li imate nešto objavljeno 2019')->godinaOpseg);
    }

    /** @test */
    public function zanrovska_rec_je_dovoljan_kontekst_za_godinu(): void
    {
        // „poezija" nije u kataloškom regexu, ali jeste žanrovska reč.
        $this->assertSame(['od' => 2015, 'do' => 2015], $this->klasifikuj('šta imate od poezije iz 2015')->godinaOpseg);
    }

    /** @test */
    public function relativna_godina_izdanja_se_racuna_od_danas(): void
    {
        $sada = (int) now()->year;

        $this->assertSame(['od' => $sada, 'do' => $sada], $this->klasifikuj('koje knjige su izdate ove godine')->godinaOpseg);
        $this->assertSame(['od' => $sada - 1, 'do' => $sada - 1], $this->klasifikuj('koje knjige su izdate prošle godine')->godinaOpseg);
        $this->assertSame(['od' => $sada - 2, 'do' => $sada - 2], $this->klasifikuj('imate li knjige izdate pretprošle godine')->godinaOpseg);
    }

    /** @test */
    public function starost_u_godinama_se_pretvara_u_opseg(): void
    {
        $sada = (int) now()->year;

        $this->assertSame(['od' => null, 'do' => $sada - 20], $this->klasifikuj('imate li knjige starije od 20 godina')->godinaOpseg);
        $this->assertSame(['od' => $sada - 5, 'do' => null], $this->klasifikuj('koje knjige imate novije od 5 godina')->godinaOpseg);
        $this->assertSame(['od' => $sada - 5, 'do' => null], $this->klasifikuj('daj mi knjige iz poslednjih pet godina')->godinaOpseg);
    }

    /** @test */
    public function arhivska_pitanja_ne_postaju_godina_izdanja(): void
    {
        // Izričito „zadužene" — arhiva, ne godina izdanja.
        $this->assertNull($this->klasifikuj('koje knjige su zadužene prošle godine')->godinaOpseg);
        $this->assertNull($this->klasifikuj('koje knjige su zadužene pre 2015 godine')->godinaOpseg);
        // A „izdate prošle godine" jeste godina izdanja, ne arhivski opseg.
        $this->assertNull($this->klasifikuj('koje knjige su izdate prošle godine')->datumskiOpseg);
    }

    // ── KONVERZACIONE PORUKE ────────────────────────────────────────────────

    /** @test */
    public function pozdrav_i_zahvalnica_su_konverzacioni(): void
    {
        $this->assertTrue($this->klasifikuj('hvala puno')->konverzacioni);
        $this->assertTrue($this->klasifikuj('dobar dan')->konverzacioni);
        $this->assertFalse($this->klasifikuj('ko je najbolji fudbaler na svetu')->konverzacioni);
    }
}
