<?php

namespace App\Services\Ai;

use Illuminate\Support\Collection;

/**
 * KatalogPretraga — DETERMINISTIČKI leksički sloj pretrage kataloga.
 *
 * Radi PRE semantičke (vektorske) pretrage, nad normalizovanim tekstom
 * (TextUtils::normalize — isti oblik kao normalize() u rag_service/main.py):
 *
 *  1. kljucneReci()   — izdvaja sadržajne reči upita (bez upitnog šablona),
 *  2. pretrazi()      — egzaktan i fuzzy (Levenshtein) pogodak ključnih
 *                       reči u nazive, autore i žanrove svih knjiga;
 *                       pogodak naslova/autora/žanra je JAK (score 1.0)
 *                       i ide ispred semantičkih pogodaka,
 *  3. imaPoznatihReci() — da li upit sadrži ijednu poznatu reč
 *                       (za kontra-upit „Nisam vas razumeo…", tačka 3).
 *
 * KLASIFIKACIJA NAMERE ne živi ovde: prepoznavanje intencija, akcionih
 * zahteva, datumskih opsega i imenovanih entiteta radi IntentClassifier —
 * ova klasa samo TRAŽI po katalogu. Fuzzy pravila i tokenizacija su
 * zajedničke (TextUtils), rečnici upitnog šablona u IntentClassifier-u.
 *
 * Fuzzy pravilo pokriva tipfelere („kniga"), padeže („Dostojevskog" →
 * „Dostojevski") i kucanje bez dijakritika (normalizacija ih je već skinula).
 *
 * Zašto leksički sloj uz semantiku: izmereno je da embedding pretraga ne
 * razdvaja pogodak po imenu (naslov/autor) od šuma fonda — imenovane stvari
 * mora da nađe deterministički sloj, semantika ostaje za tematska pitanja.
 *
 * Fond je mali (~stotine knjiga), pa se rečnik gradi po zahtevu jednim
 * upitom (LibraryDataService) — bez keša koji bi mogao da zastari.
 */
class KatalogPretraga
{
    /** Koliko najviše jakih pogodaka vraćamo po upitu (koliko i semantika). */
    private const MAX_POGODAKA = 5;

    /**
     * Česte reči (posle normalizacije) koje se poklapaju sa PREZIMENOM autora
     * a nisu pretraga po autoru — homografi. „kući/kuću/kuća…" (prilog „kod
     * kuće") = prezime „Kuci" (Coetzee): „uzmem knjigu kući" NE sme da postane
     * lista knjiga tog autora. Isključuju se SAMO iz autorskog uparivanja —
     * poklapanje po naslovu/žanru ove reči i dalje mogu da koriste.
     */
    private const NE_AUTORSKE_RECI = ['kuci', 'kucu', 'kuca', 'kuce', 'kucom', 'kucama'];

    public function __construct(
        private readonly LibraryDataService $data,
    ) {}

    /**
     * Ključne reči upita: normalizovani tokeni bez stop reči, dužine ≥ 3.
     *
     * @return array<int, string>
     */
    public function kljucneReci(string $norm): array
    {
        return array_values(array_filter(
            TextUtils::tokenizuj($norm),
            fn (string $t) => mb_strlen($t) >= 3 && !in_array($t, IntentClassifier::STOP_RECI, true)
        ));
    }

    /**
     * Jaki (leksički) pogoci: knjige čiji naziv, autor ili žanr odgovara
     * ključnim rečima upita. Redosled prioriteta: naziv > autor > žanr.
     *
     * @param  bool  $tematsko  TEMATSKO pitanje („na temu priče") preskače
     *               pogodak po ŽANRU — korisnik traži radnju, ne žanr
     *               „Priče"; temu pronalazi semantička pretraga po opisu.
     *               Odluku donosi IntentClassifier, prosleđuje Router.
     * @return Collection<int, array{id: int, score: float, razlog: string}>
     */
    public function pretrazi(string $norm, bool $tematsko = false): Collection
    {
        $kljucne = $this->kljucneReci($norm);

        if (empty($kljucne)) {
            return collect();
        }

        $poNazivu = [];
        $poAutoru = [];
        $poZanru  = [];

        // Reči koje NE SMEJU da pogađaju autore: nazivi žanrova („roman" ≈
        // „Soman" bi bio lažan pogodak) i homografi prezimena (NE_AUTORSKE_RECI
        // — „kući" = autor „Kuci"). Isti spisak važi i za fuzzy i za tačno
        // poklapanje (array_intersect), inače bi homograf procurio kroz tačno.
        $nijeAutor = array_merge($this->sviZanrTokeni(), self::NE_AUTORSKE_RECI);
        $zaAutora  = array_values(array_filter(
            $kljucne,
            fn (string $t) => !in_array($t, $nijeAutor, true)
        ));

        foreach ($this->recnik() as $knjiga) {
            // 1) NAZIV — pun naslov u upitu, ili većina reči naslova pogođena.
            if ($this->pogadjaNaziv($kljucne, $norm, $knjiga)) {
                $poNazivu[] = $knjiga['id'];
                continue;
            }

            // 2) AUTOR — bar jedna reč imena autora (≥ 4 slova, zbog fuzzy).
            if ($this->pogadjaTokene($zaAutora, $knjiga['autor_tokeni'], 4)
                || array_intersect($zaAutora, $knjiga['autor_tokeni'])) {
                $poAutoru[] = $knjiga['id'];
                continue;
            }

            // 3) ŽANR — naziv žanra među ključnim rečima (preskočeno za
            //    tematska pitanja, vidi $tematsko).
            if (!$tematsko && $this->pogadjaTokene($kljucne, $knjiga['zanr_tokeni'], 4)) {
                $poZanru[] = $knjiga['id'];
            }
        }

        return collect([
            ['ids' => $poNazivu, 'razlog' => 'naziv'],
            ['ids' => $poAutoru, 'razlog' => 'autor'],
            ['ids' => $poZanru,  'razlog' => 'zanr'],
        ])
            ->flatMap(fn (array $grupa) => collect($grupa['ids'])->map(fn (int $id) => [
                'id'     => $id,
                'score'  => 1.0,
                'razlog' => $grupa['razlog'],
            ]))
            ->unique('id')
            ->take(self::MAX_POGODAKA)
            ->values();
    }

    /**
     * Knjige čiji NASLOV odgovara izolovanoj frazi (isto pravilo kao
     * pogadjaNaziv: pun naslov sadržan u frazi/fraza u naslovu, ili ≥60%
     * sadržajnih reči naslova pogođeno fuzzy poređenjem).
     *
     * @return Collection<int, int>  ID-jevi knjiga (najviše 5)
     */
    public function nadjiPoNaslovu(string $frazaNorm): Collection
    {
        $kljucne = array_values(array_filter(
            TextUtils::tokenizuj($frazaNorm),
            fn (string $t) => mb_strlen($t) >= 3
        ));

        if (empty($kljucne)) {
            return collect();
        }

        $ids = [];
        foreach ($this->recnik() as $knjiga) {
            if ($this->pogadjaNaziv($kljucne, $frazaNorm, $knjiga)) {
                $ids[] = $knjiga['id'];
            }
        }

        return collect(array_slice($ids, 0, self::MAX_POGODAKA));
    }

    /**
     * Knjige čiji normalizovan NASLOV POČINJE datom frazom (≥ 3 znaka) —
     * za nesigurno/delimično imenovanje („knjiga isl…"): korisnik zna samo
     * početak naslova, pa se postojanje proverava prefiksom pre nego što se
     * pošteno kaže da knjige nema.
     *
     * @return Collection<int, int>  ID-jevi knjiga (najviše 5)
     */
    public function nadjiPoPocetkuNaslova(string $frazaNorm): Collection
    {
        $fraza = trim($frazaNorm);

        if (mb_strlen($fraza) < 3) {
            return collect();
        }

        $ids = [];
        foreach ($this->recnik() as $knjiga) {
            if ($knjiga['naziv_norm'] !== '' && str_starts_with($knjiga['naziv_norm'], $fraza)) {
                $ids[] = $knjiga['id'];
            }
        }

        return collect(array_slice($ids, 0, self::MAX_POGODAKA));
    }

    /**
     * SVE knjige čiji normalizovan naslov počinje datim slovom — za
     * strukturni odgovor „koliko knjiga počinje na slovo X" (tačan broj i
     * kompletna lista iz kataloga, model se ne poziva).
     *
     * @return array<int, int>  SVI ID-jevi (odgovor nabraja kompletnu listu)
     */
    public function naslovNaSlovo(string $slovo): array
    {
        $slovo = TextUtils::normalize($slovo);

        if ($slovo === '') {
            return [];
        }

        $ids = [];
        foreach ($this->recnik() as $knjiga) {
            if ($knjiga['naziv_norm'] !== '' && str_starts_with($knjiga['naziv_norm'], $slovo)) {
                $ids[] = $knjiga['id'];
            }
        }

        return $ids;
    }

    /**
     * Knjige čiji naslov POČINJE ili se ZAVRŠAVA datom rečju (morfološki, uz
     * fuzzy) — za „knjiga koja se završava rečju ljudi" (kandidati_ucenje_3
     * #39 p18). $kraj = true → poslednja reč naslova; inače prva.
     *
     * @return array<int, int>  SVI pogođeni ID-jevi
     */
    public function naslovPoReci(string $rec, string $pozicija = 'pocetak'): array
    {
        $rec = TextUtils::normalize($rec);

        if ($rec === '') {
            return [];
        }

        $ids = [];
        foreach ($this->recnik() as $knjiga) {
            $tokeni = $knjiga['naziv_tokeni'];
            if (empty($tokeni)) {
                continue;
            }

            // „sadrzi" gleda SVE tokene naslova; „pocetak"/„kraj" samo granični.
            $kandidati = match ($pozicija) {
                'kraj'   => [end($tokeni)],
                'sadrzi' => $tokeni,
                default  => [$tokeni[0]],
            };

            foreach ($kandidati as $t) {
                if (TextUtils::istaRec($t, $rec) || TextUtils::fuzzyJednake($t, $rec)) {
                    $ids[] = $knjiga['id'];
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * SVE knjige čiji naslov sadrži CIFRU (arapski broj) — „Solunska 28",
     * „Pripovetke 2" (set17 p18). Model se ne poziva.
     *
     * @return array<int, int>  SVI ID-jevi
     */
    public function naslovSaBrojem(): array
    {
        $ids = [];
        foreach ($this->recnik() as $knjiga) {
            if (preg_match('/\d/', $knjiga['naziv_norm'])) {
                $ids[] = $knjiga['id'];
            }
        }

        return $ids;
    }

    /**
     * SVE knjige čiji naslov ima TAČNO N reči (tokena) — „knjige sa dve reči u
     * naslovu" (set17 p19). Podnaslov iza dvotačke/zareza broji svoje reči.
     *
     * @return array<int, int>  SVI ID-jevi
     */
    public function naslovPoBrojuReci(int $n): array
    {
        if ($n < 1) {
            return [];
        }

        $ids = [];
        foreach ($this->recnik() as $knjiga) {
            if (count($knjiga['naziv_tokeni']) === $n) {
                $ids[] = $knjiga['id'];
            }
        }

        return $ids;
    }

    /**
     * „DA LI STE MISLILI…" — najbliži POSTOJEĆI naslov za frazu koja nije
     * pogodila nijednu knjigu: prefiks celog naslova, mala Levenshtein
     * razlika celih stringova (tipfeleri), ili pokrivenost SVIH reči fraze
     * rečima naslova (fuzzy/prefiks). Vraća ID najboljeg kandidata ili null
     * — poziva se SAMO kada je regularna pretraga već promašila, pa lažni
     * pogodak ne može da istisne pravi (predlog je sročen kao pitanje).
     */
    public function predloziNaslov(string $frazaNorm): ?int
    {
        $fraza = trim($frazaNorm);

        if (mb_strlen($fraza) < 3) {
            return null;
        }

        $frazaTokeni = array_values(array_filter(
            TextUtils::tokenizuj($fraza),
            fn (string $t) => mb_strlen($t) >= 3
        ));

        $najboljiId   = null;
        $najboljiSkor = 0.0;

        foreach ($this->recnik() as $knjiga) {
            $naziv = $knjiga['naziv_norm'];

            if ($naziv === '') {
                continue;
            }

            $skor = 0.0;

            // 1) Fraza je početak naslova (ili naslov početak fraze).
            if (str_starts_with($naziv, $fraza) || str_starts_with($fraza, $naziv)) {
                $skor = 2.0 + min(strlen($fraza), strlen($naziv)) / max(strlen($fraza), strlen($naziv));
            }

            // 2) Tipfeler u celoj frazi: mala razlika prema celom naslovu.
            if ($skor === 0.0
                && abs(strlen($fraza) - strlen($naziv)) <= 3
                && levenshtein($fraza, $naziv) <= max(2, (int) floor(strlen($naziv) / 5))) {
                $skor = 1.5;
            }

            // 3) Sve reči fraze pogađaju reči naslova (fuzzy ili prefiks ≥ 3).
            if ($skor === 0.0 && !empty($frazaTokeni)) {
                $sve = true;
                foreach ($frazaTokeni as $ft) {
                    $pogodak = false;
                    foreach ($knjiga['naziv_tokeni'] as $nt) {
                        if (TextUtils::fuzzyJednake($ft, $nt)
                            || (mb_strlen($ft) >= 3 && str_starts_with($nt, $ft))) {
                            $pogodak = true;
                            break;
                        }
                    }
                    if (!$pogodak) {
                        $sve = false;
                        break;
                    }
                }
                if ($sve) {
                    $skor = 1.0 + count($frazaTokeni) / max(1, count($knjiga['naziv_tokeni']));
                }
            }

            if ($skor > $najboljiSkor) {
                $najboljiSkor = $skor;
                $najboljiId   = $knjiga['id'];
            }
        }

        return $najboljiId;
    }

    /**
     * Knjige čiji AUTOR odgovara frazi (fuzzy, reči ≥ 4 slova) — za slučaj
     * da je korisnik pod navodnike stavio autora, ne naslov.
     *
     * @return Collection<int, int>  ID-jevi knjiga (najviše 5)
     */
    public function nadjiPoAutoru(string $frazaNorm): Collection
    {
        $kljucne = array_values(array_filter(
            TextUtils::tokenizuj($frazaNorm),
            fn (string $t) => mb_strlen($t) >= 4
        ));

        if (empty($kljucne)) {
            return collect();
        }

        $ids = [];
        foreach ($this->recnik() as $knjiga) {
            if ($this->pogadjaTokene($kljucne, $knjiga['autor_tokeni'], 4)) {
                $ids[] = $knjiga['id'];
            }
        }

        return collect(array_slice($ids, 0, self::MAX_POGODAKA));
    }

    /**
     * STROGA pretraga autora po PUNOM imenu: SVAKA reč fraze (≥ 3 slova)
     * mora da pogodi tokene ISTOG autora — poređenje po imenu I prezimenu,
     * ne samo po jednoj reči.
     *
     * Ovim se rešava greška „ko je X": labava pretraga (nadjiPoAutoru) je
     * izmišljenu osobu proglašavala autorom čim joj se PREZIME poklopi sa
     * nekim autorom iz fonda. Ovde „Pera Detlić" ne pogađa „Marka Detlića" —
     * ako se ime ne poklapa, osoba NIJE autor i sledi generički odgovor.
     *
     * Padeži imena su dozvoljeni (imeTokenJednak): „Ive Andrića" ≈ „Ivo
     * Andrić", „Kiša" ≈ „Kiš".
     *
     * Vraća SVE knjige autora (bez seče na 5): fiksna autorska lista mora
     * da zna UKUPAN broj; pozivaoci koji pune prompt seku na rag_top_k.
     *
     * @return Collection<int, int>  ID-jevi knjiga tog autora
     */
    public function nadjiAutoraStrogo(string $frazaNorm): Collection
    {
        $imena = array_values(array_filter(
            TextUtils::tokenizuj($frazaNorm),
            fn (string $t) => mb_strlen($t) >= 3 && !in_array($t, IntentClassifier::STOP_RECI, true)
        ));

        if (empty($imena)) {
            return collect();
        }

        $ids = [];
        foreach ($this->recnik() as $knjiga) {
            if (empty($knjiga['autor_tokeni'])) {
                continue;
            }

            $sveReci = true;
            foreach ($imena as $ime) {
                $pogodjeno = false;
                foreach ($knjiga['autor_tokeni'] as $token) {
                    if ($this->imeTokenJednak($ime, $token)) {
                        $pogodjeno = true;
                        break;
                    }
                }
                if (!$pogodjeno) {
                    $sveReci = false;
                    break;
                }
            }

            if ($sveReci) {
                $ids[] = $knjiga['id'];
            }
        }

        return collect($ids);
    }

    /**
     * Jednakost tokena LIČNOG IMENA sa tolerancijom na padež — fuzzy pravila
     * za opšte reči su prestroga za kratka imena („Ive" ≠ „Ivo" po fuzzy):
     *  - fuzzy jednakost (tipfeleri, duži padeži: „Andrića" ≈ „Andrić"),
     *  - ista dužina ≥ 3, razlika samo u POSLEDNJEM slovu („Ive" ≈ „Ivo"),
     *  - „nepostojano a" u genitivu — zamena dva SUSEDNA slova iste dužine
     *    („Sremca" ≈ „Sremac", „Kovača" ≈ „Kovac"? ne): imena na „-ac" u
     *    nominativu daju „-ca" u genitivu,
     *  - kraće ime je početak dužeg uz višak ≤ 2 slova („Kiš" ≈ „Kiša",
     *    „Kišom") — nastavci padeža na kratkim prezimenima.
     */
    private function imeTokenJednak(string $a, string $b): bool
    {
        if (TextUtils::fuzzyJednake($a, $b)) {
            return true;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA === $lenB && $lenA >= 3 && substr($a, 0, -1) === substr($b, 0, -1)) {
            return true;
        }

        // Nepostojano a: „Sremac" ↔ „Sremca" — iste dužine, razlikuju se samo
        // u zameni dva susedna slova na istim pozicijama.
        if ($lenA === $lenB && $lenA >= 5) {
            $razlike = [];
            for ($i = 0; $i < $lenA; $i++) {
                if ($a[$i] !== $b[$i]) {
                    $razlike[] = $i;
                }
            }
            if (count($razlike) === 2 && $razlike[1] === $razlike[0] + 1
                && $a[$razlike[0]] === $b[$razlike[1]]
                && $a[$razlike[1]] === $b[$razlike[0]]) {
                return true;
            }
        }

        [$kraci, $duzi] = $lenA <= $lenB ? [$a, $b] : [$b, $a];

        return strlen($kraci) >= 3
            && strlen($duzi) - strlen($kraci) <= 2
            && str_starts_with($duzi, $kraci);
    }

    /**
     * Da li je fraza zapravo ŽANR (postojeći u fondu ili poznata žanrovska
     * reč) — tada pitanje nije o konkretnoj knjizi i rešava ga žanrovski tok.
     */
    public function jeZanrovskaFraza(string $frazaNorm): bool
    {
        foreach (TextUtils::tokenizuj($frazaNorm) as $token) {
            foreach (IntentClassifier::ZANROVSKE_RECI as $zanr) {
                if (TextUtils::fuzzyJednake($token, $zanr)) {
                    return true;
                }
            }
            foreach ($this->sviZanrTokeni() as $zanrToken) {
                if (TextUtils::fuzzyJednake($token, $zanrToken)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Da li upit sadrži bar jednu poznatu reč (stop reč, konverzacionu reč
     * ili reč iz kataloga — naziv/autor/žanr)? Ako NE — namera se ne može
     * prepoznati i vraća se kontra-upit (bib-pravila.md, tačka 3).
     */
    public function imaPoznatihReci(string $norm): bool
    {
        $tokeni = TextUtils::tokenizuj($norm);

        if (empty($tokeni)) {
            return false;
        }

        foreach ($tokeni as $token) {
            if (in_array($token, IntentClassifier::STOP_RECI, true)
                || in_array($token, IntentClassifier::KONVERZACIONE_RECI, true)) {
                return true;
            }
        }

        // Reči iz kataloga (uključuje fuzzy — tipfeler u naslovu je i dalje namera).
        $kljucne = array_values(array_filter($tokeni, fn (string $t) => mb_strlen($t) >= 3));
        foreach ($this->recnik() as $knjiga) {
            $sviTokeni = array_merge($knjiga['naziv_tokeni'], $knjiga['autor_tokeni'], $knjiga['zanr_tokeni']);
            if ($this->pogadjaTokene($kljucne, $sviTokeni, 4)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Broj knjiga čiji naslov sadrži datu reč (za pitanje „koliko knjiga
     * sadrži reč X") — poređenje PO REČIMA naslova, morfološki (Nalaz 3):
     * padeži iste reči ulaze u broj („priče" pogađa i „Beskrajna priča" i
     * „Priče iz davnina"), a substring lažni pogoci ne („princip" nije
     * „priče", „vrata" nije „rat"). Vidi TextUtils::istaRec.
     *
     * Vraća SVE pogođene ID-jeve: odgovor nabraja kompletnu listu (bib deo
     * 1A — tačan broj i sve stavke), skraćivanje uz napomenu radi ispis.
     *
     * @return array{rec: string, broj: int, primeri: array<int, int>}  primeri = SVI ID-jevi
     */
    public function brojKnjigaSaRecju(string $rec): array
    {
        $rec = TextUtils::normalize($rec);
        $ids = [];

        foreach ($this->recnik() as $knjiga) {
            foreach ($knjiga['naziv_tokeni'] as $token) {
                if ($rec !== '' && TextUtils::istaRec($token, $rec)) {
                    $ids[] = $knjiga['id'];
                    break;
                }
            }
        }

        return ['rec' => $rec, 'broj' => count($ids), 'primeri' => $ids];
    }

    /**
     * Svi tokeni naziva žanrova u fondu — koristi ih i IntentClassifier
     * (prepoznavanje žanrovske fraze) i fuzzy izuzimanje kod autora.
     *
     * @return array<int, string>
     */
    public function sviZanrTokeni(): array
    {
        $tokeni = [];
        foreach ($this->recnik() as $knjiga) {
            foreach ($knjiga['zanr_tokeni'] as $t) {
                $tokeni[$t] = true;
            }
        }

        return array_keys($tokeni);
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @var array<int, array{id:int, naziv_norm:string, naziv_tokeni:array, autor_tokeni:array, zanr_tokeni:array}>|null */
    private ?array $recnik = null;

    /**
     * Normalizovan rečnik kataloga — jedan upit po HTTP zahtevu
     * (LibraryDataService), tokenizacija ovde.
     */
    private function recnik(): array
    {
        if ($this->recnik !== null) {
            return $this->recnik;
        }

        $this->recnik = $this->data->sveKnjigeSaZanrovima()
            ->map(function ($k) {
                $nazivNorm = TextUtils::normalize($k->naziv_knjige ?? '');

                return [
                    'id'           => $k->id,
                    'naziv_norm'   => $nazivNorm,
                    'naziv_tokeni' => TextUtils::tokenizuj($nazivNorm),
                    'autor_tokeni' => TextUtils::tokenizuj(TextUtils::normalize($k->autor ?? '')),
                    'zanr_tokeni'  => TextUtils::tokenizuj(TextUtils::normalize($k->zanrovi->pluck('naziv')->implode(' '))),
                ];
            })
            ->all();

        return $this->recnik;
    }

    /**
     * Pogodak naslova, tri pravila:
     *  1. pun normalizovan naslov sadržan u upitu,
     *  2. pogođeno bar 60% sadržajnih reči naslova (fuzzy po reči),
     *  3. DELIMIČAN NAZIV (obrnut smer): SVE ključne reči upita pogađaju
     *     reči naslova — „moja baka" pogađa „Moja baka vam se izvinjava"
     *     iako je to manje od 60% reči naslova. Zaštita od slučajnih
     *     pogodaka: bar jedna pogođena reč mora imati ≥ 4 slova.
     */
    private function pogadjaNaziv(array $kljucne, string $norm, array $knjiga): bool
    {
        if ($knjiga['naziv_norm'] !== '' && mb_strlen($knjiga['naziv_norm']) >= 5
            && str_contains($norm, $knjiga['naziv_norm'])) {
            return true;
        }

        $sadrzajne = array_values(array_filter(
            $knjiga['naziv_tokeni'],
            fn (string $t) => mb_strlen($t) >= 3 && !in_array($t, IntentClassifier::STOP_RECI, true)
        ));

        if (empty($sadrzajne)) {
            return false;
        }

        $pogodjeno = 0;
        foreach ($sadrzajne as $tokenNaslova) {
            foreach ($kljucne as $kljucna) {
                if (TextUtils::fuzzyJednake($kljucna, $tokenNaslova)) {
                    $pogodjeno++;
                    break;
                }
            }
        }

        // Jednorečni naslov: reč mora biti duža (≥ 4) da kratka reč ne pogodi slučajno.
        if (count($sadrzajne) === 1) {
            return $pogodjeno === 1 && mb_strlen($sadrzajne[0]) >= 4;
        }

        if ($pogodjeno / count($sadrzajne) >= 0.6) {
            return true;
        }

        // 3) Obrnut smer — upit je (deo) naslova: sve ključne reči upita
        //    moraju da pogode reči naslova (poredi se i sa punim tokenima,
        //    ne samo sadržajnim — „moja" je stop reč upita, ali reč naslova).
        if ($pogodjeno === 0 || empty($kljucne)) {
            return false;
        }

        $imaDuzu = false;
        foreach ($kljucne as $kljucna) {
            $pogodak = false;
            foreach ($knjiga['naziv_tokeni'] as $tokenNaslova) {
                if (TextUtils::fuzzyJednake($kljucna, $tokenNaslova)) {
                    $pogodak = true;
                    $imaDuzu = $imaDuzu || mb_strlen($kljucna) >= 4;
                    break;
                }
            }
            if (!$pogodak) {
                return false;
            }
        }

        return $imaDuzu;
    }

    /**
     * Da li ijedna ključna reč (dužine ≥ $minDuzina) pogađa ijedan token liste.
     */
    private function pogadjaTokene(array $kljucne, array $tokeni, int $minDuzina): bool
    {
        foreach ($kljucne as $kljucna) {
            if (mb_strlen($kljucna) < $minDuzina) {
                continue;
            }
            foreach ($tokeni as $token) {
                if (TextUtils::fuzzyJednake($kljucna, $token)) {
                    return true;
                }
            }
        }

        return false;
    }
}
