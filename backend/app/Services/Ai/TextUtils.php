<?php

namespace App\Services\Ai;

/**
 * TextUtils — statički helper za tekstualne operacije u AI stack-u.
 *
 * Posle prelaska na jedinstveni RAG pipeline, PRETPROCESIRANJE ULAZA
 * (transliteracija, normalizacija dijakritika, detekcija jezika langdetect-om)
 * radi Python mikroservis (rag_service). Ovde ostaju samo:
 *
 *  - kontrola IZLAZA modela: transliteracija ćirilice u latinicu i rezervna
 *    detekcija stranog odgovora (retry u OllamaClient),
 *  - zaštita od prompt injection-a na ulazu,
 *  - fiksna poruka za strani jezik (NOT_UNDERSTOOD).
 *
 * DIZAJN: final klasa, sve statičko, bez stanja.
 */
final class TextUtils
{
    /**
     * Fiksna poruka kada upit nije na srpskom (deterministička detekcija u
     * Python mikroservisu) — tekst propisan u bib-pravila.md, tačka 2.
     */
    public const NOT_UNDERSTOOD = 'Nisam razumeo poruku';

    /**
     * Kontra-upit kada se namera ne može pouzdano prepoznati (nijedna
     * poznata reč, nijedan pogodak u katalogu) — bib-pravila.md, tačka 3.
     */
    public const NOT_RECOGNIZED = 'Nisam vas razumeo, da li možete da ponovite';

    /**
     * CILJANO potpitanje za NASTAVAK razgovora sa nejasnom referencom („a ta
     * druga?", „njegove ostale?") koju ni kombinovana pretraga nad prethodnom
     * razmenom nije razrešila — konkretnije od opšteg kontra-upita, jer kaže
     * korisniku ŠTA da navede da bi se referenca razrešila.
     */
    public const NEJASNA_REFERENCA = 'Nisam siguran na šta tačno mislite — možete li da '
        . 'navedete naslov knjige ili ime autora?';

    /**
     * Fiksni odgovor kada NEULOGOVANI posetilac traži akciju koja zahteva
     * nalog (rezervacija, zaduženje, pozajmica) — auth-gate, deterministički
     * pre pretrage i pre poziva modelu.
     */
    public const AUTH_REQUIRED = 'Za tu akciju je potrebno da budete ulogovani kao član biblioteke. '
        . 'Ukoliko ste student Akademije, možete se učlaniti lično u biblioteci kod bibliotekarke — '
        . 'članarina je besplatna.';

    /**
     * Fiksni odgovor kada korisnik bez odgovarajućeg ovlašćenja traži podatak
     * iz evidencije zaduženja („ko je zadužio knjigu X"). Ime člana i datum
     * pozajmice su lični podaci: van asistenta dostupni su isključivo kroz
     * administrativni deo aplikacije, iza BibliotekarMiddleware-a. Isto
     * ograničenje važi i ovde — asistent ne sme biti zaobilazni put do
     * podatka koji korisniku ostatak sistema ne daje.
     */
    public const EVIDENCIJA_PRIVATNA = 'Ne mogu vam reći ko je pozajmio knjigu — evidencija zaduženja '
        . 'je poverljiva i uvid u nju ima samo bibliotekar. Mogu vam reći da li je knjiga trenutno '
        . 'slobodna i kada se očekuje njen povratak.';

    /**
     * Fiksni odgovor za datumsku pretragu arhive stariju od mesec dana —
     * IntentClassifier::MAX_DANA je gornja granica opsega.
     */
    public const ARHIVA_LIMIT = 'Pretraga arhive zaduženja moguća je najviše mesec dana unazad, '
        . 'pa vam za stariji period nažalost ne mogu dati podatke.';

    /**
     * Pošten fiksni odgovor na ČISTO TEMATSKO pitanje dok u fondu nema
     * nijednog opisa knjige (tema se čita iz opisa — bez opisa bi semantika
     * servirala žanr ili sličnost naslova kao „temu", što je netačno).
     * Nestaje sam od sebe: čim se unese prvi opis, tematska pitanja idu
     * normalnim semantičkim tokom.
     */
    public const TEMA_NEMA_PODATAKA = 'Za pretragu po temi trenutno nemam dovoljno podataka — '
        . 'opisi knjiga još nisu uneti u katalog. Mogu da vam pomognem pretragom po naslovu, '
        . 'autoru ili žanru.';

    /**
     * Pošten fiksni odgovor na pitanje o STRUČNOJ literaturi (matematika,
     * fizika, programiranje, baze podataka, elektronika, elektrotehnika…)
     * — takve građe u fondu trenutno nema (bib-izmene). Router poruku vraća
     * samo kada ni leksička pretraga ništa ne nađe, pa odgovor sam od sebe
     * prestaje da važi čim se prva stručna knjiga unese u katalog.
     */
    public const STRUCNA_LITERATURA = 'U katalogu trenutno nemamo stručnu literaturu '
        . '(matematika, fizika, programiranje, baze podataka, elektronika, elektrotehnika '
        . 'i slične oblasti) — fond čine književna dela. Mogu da vam pomognem pretragom '
        . 'po naslovu, autoru ili žanru.';

    /**
     * Pošten fiksni odgovor kada korisnik traži knjigu SLIČNU nekoj drugoj:
     * sličnost po temi se ne procenjuje jer katalog (uglavnom) nema opise —
     * semantičke preporuke nad nepoznatim naslovom su nasumične (ručno
     * testiranje 2026-07-10). Formulacija usaglašena sa eval ispravkama
     * (za_kod.md, testovi #19 i #20).
     */
    public const SLICNO_NEPOZNATO = 'Na osnovu podataka kojima raspolažem ne mogu pouzdano da '
        . 'utvrdim tematiku knjiga, jer katalog ne sadrži njihove opise. Mogu da vam pomognem '
        . 'da pronađete knjige po autoru, naslovu, žanru ili godini izdanja.';

    /**
     * Pošten fiksni odgovor na pitanje o podacima kojih U KATALOGU NEMA —
     * globalne/spoljne procene („najpopularniji žanr u Srbiji", „najčitanija
     * knjiga na svetu", „najbolja knjiga svih vremena", „najviše prodatih na
     * svetu"). Bot jasno kaže da raspolaže samo bibliotečkim katalogom, umesto
     * da katalošku statistiku predstavi kao globalnu (kandidati_ucenje_5,
     * korpa F). Nikad ne ulazi u few-shot — meri se na golden/held-out.
     */
    public const VAN_KATALOGA = 'Raspolažem samo podacima iz našeg bibliotečkog kataloga, '
        . 'pa takvu procenu ne mogu da donesem. Mogu da vam pomognem ako vas interesuje '
        . 'neki naslov, autor ili žanr iz naše ponude.';

    /**
     * Pošten fiksni odgovor na DUBLJU ANALITIKU po sekundarnim osama (godina,
     * mesto izdavanja, izdavač) i njihovim ukrštanjima (izdavač×žanr,
     * autor/žanr×godina, grad×popularnost…). Svesna granica dizajna: takve
     * kombinacije se NE računaju automatski — pošteno odbijanje umesto pogrešnog
     * jednoosnog odgovora ili halucinacije (kandidati_ucenje_5, korpa E).
     */
    public const DUBLJA_ANALITIKA = 'Trenutno ne mogu da radim dublju analitiku po izdavaču, '
        . 'godini i mestu izdavanja. Mogu da vam pomognem ako vas interesuje neki određeni '
        . 'naslov, autor ili žanr.';

    /**
     * Fiksni IDENTITET (Faza 2) — „ko si ti / šta si ti / da li si AI". Bot je
     * uvek Bib, asistent biblioteke; NIKADA ne pominje da je program/model/AI.
     */
    public const IDENTITET = 'Ja sam Bib, onlajn asistent biblioteke Akademije '
        . 'tehničko-vaspitačkih strukovnih studija u Nišu. Mogu da vam pomognem oko '
        . 'kataloga knjiga i informacija o biblioteci. Kako mogu da vam pomognem?';

    /**
     * Fiksni KONTAKT biblioteke — ime bibliotekarke, mejl i telefon su
     * nepromenljive činjenice, pa se serviraju deterministički (bez modela).
     * Jedan tekst pokriva ceo klaster pitanja: ko vodi biblioteku, kako se
     * zove bibliotekarka, koji su kontakt podaci, kome/s kim da se obratim.
     */
    public const KONTAKT = 'Bibliotekarka je Silvana Bogićević. Možete joj se obratiti '
        . 'mejlom na silvana.bogicevic@akademijanis.edu.rs, telefonom na +381 18 588 211 '
        . 'ili lično u biblioteci, na prvom spratu Akademije.';

    /**
     * Fiksni FAQ odgovori (Faza 4 — FAQ determinizam) za teme sa NEPROMENLJIVOM
     * činjenicom i distinktivnom leksikom: reset lozinke, otkazivanje rezervacije,
     * članarina, ne-prodaja, učlanjenje. Slab model je ova pitanja mrcvario
     * (parafraza „…kako da otkažem knjigu koju sam rezervisao?" → „Da li imate tu
     * knjigu?"), a strogi leksički FAQ matcher ih je promašivao zbog klase upitne
     * reči. Serviraju se deterministički, bez modela. Ključ → tekst preko FAQ_TEME.
     */
    public const FAQ_LOZINKA = 'Ako ne možete da pristupite nalogu, obratite se '
        . 'bibliotekarki (lično, mejlom ili telefonom) da vam resetuje lozinku — '
        . 'novu lozinku dobićete na svoj mejl.';

    public const FAQ_OTKAZ_REZERVACIJE = 'Rezervaciju možete da otkažete na kartici '
        . 'knjige, klikom na dugme „Otkaži rezervaciju".';

    public const FAQ_BROJ_REZERVACIJA = 'Istovremeno možete da imate najviše jednu '
        . 'aktivnu rezervaciju.';

    public const FAQ_CLANARINA = 'Članstvo u biblioteci je potpuno besplatno — nema '
        . 'članarine ni bilo kakvih troškova.';

    public const FAQ_NE_PRODAJE = 'Biblioteka ne prodaje knjige — možete ih besplatno '
        . 'pozajmiti kao član biblioteke.';

    public const FAQ_UCLANJENJE = 'Članom biblioteke može da postane svaki student '
        . 'Akademije. Dođite lično u biblioteku sa indeksom da potvrdite da ste student, '
        . 'a bibliotekarka će vam kreirati nalog (pristupne podatke dobijate na mejl).';

    /**
     * Mapa: ključ teme (IntentClassifier::faqFiksnaTema) → fiksni tekst odgovora.
     * Router (AiChatService) po ključu bira odgovor. Prazan ključ / null se ne ruta.
     *
     * @var array<string, string>
     */
    public const FAQ_TEME = [
        'lozinka'           => self::FAQ_LOZINKA,
        'otkaz_rezervacije' => self::FAQ_OTKAZ_REZERVACIJE,
        'broj_rezervacija'  => self::FAQ_BROJ_REZERVACIJA,
        'clanarina'         => self::FAQ_CLANARINA,
        'ne_prodaje'        => self::FAQ_NE_PRODAJE,
        'uclanjenje'        => self::FAQ_UCLANJENJE,
    ];

    /**
     * Post-generation fallback (Faza 2): kada izlaz modela sadrži stranu reč
     * koju ekavizacija nije prevela, ili je nesuvisao/degenerisan — odgovor se
     * NE prikazuje, već se traži da se pitanje ponovi. Bolje ćutati nego
     * servirati hrvatski/engleski ili halucinaciju.
     */
    public const PONOVITE_PITANJE = 'Izvinite, nisam siguran da sam vas dobro razumeo. '
        . 'Možete li da ponovite ili malo drugačije formulišete pitanje?';

    /**
     * GROUNDING fallback (Potez 1): kada odgovor modela imenuje knjigu/autora
     * kojih NEMA u priloženom katalogu (izmišljanje) — odgovor se ne prikazuje,
     * već se traži konkretizacija. „Nikad ne laži" je jače od „uvek odgovori".
     */
    public const NIJE_POUZDANO = 'Za taj upit nemam pouzdan podatak u katalogu, pa ne bih da '
        . 'nagađam. Recite mi konkretan naslov, autora ili žanr koji vas zanima, ili pogledajte '
        . 'katalog na sajtu.';

    /**
     * Subjektivno bez podataka (Potez 2): katalog ne sadrži broj strana, pa se
     * obim/dužina knjige ne može proceniti — pošteno umesto liste najčitanijih.
     */
    public const NEMA_OBIM = 'Katalog ne sadrži broj strana, pa ne mogu pouzdano da procenim obim '
        . 'knjige. Mogu da vam pomognem po žanru, autoru ili naslovu koji vas zanima.';

    /**
     * Subjektivno bez podataka (Potez 2): „poznatost" knjige merim jedino brojem
     * zaduživanja, pa je ne mogu proceniti drugačije — pošten odgovor.
     */
    public const NEMA_POZNATOST = 'Poznatost knjige ne mogu pouzdano da procenim — jedino merilo '
        . 'kojim raspolažem je broj zaduživanja. Recite mi žanr, autora ili naslov, pa ću vam rado pomoći.';

    /**
     * Distinktno HRVATSKE i ENGLESKE reči koje ekavizacija (IJEKAVICA_MAP) ne
     * prevodi — pojava bilo koje u IZLAZU modela obara ceo odgovor na
     * PONOVITE_PITANJE (guard, ne prevod). Normalizovan oblik (bez kvačica).
     * Samo NEDVOSMISLENE reči — nijedna nije i srpska (izbegnut lažni gard):
     * npr. „zrak" (srp. i hrv.) NIJE ovde. Kurirati/proširivati po potrebi.
     */
    public const STRANE_RECI = [
        // hrvatski leksemi (srpski ekvivalent u zagradi)
        'tisuca', 'tisuce', 'tisucu', 'kruh', 'vlak', 'vlaka', 'vlakom',
        'kazaliste', 'kazalista', 'glazba', 'glazbe', 'glazbu', 'tvrtka', 'tvrtke',
        'unatoc', 'sustav', 'sustava', 'sustavu', 'tijekom', 'znanost', 'znanstveni',
        'obitelj', 'obitelji', 'zrakoplov', 'kolodvor', 'nogomet', 'juha',
        'naranca', 'rajcica', 'opcina', 'opcine', 'bibliotecar', 'bibliotecara',
        'knjiznicar', 'knjiznicara', 'tocno',
        'nogometac', 'nogometas', 'nogometasi', 'spanjolski', 'spanjolska',
        'spanjolskog', 'spanjolske', 'spanjolsku', 'talijanski', 'talijanska',
        'talijan', 'njemacki', 'njemacka', 'njemackog', 'opcenito', 'sudjeluje',
        'sudjelovanje', 'sudjelovati', 'stoljece', 'stoljeca', 'gospodarstvo',
        'postotak', 'priopcenje', 'promidzba', 'povijesni', 'povijesna',
        // engleske reči (signal da je model prešao na engleski)
        'the', 'and', 'book', 'books', 'library', 'hello', 'please', 'sorry',
        'thanks', 'thank', 'answer', 'question', 'welcome', 'information', 'yes',
    ];

    private function __construct() {}

    // ─────────────────────────────────────────────────────────────────────────
    // TRANSLITERACIJA (izlaz modela — odgovor je uvek latinica)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normalizaciona mapa (ćirilica→latinica + dijakritici) — JEDAN izvor
     * pravila za PHP i Python, resources/normalization/normalization-map.json.
     * Keš po procesu; nedostajući/pokvaren fajl je greška u deploy-u i pada
     * glasno (tihi hardkodovani fallback bi vratio duplikat pravila).
     *
     * @var array{cir_digrafi: array<string,string>, cir_slova: array<string,string>, dijakritici: array<string,string>, nastavci: array<int,string>}|null
     */
    private static ?array $normMapa = null;

    /** @return array{cir_digrafi: array<string,string>, cir_slova: array<string,string>, dijakritici: array<string,string>, nastavci: array<int,string>} */
    private static function normalizacionaMapa(): array
    {
        if (self::$normMapa !== null) {
            return self::$normMapa;
        }

        $putanja = \dirname(__DIR__, 3) . '/resources/normalization/normalization-map.json';

        $sadrzaj = @file_get_contents($putanja);
        $mapa    = $sadrzaj !== false ? json_decode($sadrzaj, true) : null;

        if (!is_array($mapa)
            || empty($mapa['cir_digrafi']) || !is_array($mapa['cir_digrafi'])
            || empty($mapa['cir_slova'])   || !is_array($mapa['cir_slova'])
            || empty($mapa['dijakritici']) || !is_array($mapa['dijakritici'])
            || empty($mapa['nastavci'])    || !is_array($mapa['nastavci'])) {
            throw new \RuntimeException(
                "Normalizaciona mapa nije učitana ili je nepotpuna: {$putanja}"
            );
        }

        return self::$normMapa = $mapa;
    }

    /**
     * Konvertuje sva ćirilična slova u latinična ekvivalente (srpski standard).
     * Digrame (lj, nj, dž) obrađuje pre pojedinačnih slova. Pravila dolaze iz
     * zajedničke mape (normalization-map.json) — iste koje koristi Python.
     */
    public static function cyrillicToLatin(string $text): string
    {
        $mapa = self::normalizacionaMapa();

        return strtr(strtr($text, $mapa['cir_digrafi']), $mapa['cir_slova']);
    }

    /**
     * Kanonski oblik teksta za LEKSIČKO poređenje: latinica, mala slova,
     * bez dijakritika, sažeti razmaci. OGLEDALO funkcije normalize() u
     * rag_service/main.py — oba sloja (leksički u PHP-u i embedding u
     * Pythonu) čitaju ISTA pravila iz normalization-map.json, pa porede
     * isti oblik teksta.
     */
    public static function normalize(string $text): string
    {
        $text = self::cyrillicToLatin($text);
        $text = strtr($text, self::normalizacionaMapa()['dijakritici']);
        $text = mb_strtolower($text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * PRIDEVSKI oblik žanra → imenica žanra („istorijsku knjigu" → „istorija
     * knjigu", „dramsku" → „drame"). Fuzzy poklapanje promašuje ove oblike jer
     * im se osnova menja u nastavku (istorij-a vs istorij-sku). Primenjuje se
     * na već normalizovan tekst, PRE traženja žanra. Reč koja NIJE u mapi
     * (npr. „ljubavnu") ostaje netaknuta — takav žanr se obrađuje kao van fonda.
     */
    public static function kanonizujZanrPrideve(string $norm): string
    {
        foreach (self::ZANR_PRIDEVI as $pridev => $imenica) {
            $norm = preg_replace('/\b' . $pridev . '\w*/u', $imenica, $norm) ?? $norm;
        }

        return $norm;
    }

    /**
     * Heuristička procena da li je autor ŽENSKO — za slaganje glagola u rodu
     * („napisala" umesto „napisao"). Pravilo: žensko ako se IME (prva reč)
     * završava na -a i nije poznato muško ime na -a (Nikola, Ilija…), ili je u
     * spisku ženskih imena koja se ne završavaju na -a (Fransoaz, Odri…). Kada
     * nije sigurno, vraća false (muški rod je bezbedan podrazumevani oblik).
     */
    public static function jeZenskoIme(string $autor): bool
    {
        $prvi = self::normalize(explode(' ', trim($autor))[0] ?? '');

        if ($prvi === '') {
            return false;
        }

        if (in_array($prvi, self::ZENSKA_IMENA, true)) {
            return true;
        }

        if (in_array($prvi, self::MUSKA_IMENA_NA_A, true)) {
            return false;
        }

        return str_ends_with($prvi, 'a');
    }

    /** Muška imena na -a (ne smeju da se protumače kao ženska). */
    private const MUSKA_IMENA_NA_A = [
        'nikola', 'ilija', 'sava', 'luka', 'kosta', 'djura', 'andrija', 'matija',
        'aleksa', 'nemanja', 'jova', 'misa', 'pera', 'steva', 'vlada', 'sima',
        'toma', 'jaksa', 'vuksa', 'velja', 'gliga', 'vasa', 'joca', 'djoka',
        'nikita', 'mustafa', 'borisa', 'ilya', 'kuzma', 'gavra',
    ];

    /** Ženska imena koja se NE završavaju na -a. */
    private const ZENSKA_IMENA = [
        'fransoaz', 'odri', 'tove', 'zoe', 'helen', 'elizabet', 'margaret', 'ingrid',
        'doris', 'meri', 'rut', 'edit', 'agnes', 'karmen', 'nives', 'ester', 'marion',
        'an', 'nel', 'kejt', 'rejčel', 'greis', 'harper', 'toni', 'nives',
    ];

    /** Pridevski koren žanra → kanonska imenica žanra iz fonda. */
    private const ZANR_PRIDEVI = [
        'istorijsk'      => 'istorija',
        'dramsk'         => 'drame',
        'komicn'         => 'komedija',
        'komedijsk'      => 'komedija',
        'poetsk'         => 'poezija',
        'pesnick'        => 'poezija',
        'putopisn'       => 'putopis',
        'kriminalistick' => 'kriminalistika',
        'detektivsk'     => 'kriminalistika',
        'autobiografsk'  => 'autobiografija',
        'umetnick'       => 'umetnost',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // LEKSIČKA POREĐENJA — jedan izvor pravila za ceo AI sloj
    // (ranije kopirano u KatalogPretraga i StrukturniUpiti — Nalaz 4)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tokeni normalizovanog teksta (mala slova, ASCII — posle normalize()).
     *
     * @return array<int, string>
     */
    public static function tokenizuj(string $norm): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $norm) ?: []));
    }

    /**
     * Fuzzy jednakost dva normalizovana tokena (ASCII posle normalizacije):
     *  - kraći od 5 slova → samo egzaktno („radi" ≈ „rada" bi bio lažan pogodak),
     *  - 5–7 slova  → dozvoljena 1 izmena  (tipfeler: „kniga" → „knjiga"),
     *  - 8 i više   → dozvoljene 2 izmene (padeži: „dostojevskog" → „dostojevski").
     */
    public static function fuzzyJednake(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $min = min(strlen($a), strlen($b));

        if ($min < 5 || abs(strlen($a) - strlen($b)) > 2) {
            return false;
        }

        return levenshtein($a, $b) <= ($min >= 8 ? 2 : 1);
    }

    /**
     * Gruba osnova (koren) normalizovane reči: skida JEDAN padeški nastavak,
     * ali samo ako osnova ostaje dovoljno duga (≥ 4) — „rat" ostaje „rat",
     * „pricama"/„price"/„prica" → „pric".
     *
     * Lista nastavaka dolazi iz zajedničke mape (normalization-map.json,
     * sekcija „nastavci" — najduži prvo) — isti spisak čita i Python
     * (main.py::_koren), pa FTS5 koren-prefiks upiti i PHP brojanje reči
     * porede istu osnovu.
     */
    public static function koren(string $rec): string
    {
        foreach (self::normalizacionaMapa()['nastavci'] as $nastavak) {
            if (strlen($rec) - strlen($nastavak) >= 4 && str_ends_with($rec, $nastavak)) {
                return substr($rec, 0, strlen($rec) - strlen($nastavak));
            }
        }

        return $rec;
    }

    /**
     * Da li su dva normalizovana tokena OBLICI ISTE REČI: isti koren
     * (padeži: „prica" ≈ „pricama"). Namerno STROGO, bez fuzzy tolerancije —
     * koristi se za TAČNO BROJANJE (Nalaz 3), gde padeži iste reči ulaze u
     * broj, a slične tuđe reči ne: „princip" ≠ „priče", „vrata" ≠ „rat",
     * „prije" ≠ „priče" (fuzzy bi ih izjednačio na 1 izmenu).
     */
    public static function istaRec(string $a, string $b): bool
    {
        return self::koren($a) === self::koren($b);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IZLAZNI GUARD — validacija linkova (deterministički, bez drugog LLM prolaza)
    // ─────────────────────────────────────────────────────────────────────────

    /** Obrazac linka knjige u odgovoru — isti prepoznaje i JS widget. */
    public const LINK_PATTERN = '/\[([^\[\]]+)\]\(\/knjige\/(\d+)\)/';

    /**
     * Validira linkove u odgovoru nad mapom dozvoljenih knjiga [id => naslov]:
     *  - link ka knjizi VAN konteksta pitanja svodi se na običan tekst,
     *  - link ka dozvoljenoj knjizi dobija KANONSKI naslov iz baze — model ume
     *    da prepriča naslov („Istorija umetnosti" umesto pravog naslova), a
     *    ovde se to deterministički ispravlja bez drugog LLM prolaza.
     *
     * @param  array<int, string>  $dozvoljene  [id knjige => tačan naslov iz baze]
     */
    public static function ocistiLinkove(string $text, array $dozvoljene): string
    {
        return preg_replace_callback(
            self::LINK_PATTERN,
            fn (array $m) => array_key_exists((int) $m[2], $dozvoljene)
                ? '[' . $dozvoljene[(int) $m[2]] . '](/knjige/' . $m[2] . ')'
                : $m[1],
            $text
        ) ?? $text;
    }

    /**
     * Ista validacija linkova za STREAMING, u letu: tekst pre linka teče
     * odmah, a od „[" se zadržava u međuspremniku dok link ne stigne ceo —
     * validan dobija kanonski naslov iz mape, nevalidan se svodi na goli
     * tekst. Ako se do 160 znakova ne zatvori obrazac linka, sadržaj nije
     * link i pušta se kakav jeste (zaštita od zaglavljivanja).
     *
     * @param  iterable<string>     $tokens
     * @param  array<int, string>   $dozvoljene  [id knjige => tačan naslov iz baze]
     * @return \Generator<string>
     */
    public static function filtrirajLinkoveStream(iterable $tokens, array $dozvoljene): \Generator
    {
        $buffer = '';

        foreach ($tokens as $token) {
            $buffer .= $token;

            while ($buffer !== '') {
                $pos = strpos($buffer, '[');

                // Nema početka linka — pusti sve.
                if ($pos === false) {
                    yield $buffer;
                    $buffer = '';
                    break;
                }

                // Pusti tekst pre „[".
                if ($pos > 0) {
                    yield substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos);
                }

                // Kompletan link na početku međuspremnika → validiraj i pusti
                // (dozvoljen link uvek sa KANONSKIM naslovom iz baze).
                if (preg_match('/^\[([^\[\]]+)\]\(\/knjige\/(\d+)\)/', $buffer, $m)) {
                    yield array_key_exists((int) $m[2], $dozvoljene)
                        ? '[' . $dozvoljene[(int) $m[2]] . '](/knjige/' . $m[2] . ')'
                        : $m[1];
                    $buffer = substr($buffer, strlen($m[0]));
                    continue;
                }

                // Sigurno NIJE naš link (zatvorena zagrada bez „(" iza, ili
                // predugačko) → pusti „[" i skeniraj ostatak.
                if (preg_match('/^\[[^\[\]]*\][^(]/', $buffer) || strlen($buffer) > 160) {
                    yield '[';
                    $buffer = substr($buffer, 1);
                    continue;
                }

                // Link možda još stiže — čekaj sledeći token.
                break;
            }
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IZLAZNI GUARD — ekavizacija (model ume da umeša ijekavicu/hrvatski)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Česte ijekavske/hrvatske reči → standardni srpski (ekavica). Samo parovi
     * 1:1 kod kojih zamena cele reči ne može da pokvari rečenicu. Mala slova —
     * ekaviziraj() čuva veliko početno slovo.
     */
    private const IJEKAVICA_MAP = [
        'gdje' => 'gde', 'ovdje' => 'ovde', 'negdje' => 'negde', 'nigdje' => 'nigde',
        'svugdje' => 'svugde', 'vrijeme' => 'vreme', 'vrijedi' => 'vredi',
        'uvijek' => 'uvek', 'lijep' => 'lep', 'lijepa' => 'lepa', 'lijepo' => 'lepo',
        'riječ' => 'reč', 'riječi' => 'reči', 'dvije' => 'dve',
        'cijena' => 'cena', 'cijene' => 'cene', 'cijela' => 'cela', 'cijeli' => 'celi',
        'cijelo' => 'celo', 'svijet' => 'svet', 'svijeta' => 'sveta',
        'dijete' => 'dete', 'djeca' => 'deca', 'djecu' => 'decu',
        'mjesto' => 'mesto', 'mjesta' => 'mesta', 'mjesec' => 'mesec', 'mjeseca' => 'meseca',
        'poslije' => 'posle', 'prije' => 'pre', 'htio' => 'hteo', 'htjeli' => 'hteli',
        'vidjeti' => 'videti', 'živjeti' => 'živeti', 'razumijem' => 'razumem',
        'primjer' => 'primer', 'primjera' => 'primera', 'primjere' => 'primere',
        'sljedeći' => 'sledeći', 'sljedeće' => 'sledeće', 'uspješno' => 'uspešno',
        'pjesme' => 'pesme', 'pjesama' => 'pesama', 'pjesnik' => 'pesnik',
        'djelo' => 'delo', 'djela' => 'dela', 'umjetnost' => 'umetnost',
        'umjetnosti' => 'umetnosti', 'povijest' => 'istorija', 'povijesti' => 'istorije',
        'vjerujem' => 'verujem', 'čovjek' => 'čovek', 'čovjeka' => 'čoveka',
        'ponedjeljak' => 'ponedeljak', 'srijeda' => 'sreda', 'nedjelja' => 'nedelja',
        'nedjelje' => 'nedelje', 'prijedlog' => 'predlog', 'knjižnica' => 'biblioteka',
        'knjižnici' => 'biblioteci', 'knjižnice' => 'biblioteke', 'osjećaj' => 'osećaj',
        // Hrvatski oblici koje je model mešao u odgovore (eval za_kod.md) —
        // bibliotečki rečnik: rezervacija, osoblje, pozajmica, obaveštenja.
        'rezervirati' => 'rezervisati', 'rezerviraj' => 'rezerviši',
        'rezervirajte' => 'rezervišite', 'rezerviran' => 'rezervisan',
        'rezervirana' => 'rezervisana', 'rezervirano' => 'rezervisano',
        'rezervirali' => 'rezervisali',
        'bibliotekarica' => 'bibliotekarka', 'bibliotekarice' => 'bibliotekarke',
        'bibliotekarici' => 'bibliotekarki', 'bibliotekaricu' => 'bibliotekarku',
        'također' => 'takođe', 'takodjer' => 'takođe',
        'uvjet' => 'uslov', 'uvjeti' => 'uslovi', 'uvjete' => 'uslove',
        'obavijest' => 'obaveštenje', 'obavijesti' => 'obaveštenja',
        'tjedan' => 'nedelja', 'tjedna' => 'nedelje', 'tjedno' => 'nedeljno',
        'korištenje' => 'korišćenje', 'korištenjem' => 'korišćenjem',
        'izdan' => 'izdat', 'izdana' => 'izdata', 'izdano' => 'izdato',
        'osobno' => 'lično', 'osobni' => 'lični', 'trenutačno' => 'trenutno',
        'netko' => 'neko', 'nitko' => 'niko', 'svatko' => 'svako',
        'posudba' => 'pozajmica', 'posuditi' => 'pozajmiti', 'posudite' => 'pozajmite',
        'odnijeli' => 'odneli', 'donijeti' => 'doneti', 'donijeli' => 'doneli',
    ];

    /**
     * Zamenjuje ijekavske/hrvatske oblike ekavskim u OBIČNOM tekstu (cele
     * reči, čuva veliko početno slovo). Ne zvati nad naslovima knjiga —
     * naslovi u linkovima se prepisuju kanonski iz baze, pre ovog koraka.
     */
    public static function ekaviziraj(string $text): string
    {
        static $pattern = null;
        if ($pattern === null) {
            $pattern = '/\b(' . implode('|', array_map(
                fn (string $w) => preg_quote($w, '/'),
                array_keys(self::IJEKAVICA_MAP)
            )) . ')\b/iu';
        }

        return preg_replace_callback($pattern, function (array $m) {
            $zamena = self::IJEKAVICA_MAP[mb_strtolower($m[1])] ?? $m[1];

            // Čuvaj veliko početno slovo („Gdje" → „Gde").
            if (mb_substr($m[1], 0, 1) !== mb_strtolower(mb_substr($m[1], 0, 1))) {
                $zamena = mb_strtoupper(mb_substr($zamena, 0, 1)) . mb_substr($zamena, 1);
            }

            return $zamena;
        }, $text) ?? $text;
    }

    /**
     * Ekavizacija CELOG odgovora uz preskakanje linkova ka knjigama — naslov
     * u linku je kanonski iz baze i ne sme se menjati.
     */
    public static function ekavizirajTekst(string $text): string
    {
        $delovi = preg_split(self::LINK_PATTERN, $text, flags: PREG_SPLIT_DELIM_CAPTURE);
        if ($delovi === false) {
            return self::ekaviziraj($text);
        }

        // preg_split sa DELIM_CAPTURE vraća: [tekst, naslov, id, tekst, ...]
        $out = '';
        for ($i = 0; $i < count($delovi); $i += 3) {
            $out .= self::ekaviziraj($delovi[$i]);
            if (isset($delovi[$i + 1], $delovi[$i + 2])) {
                $out .= '[' . $delovi[$i + 1] . '](/knjige/' . $delovi[$i + 2] . ')';
            }
        }

        return $out;
    }

    /**
     * Post-generation GUARD (Faza 2): vraća PRVU stranu reč (hrvatsku/englesku
     * iz STRANE_RECI) nađenu u tekstu, ili null kada je tekst čist. Poredi se
     * nad normalizovanim tokenima (bez kvačica), po granici reči.
     */
    public static function sadrziStranuRec(string $text): ?string
    {
        $bloklista = array_flip(self::STRANE_RECI);

        foreach (self::tokenizuj(self::normalize($text)) as $token) {
            if (isset($bloklista[$token])) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Post-generation GUARD (Faza 2): da li je odgovor NESUVISAO — prazan,
     * gotovo prazan (bez ijednog slova), ili degenerisan (ista reč ponovljena
     * bar šest puta zaredom, tipičan „zaglavljen" izlaz slabog modela).
     */
    /**
     * GROUNDING GATE (Potez 1): da li odgovor imenuje knjigu čiji AUTOR stoji u
     * zagradi „(Ime Prezime)" a NIJE vezan za validan kataloški link — tipičan
     * potpis IZMIŠLJENE knjige u golom tekstu („Kraljašva i đaka (Milovan
     * Vitezović)", „Mrtva prijateljica (Linda McGowan)"). Poziva se TEK POSLE
     * ocistiLinkove, kada su svi preostali linkovi već grounded (u kontekstu);
     * grounded knjige imaju oblik „[Naslov](/knjige/ID) (Autor)" pa se uklone
     * pre provere, a preostali autor-citat u zagradi znači necitiranu knjigu.
     */
    public static function imaNegroundovanAutorCitat(string $text): bool
    {
        // Ukloni grounded citate: [Naslov](/knjige/ID) (Autor)?
        $bez = preg_replace(
            '/\[[^\[\]]+\]\(\/knjige\/\d+\)\s*(?:\([^()]*\))?/u',
            '',
            $text
        ) ?? $text;

        // Preostala zagrada sa LIČNIM IMENOM (bar dve reči s velikim slovom) =
        // knjiga imenovana bez grounded linka → izmišljeno. „(srpski)",
        // „(ATVSS)", „(08:00 do 16:00)" se NE računaju (nisu ime i prezime).
        return (bool) preg_match(
            '/\(\s*\p{Lu}[\p{Ll}.\-]+(?:\s+\p{Lu}[\p{Ll}.\-]+)+[^)]*\)/u',
            $bez
        );
    }

    public static function jeNesuvisaoOdgovor(string $text): bool
    {
        $ociscen = trim($text);

        if (!preg_match('/\p{L}{2,}/u', $ociscen)) {
            return true;
        }

        return (bool) preg_match('/\b(\p{L}+)\b(?:\s+\1\b){5,}/ui', $ociscen);
    }

    /**
     * Ekavizacija za STREAMING: čanke deli na cele reči (poslednja, možda
     * nepotpuna reč čeka sledeći čank), a čank koji je ceo link knjige
     * ([Naslov](/knjige/ID) — tako ih emituje filtrirajLinkoveStream)
     * prolazi netaknut.
     *
     * @param  iterable<string>  $chunks
     * @return \Generator<string>
     */
    public static function ekavizirajStream(iterable $chunks): \Generator
    {
        $rep = '';

        foreach ($chunks as $chunk) {
            // Ceo link knjige — prosledi kanonski, prethodno isprazni rep.
            if (preg_match('/^\[[^\[\]]+\]\(\/knjige\/\d+\)$/', $chunk)) {
                if ($rep !== '') {
                    yield self::ekaviziraj($rep);
                    $rep = '';
                }
                yield $chunk;
                continue;
            }

            $rep .= $chunk;

            // Pusti sve do POSLEDNJE granice reči; ostatak (možda presečena
            // reč) čeka sledeći čank.
            if (preg_match('/^(.*[\s.,!?;:„"()\-])([^\s.,!?;:„"()\-]*)$/us', $rep, $m)) {
                if ($m[1] !== '') {
                    yield self::ekaviziraj($m[1]);
                }
                $rep = $m[2];
            }
        }

        if ($rep !== '') {
            yield self::ekaviziraj($rep);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IZLAZNI GUARD — rezervna detekcija stranog ODGOVORA modela
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detektuje da li je model odgovorio na engleskom umesto na srpskom
     * (rezervni sloj — koristi ga OllamaClient za retry posle generisanja).
     * Funkcijske reči engleskog se ne pojavljuju kao samostalne reči u
     * srpskom latiničnom tekstu; prag od 2 pogotka pouzdano ukazuje na engleski.
     */
    public static function isEnglishResponse(string $text): bool
    {
        static $englishWords = [
            'the', 'and', 'but', 'not',
            'is', 'are', 'was', 'were', 'have', 'has', 'had',
            'will', 'would', 'could', 'should', 'can',
            'this', 'that', 'you', 'your',
            'with', 'from', 'for',
            'sure', 'certainly',
            'please', 'sorry', 'however', 'therefore',
            'available', 'currently', 'unfortunately', 'assistance',
        ];

        $lower = mb_strtolower($text);
        $found = 0;

        foreach ($englishWords as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $lower)) {
                if (++$found >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Srpska ćirilica — sve ostale ćirilične bukve (rus./mak./bug.) su STRANI skript. */
    private const SERBIAN_CYRILLIC =
        'абвгдђежзијклљмнњопрстћуфхцчџшАБВГДЂЕЖЗИЈКЛЉМНЊОПРСТЋУФХЦЧЏШ';

    /**
     * Detektuje strani SKRIPT u odgovoru: CJK, japanski kana, korejski hangul,
     * arapski, grčki i NE-srpsku ćirilicu (ruski/makedonski/bugarski).
     */
    public static function containsForeignScript(string $text): bool
    {
        if (preg_match('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}\x{0600}-\x{06FF}\x{0370}-\x{03FF}]/u', $text)) {
            return true;
        }

        if (preg_match_all('/[\x{0400}-\x{04FF}]/u', $text, $m)) {
            foreach ($m[0] as $ch) {
                if (mb_strpos(self::SERBIAN_CYRILLIC, $ch) === false) {
                    return true; // npr. ы, э, ё (rus.), ѓ, ќ, ѕ (mak.)
                }
            }
        }

        return false;
    }

    /**
     * Da li je ODGOVOR modela na stranom jeziku/skriptu — okida retry
     * u OllamaClient (rezervni sloj uz jezička pravila u promptu).
     */
    public static function isForeignResponse(string $text): bool
    {
        return self::isEnglishResponse($text) || self::containsForeignScript($text);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROMPT INJECTION DETEKCIJA (ulaz)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Markeri koji se filtriraju pre prosleđivanja poruke Ollami.
     *
     * Ograničenje: lista je statička i trivijalno zaobiđena permutacijama —
     * za lokalno testiranje je dovoljna; produkcija bi koristila guard model.
     */
    public const INJECTION_PATTERNS = [
        'ignoriši prethodne',
        'игнориши претходне',
        'zaboravi instrukcije',
        'заборави инструкције',
        'you are now',
        'ignore previous',
        'forget all',
        'novi sistem',
        'нови систем',
        'new system prompt',
        'disregard',
    ];

    /**
     * Vraća true ako poruka sadrži poznati injection pattern. Poruka se
     * transliteriše pre provere da bi se uhvatile i ćirilične varijante.
     */
    public static function containsInjection(string $message): bool
    {
        $lower = mb_strtolower(self::cyrillicToLatin($message));

        foreach (self::INJECTION_PATTERNS as $pattern) {
            $patternLower = mb_strtolower(self::cyrillicToLatin($pattern));
            if (str_contains($lower, $patternLower)) {
                return true;
            }
        }

        return false;
    }
}