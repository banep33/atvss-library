<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * FaqZnanje — zvanični P/O parovi o biblioteci iz few-shot-pitanja-atvss.md.
 *
 * Fajl je IZVOR ZNANJA za FAQ pitanja (radno vreme, članstvo, rezervacije,
 * lozinka, radno vreme…): za pitanje koje IntentClassifier prepozna kao
 * FAQ ovde se biraju najrelevantniji parovi i ubacuju u system prompt kao
 * sekcija sa zvaničnim formulacijama — model odgovara dosledno primerima,
 * a činjenice (brojevi telefona, rokovi, imena) prepisuje doslovno.
 *
 * Poređenje je leksičko, nad normalizovanim tekstom (ista pravila kao
 * KatalogPretraga): morfološki koren + fuzzy za tipfelere. FAQ reči su
 * većinom u IntentClassifier::STOP_RECI (namerno — nisu pojmovi za pretragu
 * kataloga), pa se ovde koristi SOPSTVENA, minimalna stop lista.
 *
 * Fajl se parsira jednom po HTTP zahtevu (keš u instanci); ako ga nema,
 * chat radi bez FAQ sekcije (prompt i dalje ima PODATKE O BIBLIOTECI).
 */
class FaqZnanje
{
    /** Najviše P/O parova koji ulaze u prompt za jedno pitanje. */
    private const MAX_PAROVA = 3;

    /**
     * Reči upitnog šablona bez informacione vrednosti za uparivanje FAQ
     * pitanja (normalizovan oblik) — NAMERNO kraća od STOP_RECI, jer su
     * FAQ pojmovi (rezervacija, lozinka, članarina…) tamo stop reči.
     */
    private const STOP = [
        'da', 'li', 'ne', 'i', 'a', 'o', 'u', 'na', 'za', 'od', 'do', 'sa',
        'se', 'je', 'su', 'sam', 'mi', 'me', 'ti', 'vi', 'vam', 'vas',
        'moj', 'moja', 'moje', 'svoj', 'svoje', 'svoju',
        'sta', 'gde', 'kad', 'kada', 'kako', 'koliko', 'zasto',
        'koji', 'koja', 'koje', 'koju', 'ako', 'ili', 'pa', 'jos',
        'mogu', 'moze', 'mozete', 'zelim', 'hocu', 'treba', 'radim',
        'postoji', 'imate', 'ima', 'bilo', 'nesto', 'neki', 'neka',
        // filler bez informacione vrednosti za FAQ uparivanje — da parafraze
        // („kako se ZOVE OSOBA koja vodi biblioteku", rezervaciju „koju sam
        //  NAPRAVIO") kolabiraju na isti skup tokena kao kurirani par
        'zove', 'zvati', 'osoba', 'nacin', 'nekih', 'neke', 'nekim',
        'napravio', 'napravila', 'napravim', 'smem', 'sme',
        'interesuje', 'interesuju', 'zanima', 'postupak', 'opcija',
    ];

    /** @var array<int, array{pitanje: string, odgovor: string, tokeni_pitanja: array<int, string>, tokeni: array<int, string>}>|null */
    private ?array $parovi = null;

    /** @var array<int, array{0: string, 1: string}>|null  [varijanta_norm, kanonska_rec] najduže prvo */
    private ?array $sinonimi = null;

    /**
     * SVI kurirani P/O parovi iz few-shot fajla (pitanje + odgovor), bez
     * uparivanja — izvor za `bot:nauci` (Faza B) koji ih embeduje u
     * `nauceni_primeri`. Parovi sa šablonom „[broj…]" su već preskočeni u
     * parsiranju.
     *
     * @return array<int, array{pitanje: string, odgovor: string}>
     */
    public function sviParovi(): array
    {
        return array_map(
            fn (array $p) => ['pitanje' => $p['pitanje'], 'odgovor' => $p['odgovor']],
            $this->parovi()
        );
    }

    /**
     * Najrelevantniji P/O parovi za dato (normalizovano) pitanje — za
     * sekciju prompta. Prazan niz kada nijedan par nije dovoljno srodan.
     *
     * @return array<int, array{pitanje: string, odgovor: string}>
     */
    public function relevantni(string $norm): array
    {
        $upitTokeni = $this->sadrzajniTokeni($this->primeniSinonime($norm));

        if (empty($upitTokeni)) {
            return [];
        }

        $kandidati = [];

        foreach ($this->parovi() as $par) {
            // Broje se pokriveni tokeni UPITA (svaki najviše jednom) — da
            // par sa više oblika iste reči u odgovoru („biblioteke,
            // bibliotekara, bibliotekaru…") ne preskoči par koji zaista
            // odgovara na pitanje. Pogodak u samom PITANJU para je jači
            // signal od pogotka u odgovoru (sekundarni kriterijum).
            $ukupno = $this->pokriveniTokeni($upitTokeni, $par['tokeni']);

            if ($ukupno === 0) {
                continue;
            }

            $kandidati[] = [
                'pitanje'   => $par['pitanje'],
                'odgovor'   => $par['odgovor'],
                'ukupno'    => $ukupno,
                'u_pitanju' => $this->pokriveniTokeni($upitTokeni, $par['tokeni_pitanja']),
            ];
        }

        usort($kandidati, fn (array $a, array $b) =>
            [$b['ukupno'], $b['u_pitanju']] <=> [$a['ukupno'], $a['u_pitanju']]);

        return array_map(
            fn (array $k) => ['pitanje' => $k['pitanje'], 'odgovor' => $k['odgovor']],
            array_slice($kandidati, 0, self::MAX_PAROVA)
        );
    }

    /**
     * DIREKTAN odgovor iz few-shot fajla: pitanje koje je ISTO kao PITANJE
     * nekog zvaničnog P/O para (modulo stop reči i padeži) dobija odgovor
     * tog para doslovno, kao FIKSNU poruku — model se ne poziva. Mali model
     * je i uz primere u promptu umeo da odgovori na pogrešno pitanje ili
     * izmisli pravilo (kandidati_ucenje: „Ko radi u biblioteci?" → radno
     * vreme; „Šta ako ne preuzmem rezervisanu knjigu?" → izmišljena
     * procedura).
     *
     * Uslovi su namerno strogi — delimičan pogodak i dalje ide kroz model
     * sa primerima u promptu:
     *  - bar DVA sadržajna tokena upita,
     *  - pokrivenost u OBA smera (isti skup sadržajnih reči — jednosmerna
     *    pokrivenost bi „koliko najviše knjiga mogu da zadužim" uparila sa
     *    parom o roku pozajmice),
     *  - ista UPITNA REČ („Ko radi u biblioteci?" i „Kada radi biblioteka?"
     *    imaju iste sadržajne tokene, a različit odgovor).
     */
    public function direktanOdgovor(string $norm): ?string
    {
        $norm       = $this->primeniSinonime($norm);
        $upitTokeni = $this->sadrzajniTokeni($norm);

        // Agresivan stop + sinonimi umeju da svedu pitanje na JEDAN nosilac
        // teme („kako se zove osoba koja vodi biblioteku" → {bibliotekar});
        // prazan skup i dalje ne može da se upari.
        if (empty($upitTokeni)) {
            return null;
        }

        $klasaUpita = $this->upitnaKlasa($norm);

        foreach ($this->parovi() as $par) {
            // STROGO uparivanje: ISTI skup sadržajnih reči u OBA smera — kratak
            // par („bibliotekar") ne sme fuzzy-korenom da se upari sa nevezanim
            // („biblioteku" u paru o učlanjenju). Generalizacija ide preko
            // sinonima (varijanta→kanonska reč) i filler stop-reči, ne preko
            // labavog praga. Ista upitna klasa je dodatni tvrd uslov.
            if (empty($par['tokeni_pitanja'])
                || $this->pokriveniTokeni($upitTokeni, $par['tokeni_pitanja']) < count($upitTokeni)
                || $this->pokriveniTokeni($par['tokeni_pitanja'], $upitTokeni) < count($par['tokeni_pitanja'])) {
                continue;
            }

            if ($klasaUpita !== $this->upitnaKlasa(TextUtils::normalize($par['pitanje']))) {
                continue;
            }

            return $par['odgovor'];
        }

        return null;
    }

    /**
     * Klasa UPITNE REČI pitanja — prva upitna reč u tekstu određuje na ŠTA
     * se pitanje odnosi („ko" ≠ „kada" i kad su sadržajne reči iste). Sva
     * da/ne pitanja („da li…", „mogu li…", „imate li…") su jedna klasa.
     */
    private function upitnaKlasa(string $norm): ?string
    {
        static $mapa = [
            'ko' => 'ko', 'kada' => 'kada',
            'kad' => 'kada', 'gde' => 'gde', 'koliko' => 'koliko',
            'kolko' => 'koliko', 'zasto' => 'zasto',
            // padeži „ko" — „sa KIM da razgovaram", „KOME da se obratim",
            //  „KOGA da pitam" su ista klasa kao „KO je bibliotekar"
            'kome' => 'ko', 'kim' => 'ko', 'koga' => 'ko',
            // „koji/kako/šta" su interkambijabilne formulacije ISTOG pitanja
            //  („KOJI je postupak za učlanjenje" = „KAKO da se učlanim" =
            //   „ŠTA treba da uradim da se učlanim") → jedna klasa. Razlika
            //  „KO radi" (osoba) vs „KADA radi" (vreme) OSTAJE odvojena.
            'sta' => 'opis', 'kako' => 'opis',
            'koji' => 'opis', 'koja' => 'opis', 'koje' => 'opis',
            'da' => 'danel', 'mogu' => 'danel', 'moze' => 'danel',
            'mozes' => 'danel', 'mozete' => 'danel', 'imate' => 'danel',
            'imas' => 'danel', 'ima' => 'danel', 'postoji' => 'danel',
            'smem' => 'danel', 'sme' => 'danel', 'jel' => 'danel',
            'zelim' => 'danel', 'hocu' => 'danel',
        ];

        foreach (TextUtils::tokenizuj($norm) as $token) {
            if (isset($mapa[$token])) {
                return $mapa[$token];
            }
        }

        return null;
    }

    /**
     * Najveća pokrivenost sadržajnih tokena upita PITANJEM nekog para
     * (0.0–1.0) — signal da za pitanje postoji zvaničan odgovor u few-shot
     * fajlu. Poredi se samo sa tokenima PITANJA parova (ne i odgovora), da
     * uzgredni pogodak u tekstu odgovora ne bi davao lažni signal.
     *
     * Koristi se kao gate u AiChatService: pitanje sa dobrim FAQ pogotkom
     * se NIKADA ne odbija kao "van teme" / "nisam razumeo" (eval za_kod.md:
     * "Imate li skripte za ispite?", "Da li mogu da kupim knjigu?"), već
     * ide kroz model sa FAQ primerima u promptu.
     */
    public function najboljaPokrivenost(string $norm): float
    {
        $upitTokeni = $this->sadrzajniTokeni($this->primeniSinonime($norm));

        if (empty($upitTokeni)) {
            return 0.0;
        }

        $max = 0;

        foreach ($this->parovi() as $par) {
            $max = max($max, $this->pokriveniTokeni($upitTokeni, $par['tokeni_pitanja']));

            if ($max === count($upitTokeni)) {
                break; // puna pokrivenost — dalje traženje ne menja rezultat
            }
        }

        return $max / count($upitTokeni);
    }

    /**
     * Koliko tokena upita je pokriveno tokenima para (svaki token upita se
     * broji najviše jednom) — morfološki koren + fuzzy za tipfelere.
     *
     * @param  array<int, string>  $upitTokeni
     * @param  array<int, string>  $parTokeni
     */
    private function pokriveniTokeni(array $upitTokeni, array $parTokeni): int
    {
        $pogodjeno = 0;

        foreach ($upitTokeni as $u) {
            foreach ($parTokeni as $t) {
                if (TextUtils::istaRec($u, $t) || TextUtils::fuzzyJednake($u, $t)) {
                    $pogodjeno++;
                    break;
                }
            }
        }

        return $pogodjeno;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parsira few-shot fajl u P/O parove (keš po HTTP zahtevu).
     *
     * Format u fajlu:  **P: pitanje?**  pa u sledećem redu  O: odgovor…
     * Parovi čiji odgovor sadrži šablonski umetak („[broj iz baze]") se
     * preskaču — na ta pitanja odgovaraju sveži agregati iz baze, ne šablon.
     *
     * @return array<int, array{pitanje: string, odgovor: string, tokeni_pitanja: array<int, string>, tokeni: array<int, string>}>
     */
    private function parovi(): array
    {
        if ($this->parovi !== null) {
            return $this->parovi;
        }

        $this->parovi = [];

        $putanja = (string) config('ai.faq_primeri_path', '');

        if ($putanja === '' || !is_file($putanja)) {
            Log::warning('[FaqZnanje] few-shot fajl nije pronađen', ['putanja' => $putanja]);
            return $this->parovi;
        }

        $tekst = (string) file_get_contents($putanja);

        if (!preg_match_all('/\*\*P:\s*(.+?)\*\*\s*\r?\nO:\s*(.+?)(?=\r?\n\s*\r?\n|\z)/su', $tekst, $m, PREG_SET_ORDER)) {
            return $this->parovi;
        }

        foreach ($m as $par) {
            $pitanje = trim(preg_replace('/\s+/u', ' ', $par[1]) ?? '');
            $odgovor = trim(preg_replace('/\s+/u', ' ', $par[2]) ?? '');

            if ($pitanje === '' || $odgovor === '' || str_contains($odgovor, '[broj')) {
                continue;
            }

            // Tokeni i PITANJA i ODGOVORA — „Ko je Silvana?" mora da pogodi
            // par „Ko je bibliotekar?" preko imena koje stoji u odgovoru.
            $tokeniPitanja = $this->sadrzajniTokeni(TextUtils::normalize($pitanje));

            $this->parovi[] = [
                'pitanje'        => $pitanje,
                'odgovor'        => $odgovor,
                'tokeni_pitanja' => $tokeniPitanja,
                'tokeni'         => array_values(array_unique(array_merge(
                    $tokeniPitanja,
                    $this->sadrzajniTokeni(TextUtils::normalize($odgovor)),
                ))),
            ];
        }

        return $this->parovi;
    }

    /**
     * FAQ SINONIMI (kurirani): u korisničkom pitanju zameni varijantu kanonskom
     * rečju koja postoji u FAQ paru — deterministička generalizacija bez modela
     * (za llama3.2:3b na CPU, gde embedding semantika nije pouzdana nad srpskim:
     * „šifra"→„lozinka", „osoba koja vodi biblioteku"→„bibliotekar"). Primenjuje
     * se SAMO na pitanje (parovi ostaju kanonski). Zamena po granici reči,
     * najduža varijanta prvo (fraze pre pojedinačnih reči).
     */
    private function primeniSinonime(string $norm): string
    {
        foreach ($this->sinonimi() as [$varijanta, $kanonska]) {
            $norm = preg_replace('/\b' . preg_quote($varijanta, '/') . '\b/u', $kanonska, $norm) ?? $norm;
        }

        return $norm;
    }

    /**
     * Učitava faq-sinonimi.json u ravnu listu [varijanta, kanonska], sortiranu
     * po dužini varijante opadajuće (fraze pre reči). Fajl je opcion — ako ga
     * nema, sinonimski sloj je prazan (leksičko jezgro radi kao ranije).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function sinonimi(): array
    {
        if ($this->sinonimi !== null) {
            return $this->sinonimi;
        }

        $this->sinonimi = [];

        $putanja = (string) config('ai.faq_sinonimi_path', '');
        $sadrzaj = ($putanja !== '' && is_file($putanja)) ? @file_get_contents($putanja) : false;
        $mapa    = $sadrzaj !== false ? json_decode($sadrzaj, true) : null;

        foreach (($mapa['sinonimi'] ?? []) as $kanonska => $varijante) {
            foreach ((array) $varijante as $v) {
                $this->sinonimi[] = [(string) $v, (string) $kanonska];
            }
        }

        usort($this->sinonimi, fn (array $a, array $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $this->sinonimi;
    }

    /** Sadržajni tokeni za uparivanje: dužina ≥ 4, van mini stop liste. @return array<int, string> */
    private function sadrzajniTokeni(string $norm): array
    {
        return array_values(array_filter(
            TextUtils::tokenizuj($norm),
            fn (string $t) => mb_strlen($t) >= 4 && !in_array($t, self::STOP, true)
        ));
    }
}