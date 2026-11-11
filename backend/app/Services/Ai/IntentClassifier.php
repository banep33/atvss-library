<?php

namespace App\Services\Ai;

/**
 * IntentClassifier — SLOJ 1 pipeline-a: određuje NAMERU pitanja i izvlači
 * parametre (reč za brojanje, žanrovsku frazu, datumski opseg, imenovanu
 * knjigu, osobu), PRE bilo kakve pretrage i bez ijednog upita ka bazi
 * (jedini izuzetak: rečnik žanrovskih tokena iz keširane KatalogPretrage).
 *
 * Ovde je KONSOLIDOVANA klasifikacija koja je ranije bila razbacana po
 * KatalogPretraga (detektujIntencije, jeTematskoPitanje, jeZanrovskiUpit,
 * jeAkcioniZahtev, zanrListaFraza, detektujCountRec, izvuciKoJe,
 * jeKonverzacioni, izvuciImenovanuKnjigu), StrukturniUpiti
 * (parsirajDatumskiOpseg) i inline regexima u AiChatService („ko je
 * zadužio") — logika je PRESELJENA, ne prepisana; ponašanje je isto.
 *
 * KONVERZACIJSKI KONTEKST: klasifikacija prima i istoriju razgovora, pa
 * očigledni nastavci ne gube kontekst prethodnog pitanja:
 *  - „a za prošlu nedelju?" posle datumskog pitanja nasleđuje arhivski
 *    kontekst (novi opseg, ista namera),
 *  - „recite mi ostatak / izlistaj i ostale" posle skraćenog spiska ponovo
 *    izvršava PRETHODNU nameru sa pomerajem (ostatakOd),
 *  - kratka anaforična poruka („a taj autor?") dobija nastavak=true +
 *    kontekstNorm, pa je router ne odbija kao van teme već pušta modelu
 *    (uz kombinovanu leksičku pretragu nad prethodnom razmenom).
 *
 * Klasifikacija radi nad NORMALIZOVANIM tekstom (TextUtils::normalize);
 * sirovi tekst je potreban samo za imenovanu knjigu (velika slova).
 */
class IntentClassifier
{
    /** Najviše koliko dana unazad sme datumska pretraga arhive (1 mesec). */
    public const MAX_DANA = 31;

    /** Brojevi rečima kako ih korisnici kucaju (normalizovan oblik). */
    private const BROJ_RECI = [
        'jedan' => 1, 'jednu' => 1, 'jedne' => 1, 'jednog' => 1,
        'dva' => 2, 'dve' => 2, 'tri' => 3, 'cetiri' => 4, 'pet' => 5,
        'sest' => 6, 'sedam' => 7, 'osam' => 8, 'devet' => 9, 'deset' => 10,
        'petnaest' => 15, 'dvadeset' => 20, 'trideset' => 30,
    ];

    /**
     * Reči upitnog šablona koje se NE računaju kao ključne reči pretrage
     * (normalizovan oblik — mala slova, bez dijakritika).
     */
    public const STOP_RECI = [
        // upitne i funkcijske reči
        'da', 'li', 'ne', 'i', 'a', 'o', 'u', 'na', 'za', 'od', 'do', 'sa',
        'se', 'je', 'su', 'sam', 'si', 'smo', 'ste', 'bi', 'bih', 'biste',
        'ce', 'cu', 'ces', 'cemo', 'im', 'ih', 'mi', 'me', 'te', 'ti', 'vi',
        'vas', 'vam', 'nam', 'nas', 'moj', 'moja', 'moje', 'vasa', 'vase',
        'vasem', 'vasoj', 'kod', 'kao', 'ali', 'ili', 'pa', 'jer', 'sto',
        'sta', 'gde', 'kad', 'kada', 'kako', 'koliko', 'zasto', 'koji',
        'koja', 'koje', 'koju', 'kojeg', 'kojoj', 'kojim', 'neki', 'neka',
        'neko', 'neku', 'nesto', 'nekog', 'bilo', 'ovde', 'tamo', 'ovo',
        'to', 'ta', 'taj', 'tu', 'ono', 'ona', 'on',
        // glagoli upitnog šablona
        'imate', 'ima', 'imas', 'imamo', 'postoji', 'postoje', 'mogu',
        'moze', 'mozete', 'mozes', 'zelim', 'zelite', 'hocu', 'hteo',
        'htela', 'trazim', 'trebam', 'treba', 'nadjem', 'nadem', 'vidim',
        'preporuci', 'preporucite', 'preporucis', 'predlozi', 'predlozite',
        'zanima', 'interesuje', 'molim', 'daj', 'dajte', 'recite', 'reci',
        // opšte imenice bez informacione vrednosti za pretragu
        'knjiga', 'knjige', 'knjigu', 'knjizi', 'knjigama', 'naslov',
        'naslova', 'naslovu', 'primerak', 'primerka', 'primeraka',
        'biblioteka', 'biblioteci', 'biblioteke', 'katalog', 'katalogu',
        'kataloga', 'fond', 'fondu', 'citanje', 'citanja', 'citam',
        'procitam', 'citao', 'citala', 'autor', 'autora', 'autorka',
        'pisac', 'pisca', 'zanr', 'zanra', 'zanru', 'zanrovi', 'zanrove',
        'zanrova', 'zanrovima', 'tema',
        'temu', 'teme', 'oblast', 'oblasti', 'rec',
        // reči opštih pitanja o biblioteci (FAQ) — nisu pojmovi za pretragu
        // kataloga („kada RADI biblioteka" ne sme da traži knjigu po „radi")
        'radi', 'radite', 'radno', 'vreme', 'vremena', 'otvorena', 'otvoreno',
        'zatvorena', 'zatvoreno', 'danas', 'sutra', 'vikend', 'vikendom',
        'sati', 'casova', 'kontakt', 'telefon', 'mejl', 'adresa', 'adresi',
        'lozinka', 'lozinku', 'profil', 'profilu', 'nalog', 'nalogu',
        'notifikacije', 'notifikacija', 'obavestenje', 'obavestenja',
        'rok', 'roka', 'roku', 'kazna', 'kazne', 'clan', 'clanstvo',
        'clanarina', 'kosta', 'besplatno', 'placam', 'rezervacija',
        'rezervacije', 'rezervaciju', 'rezervisem', 'rezervises',
        'rezervisati', 'zaduzim', 'zaduzenje', 'zaduzenja', 'zaduzene',
        'vratim', 'vracanje', 'preuzmem', 'preuzimanje', 'produzim',
        'produzenje', 'istice', 'istekla', 'uclanim', 'uclanjenje',
        'student', 'studenti', 'bibliotekar', 'bibliotekarka',
    ];

    /**
     * Poznate konverzacione reči VAN stop liste — koriste se za
     * jeKonverzacioni i KatalogPretraga::imaPoznatihReci (da „hvala puno"
     * ili pitanje o rezervaciji ne dobiju kontra-upit).
     */
    public const KONVERZACIONE_RECI = [
        'hvala', 'zdravo', 'cao', 'dobar', 'dobro', 'dan', 'jutro', 'vece',
        'puno', 'mnogo', 'super', 'vazi', 'okej', 'ok',
        'dovidjenja', 'pozdrav', 'izvini', 'izvinite', 'oprostite',
        'rezervisao', 'rezervisala', 'zaduzena', 'podatke',
        'roman', 'romana', 'romani', 'pesme', 'poezija', 'prica', 'price',
        'lektira', 'lektiru', 'najnovije', 'najnovija', 'najcitanije',
        'najcitanija', 'dostupna', 'dostupno', 'dostupne', 'slobodan',
        'slobodna', 'slobodnih', 'otkazem', 'otkazati', 'kasnim', 'zakasnim',
    ];

    /**
     * Reči kojima korisnici imenuju ŽANROVE (uključujući i žanrove kojih u
     * fondu nema — „horor") — služe SAMO za prepoznavanje da je upit
     * žanrovski. Srodni žanr se ne bira iz ove liste, već preko embedding
     * sličnosti (AiChatService::srodniZanr tok).
     */
    public const ZANROVSKE_RECI = [
        'roman', 'romani', 'romana', 'poezija', 'poezije', 'pesme', 'pesama',
        'drama', 'drame', 'komedija', 'komedije', 'tragedija', 'satira',
        'triler', 'trileri', 'horor', 'horora', 'krimi', 'kriminalistika',
        'krimic', 'krimici', 'misterija', 'misterije', 'fantastika',
        'fantazija', 'fantasy', 'romansa', 'ljubavni', 'ljubici',
        'istorijski', 'istorija', 'istorije', 'biografija', 'biografije',
        'autobiografija', 'memoari', 'putopis', 'putopisi', 'eseji',
        'pripovetke', 'proza', 'bajke', 'basne', 'epika', 'lirika',
        'klasici', 'klasika', 'avantura', 'avanture', 'avanturisticki',
        'psihologija', 'filozofija', 'publicistika', 'beletristika',
        'naucna', 'distopija', 'vestern', 'kolumne', 'prepiska', 'umetnost',
    ];

    /**
     * Reči kojima korisnik traži SPISAK/nabrajanje (normalizovan oblik) —
     * signal za kompletnu listu žanra (zanrListaFraza).
     */
    private const LISTA_RECI = [
        'izlistaj', 'izlistas', 'izlistate', 'nabroj', 'nabrojte', 'nabrojis',
        'prikazi', 'prikazite', 'ispisi', 'ispisite', 'izbaci', 'navedi',
        'navedite', 'spisak', 'lista', 'listu', 'popis', 'pregled',
    ];

    public function __construct(
        private readonly KatalogPretraga $katalog,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GLAVNA KLASIFIKACIJA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Klasifikuje pitanje u tipiziranu nameru sa parametrima.
     *
     * @param  string  $norm     normalizovan tekst pitanja (TextUtils::normalize)
     * @param  string  $raw      sirovi tekst (za imenovanu knjigu — velika slova)
     * @param  array<int, array{role: string, content: string}>  $history
     *         prethodne poruke razgovora, hronološki (za razrešavanje nastavaka)
     */
    public function klasifikuj(string $norm, string $raw, array $history = []): Namera
    {
        [$prethodnoPitanje, $prethodniOdgovor] = $this->poslednjaRazmena($history);
        $prethodnaNorm = $prethodnoPitanje !== null ? TextUtils::normalize($prethodnoPitanje) : null;

        // OSTATAK SPISKA — pre svega ostalog: „recite mi ostatak" nema
        // sopstvenu nameru, već ponavlja PRETHODNU sa pomerajem.
        $ostatakOd = null;
        if ($prethodnaNorm !== null
            && $this->trazenOstatak($norm, $prethodniOdgovor)) {
            $kandidat = $this->klasifikuj($prethodnaNorm, $prethodnoPitanje, []);
            if ($kandidat->autorFraza !== null
                || $kandidat->izdavacFraza !== null
                || in_array($kandidat->tip, [
                    TipNamere::DatumskiOpseg, TipNamere::ZanrLista, TipNamere::BrojanjeReci,
                    TipNamere::NikadZaduzene, TipNamere::PocinjeNaSlovo, TipNamere::GodinaIzdanja,
                    TipNamere::IzdavacStatistika,
                ], true)) {
                $ostatakOd = $kandidat;
            }
        }

        $tematsko    = $this->jeTematskoPitanje($norm);
        $godinaOpseg = $this->godinaIzdanjaOpseg($norm);

        // GODINA IZDANJA ima PREDNOST nad arhivskim opsegom: „izdate prošle
        // godine" / „izdate pre 2000 godine" su pitanja o godini IZDANJA —
        // bez ovog reda bi ih parsirajDatumskiOpseg (arhivski kontekst preko
        // reči „izdat…") proglasio opsegom unazad preko limita. Izričito
        // „zaduž…" godinaIzdanjaOpseg ionako odbija, pa arhiva ostaje arhiva.
        $datumski = $godinaOpseg === null ? $this->parsirajDatumskiOpseg($norm) : null;

        // NASLEĐIVANJE DATUMSKOG KONTEKSTA: „a za prošlu nedelju?" posle
        // datumskog pitanja — tekuća poruka ima datumski izraz ali ne i
        // arhivski kontekst (reč „zaduž…"), pa se kontekst nasleđuje.
        if ($datumski === null
            && $godinaOpseg === null
            && $prethodnaNorm !== null
            && $this->parsirajDatumskiOpseg($prethodnaNorm) !== null) {
            $datumski = $this->parsirajDatumskiOpseg($norm, zahtevajKontekst: false);
        }

        $akcioni      = $this->jeAkcioniZahtev($norm);
        $statistika   = $this->jeStatistika($norm);
        $zanrFraza    = $this->zanrListaFraza($norm);
        $countRec     = $this->detektujCountRec($norm);
        $pocinjeSlovo = $this->pocinjeNaSlovo($norm);

        // IZDAVAČ: imenovan izdavač ima prednost nad statistikom o izdavačima
        // — statistička pitanja ne imenuju izdavača (fraza im počinje upitnom
        // rečju i odbija se), pa se statistika proverava tek bez fraze.
        $izdavacFraza = $this->izvuciIzdavacFrazu($raw);
        $izdavacStat  = $izdavacFraza === null ? $this->izdavacStatistika($norm) : null;
        $koJeZaduzio  = (bool) preg_match('/\bko\s+je\b.{0,20}\b(zaduzi|uze|pozajmi|preuze)/u', $norm);
        $koJe         = $this->izvuciKoJe($norm);
        $nastavak     = $prethodnaNorm !== null && $this->jeNastavak($norm);

        $nikadZaduzene    = $this->jeNikadZaduzene($norm);
        $odnosRezervacija = $this->jeOdnosRezervacija($norm);
        $prosekZaduzenja  = $this->jeProsekZaduzenja($norm);

        // STATISTIKA/RELACIJE nad metapodacima (kandidati_ucenje_2): autori po
        // broju naslova, godina sa najviše knjiga, najstarija/najnovija, mesto
        // izdavanja, poređenje dve imenovane knjige i „iste osobine kao X".
        $imenovane   = $this->izvuciSveImenovaneKnjige($raw);
        $autorStat   = $izdavacStat === null ? $this->autorStatistika($norm) : null;
        $godinaNajv  = $this->jeGodinaNajvise($norm);
        $ekstrem     = $this->ekstremKnjiga($norm);
        $mestoFraza  = $this->izvuciMestoFrazu($raw);
        $poredjenje  = count($imenovane) >= 2 ? $this->poredjenjeKriterijum($norm) : null;
        $relacioni   = ($poredjenje === null && count($imenovane) >= 1)
            ? $this->relacioniKriterijum($norm)
            : null;
        $josKnjiga   = $this->jeJosKnjiga($norm);
        // SADRŽAJNO se procenjuje nad tekstom BEZ imenovanih (navedenih pod
        // navodnicima) naslova — reč iz naslova („…o prijateljstvu i izdaji")
        // ne sme da bude shvaćena kao pitanje o radnji: „pročitao sam ‘X’,
        // imate li još knjigu autora Y" je zahtev za drugim delima, ne
        // sadržajno pitanje (kandidati_ucenje_4, set8 p4).
        $sadrzajno   = $this->jeSadrzajnoPitanje($this->bezImenovanihNaslova($raw));
        $nastavak2   = $this->jeNastavakUpit($norm);
        $recNaslova  = $this->izvuciRecNaslova($norm);
        $brojNaslov  = $this->jeBrojUNaslovu($norm);
        $reciNaslov  = $recNaslova === null ? $this->brojReciNaslova($norm) : null;
        $prezimeSlov = $this->prezimeNaSlovo($norm);
        $dveIsteGod  = $this->jeDveIsteGodine($norm);

        $tip = match (true) {
            $akcioni                          => TipNamere::Akcija,
            $datumski !== null                => TipNamere::DatumskiOpseg,
            $nikadZaduzene                    => TipNamere::NikadZaduzene,
            $odnosRezervacija                 => TipNamere::OdnosRezervacija,
            $prosekZaduzenja                  => TipNamere::ProsekZaduzenja,
            $izdavacFraza !== null            => TipNamere::IzdavacLista,
            $izdavacStat !== null             => TipNamere::IzdavacStatistika,
            $statistika                       => TipNamere::Statistika,
            $pocinjeSlovo !== null            => TipNamere::PocinjeNaSlovo,
            $godinaOpseg !== null             => TipNamere::GodinaIzdanja,
            !$tematsko && $zanrFraza !== null => TipNamere::ZanrLista,
            $countRec !== null                => TipNamere::BrojanjeReci,
            $koJeZaduzio                      => TipNamere::KoJeZaduzio,
            $koJe !== null                    => TipNamere::KoJeOsoba,
            default                           => TipNamere::Katalog,
        };

        return new Namera(
            tip:             $tip,
            datumskiOpseg:   $datumski,
            countRec:        $countRec,
            zanrListaFraza:  $zanrFraza,
            pocinjeSlovo:    $pocinjeSlovo,
            godinaOpseg:     $godinaOpseg,
            koJe:            $koJe,
            imenovanaKnjiga: $this->izvuciImenovanuKnjigu($raw),
            autorFraza:      $this->izvuciAutorFrazu($raw),
            autorKandidat:   $this->izvuciAutorKandidat($raw),
            izdavacFraza:    $izdavacFraza,
            izdavacStatistika: $izdavacStat,
            autorStatistika: $autorStat,
            godinaNajvise:   $godinaNajv,
            ekstremKnjiga:   $ekstrem,
            mestoFraza:      $mestoFraza,
            imenovaneKnjige: $imenovane,
            relacioniKriterijum:  $relacioni,
            poredjenjeKriterijum: $poredjenje,
            josKnjiga:       $josKnjiga,
            sadrzajnoPitanje: $sadrzajno,
            nastavakUpit:    $nastavak2,
            recNaslova:      $recNaslova,
            brojUNaslovu:    $brojNaslov,
            reciUNaslovu:    $reciNaslov,
            prezimeSlovo:    $prezimeSlov,
            dveIsteGodine:   $dveIsteGod,
            akcioniZahtev:   $akcioni,
            mojeRezervacijeUpit: $this->jeMojeRezervacijeUpit($norm),
            listaCekanja:    $this->jeListaCekanja($norm),
            dostupnostUpit:  $this->jeDostupnostUpit($norm),
            koJeZaduzio:     $koJeZaduzio,
            statistika:      $statistika,
            nikadZaduzene:    $nikadZaduzene,
            poredjenjeZanrova: $this->jePoredjenjePopularnosti($norm),
            odnosRezervacija: $odnosRezervacija,
            prosekZaduzenja:  $prosekZaduzenja,
            tematsko:        $tematsko,
            strucnaLiteratura: $this->jeStrucnaLiteratura($norm),
            // „najnovije" + parafraze: „poslednje/nedavno dodato", „najskorije
            //  dodate", „šta je novo u biblioteci" (Nalaz #45 — pre ovoga je
            //  „šta je poslednje dodato" išlo modelu i vraćalo nasumičnu knjigu).
            najnovije:       (bool) preg_match(
                '/\bnajnovij|\bnajskorij|\bposlednj\w*\s+dodat|\bnedavno\s+dodat'
                . '|\bskoro\s+dodat|\bnov[oe]\s+u\s+(biblioteci|fondu|katalog\w*|ponudi)/u',
                $norm
            ),
            najcitanije:     $this->jeNajcitanije($norm),
            // Pitanje o brojkama kataloga („koliko knjiga/žanrova imate") ili
            // spisku žanrova — odgovor daju agregati u promptu, ne spisak
            // knjiga. Tematsko pitanje NIJE agregatno („koliko knjiga imate
            // na temu mora" traži knjige o moru, ne ukupan broj).
            agregatno:       !$tematsko && (bool) preg_match(
                '/koliko\s+\w*\s*(knjig|primer|naslov|del|zanr)|(koje|koji|kojih)\s+(sve\s+)?zanr/u',
                $norm
            ),
            katalosko:       $this->jeKatalosko($norm),
            // Pitanje o pravilima/uslugama biblioteke (FAQ) — na njega se NE
            // nude preporuke knjiga, odgovor daju PODACI O BIBLIOTECI i
            // zvanični P/O primeri (FaqZnanje) u promptu.
            faq:             (bool) preg_match(
                '/rezervis|rezervacij|zaduz|clanstv|clanarin|uclan|lozink|notifikacij|obavesten'
                . '|radno|radi\b|radite|vreme|kontakt|telefon|mejl|adres|bibliotekar'
                . '|preuzm|vratim|vracanj|produz|rok\b|roka|kazn|zakasn|profil|nalog|kosta|besplat'
                . '|silvan|bogicevic'
                // radno vreme („do kad ste otvoreni"), učlanjenje („da se upišem"),
                // ko vodi biblioteku (= bibliotekar) — FAQ formulacije koje gornji
                // rečnik ne pokriva, a padale su u „van teme".
                . '|otvor|upis|vodi\s+bibliotek'
                // plaćanje/članarina: „da li plaćam", „moram li da platim" — FAQ
                // (članarina je besplatna), ne „van teme".
                . '|plac|plati'
                // rok pozajmice: „pozajmica/pozajmljena", „zadržim knjigu"
                . '|pozajm|zadrz'
                // eval za_kod.md: studentska pitanja o skriptama/udžbenicima/kupovini/
                // e-knjigama su FAQ (odgovor daju few-shot parovi), ne "van teme".
                . '|skript|udzbenik|kupovin|kupim|kupit|prodaj|eknjig|elektronsk/u',
                $norm
            )
            // Trajanje pozajmice: „koliko dugo…", „na koliko (najviše) dana…" —
            // FAQ o roku (30 dana), ne kataloška pretraga. Bez ovoga reč
            // „knjigu" gura pitanje u kataloške preporuke (homograf „kući"→autor
            // je zaseban fix u KatalogPretraga::NE_AUTORSKE_RECI).
            || (bool) preg_match('/\bkoli?ko\b.{0,12}\b(dana|dugo)\b/u', $norm),
            konverzacioni:   $this->jeKonverzacioni($norm),
            zanrovskiUpit:   $this->jeZanrovskiUpit($norm),
            slicnoUpit:      $this->jeSlicnoUpit($norm),
            vanKataloga:     $this->jeVanKataloga($norm),
            dubljaAnalitika: $this->jeDubljaAnalitika($norm),
            autorViseZanrova: $this->jeAutorViseZanrova($norm),
            autorStatPodela: $this->autorStatPodela($norm),
            identitetUpit:   $this->jeIdentitet($norm),
            kontaktUpit:     $this->jeKontakt($norm),
            faqFiksnaTema:   $this->faqFiksnaTema($norm),
            podelaPiscaIme:  $this->podelaPisca($raw),
            straniPisciUpit: $this->jeStraniPisci($norm),
            autorKnjigeUpit: $this->jeAutorKnjigeUpit($norm),
            ikadZaduzenaUpit: $this->jeIkadZaduzena($norm),
            najmanjePozajmljivana: $this->jeNajmanjePozajmljivana($norm),
            nepozajmljenaPreporuka: $this->jeNepozajmljenaPreporuka($norm),
            nastavak:        $nastavak,
            kontekstNorm:    $nastavak ? $this->kontekstIzIstorije($history) : null,
            ostatakOd:       $ostatakOd,
            izvornaNorm:     $norm,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POJEDINAČNE DETEKCIJE (preseljene iz KatalogPretraga / StrukturniUpiti)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Da li je upit TEMATSKI („na temu X", „o temi X") — korisnik pita o
     * radnji/sadržaju knjige, pa žanrovska leksika i agregati ne važe.
     */
    public function jeTematskoPitanje(string $norm): bool
    {
        return (bool) preg_match('/\btem(a|u|e|i|om|ama)\b/u', $norm);
    }

    /**
     * Da li je upit ŽANROVSKI: pominje reč „žanr" ili neku od poznatih
     * žanrovskih reči (egzaktno ili fuzzy — „naucne fantastike" i sl.).
     */
    public function jeZanrovskiUpit(string $norm): bool
    {
        if (preg_match('/zanr/u', $norm)) {
            return true;
        }

        foreach (TextUtils::tokenizuj($norm) as $token) {
            if ($this->jeZanrovskaRec($token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Da li je token žanrovska reč — poznata žanrovska reč (i žanrovi kojih
     * u fondu nema, „horor") ili token naziva POSTOJEĆEG žanra iz fonda,
     * fuzzy poređenjem („pripovetki" ≈ „pripovetke").
     */
    private function jeZanrovskaRec(string $token): bool
    {
        foreach (self::ZANROVSKE_RECI as $zanr) {
            if (TextUtils::fuzzyJednake($token, $zanr)) {
                return true;
            }
        }

        foreach ($this->katalog->sviZanrTokeni() as $zanrToken) {
            if (TextUtils::fuzzyJednake($token, $zanrToken)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Da li korisnik traži knjigu SLIČNU nekoj drugoj („nešto slično knjizi
     * X", „poput X", „u stilu X") — kada referentna knjiga nije pogođena u
     * katalogu, router vraća POŠTEN fiksni odgovor umesto preporuka: sličnost
     * sa knjigom koju ne poznajemo ne može se proceniti, pa bi semantičke
     * preporuke bile nasumične (ručno testiranje 2026-07-10).
     */
    public function jeSlicnoUpit(string $norm): bool
    {
        return (bool) preg_match('/\bslicn\w*\b|\bpoput\b|\bnalik\b|\bu\s+stilu\b|\bu\s+fazonu\b/u', $norm);
    }

    /**
     * Pitanje o IDENTITETU bota (Faza 2) — „ko si ti", „šta si ti", „kako se
     * zoveš", „predstavi se", „da li si robot/AI/čovek". Router vraća FIKSNI
     * identitet (Bib), model se ne poziva — da bot nikad ne kaže da je program/
     * model/AI niti da se predstavi kao neko drugi.
     */
    /**
     * „Ko je autor knjige X / ko je napisao X" (kandidati_ucenje_6, set3
     * p15/p18) — traži se IME AUTORA imenovane knjige, NE ostale knjige tog
     * autora. Router razrešava knjigu i vraća autora iz baze. Razlikuje se od
     * relacionog „još knjiga istog autora kao X" (koje ne počinje „ko je autor").
     */
    public function jeAutorKnjigeUpit(string $norm): bool
    {
        return (bool) preg_match('/\bko\s+je\s+(napisao|napisala|autor|pisac|spisatelj)\w*/u', $norm)
            && !(bool) preg_match('/\bjos\b|\bostal\w*|\bdrug[aeiou]\s+knjig|\bkoje\s+(sve\s+)?knjig/u', $norm);
    }

    /**
     * „Da li je X domaći ili strani pisac" (Faza 4) — vraća IME pisca (za
     * prikaz) kada pitanje traži klasifikaciju IMENOVANOG pisca, inače null.
     * Zahteva reč podele (domaći/naš/srpski/strani/inostrani) I reč pisac/autor,
     * pa se ime izvlači iz sirovog teksta (velika slova) posle „je".
     */
    public function podelaPisca(string $raw): ?string
    {
        $norm = TextUtils::normalize($raw);

        if (!preg_match('/domac|\bnas\b|\bnasi\b|srpsk|stran|inostran|svetsk/u', $norm)
            || !preg_match('/\bpis\w*|\bautor\w*|\bknjizevnik/u', $norm)) {
            return null;
        }

        $latin = TextUtils::cyrillicToLatin(trim($raw));

        // Ime posle „je" (uz mogući oblik „je li X"): jedna do četiri reči sa
        // velikim početnim slovom („... je Ivo Andrić domaći", „je li Andrić naš").
        // „je/li" case-neosetljivo — pitanje počinje velikim „Je li…" (G021-p1),
        // a golden „Da li JE Ivo…" ima malo „je" u sredini; ime ostaje case-osetljivo.
        if (preg_match('/\b[Jj]e\s+(?:[Ll]i\s+)?((?:[A-ZŠĐŽČĆ][\p{Ll}.\-]*\s*){1,4})/u', $latin, $m)) {
            $ime = trim($m[1]);

            return $ime !== '' ? $ime : null;
        }

        return null;
    }

    /**
     * „Preporuči mi nešto od STRANIH pisaca / stranih autora" (Faza 4) —
     * preporuka dela stranih pisaca iz kurirane liste.
     */
    public function jeStraniPisci(string $norm): bool
    {
        return (bool) preg_match('/preporuc|predloz|nesto\s+od|imate\s+li|\bkoje\b|\bkoji\b|neku|neki/u', $norm)
            && (bool) preg_match('/\bstran\w*|\binostran\w*|\bsvetsk\w*/u', $norm)
            && (bool) preg_match('/\bpis\w*|\bautor\w*|\bknjizevnik|\bknjizevnost/u', $norm);
    }

    public function jeIdentitet(string $norm): bool
    {
        return (bool) preg_match(
            '/\bko\s+si\s+ti\b|\bko\s+si\b|\bsta\s+si\s+ti\b|\bko\s+ste\s+vi\b'
            . '|\bkako\s+se\s+zoves\b|\bkako\s+se\s+ti\s+zoves\b|\bkako\s+ti\s+je\s+ime\b'
            . '|\bpredstavi\s+se\b|\btvoje\s+ime\b|\bkako\s+ti\s+je\s+ime\b'
            . '|\b(da\s+li\s+si|jesi\s+li)\s+(ti\s+)?(robot|bot|program|masin\w*|vestack\w*|ai|covek|clovek)/u',
            $norm
        );
    }

    /**
     * Pitanje o KONTAKTU biblioteke — nepromenljive činjenice (ime bibliotekarke,
     * mejl, telefon) koje se ne prepričavaju kroz slab model, već se serviraju
     * kao FIKSNI odgovor (TextUtils::KONTAKT). Pokriva tri porodice formulacija:
     *  - eksplicitan kontakt/telefon/mejl/adresa („kako da stupim u kontakt",
     *    „postoji li mejl ili telefon", „gde da nađem kontakt"),
     *  - KO vodi/je zadužen za biblioteku, KAKO se zove bibliotekarka
     *    („ko vodi biblioteku", „kako se zove osoba koja vodi biblioteku"),
     *  - KOME/S KIM da se obratim oko biblioteke („sa kim da razgovaram",
     *    „kome da se obratim").
     *
     * Namerno NE hvata pitanja o resetu lozinke (ona ne pominju kontakt/telefon/
     * mejl/bibliotekarku po imenu) — ta ostaju u FAQ toku sa svojom procedurom.
     */
    public function jeKontakt(string $norm): bool
    {
        return (bool) preg_match(
            '/\bkontakt\w*|\btelefon\w*|\bmejl\w*|\bimejl\w*|\be-?mail\w*'
            . '|\bbroj\w*\s+telefona|\bkontaktir\w*\s+bibliotek'
            . '|\bvodi\s+bibliotek|\bzaduzen\w*\s+za\s+bibliotek'
            . '|\bkome\s+(da\s+se\s+)?(obrat|jav)|\bsa\s+kim\b|\bs\s+kim\b'
            . '|\bkako\s+se\s+zove\b.{0,30}\bbibliotek|\bkako\s+se\s+zove\s+osob/u',
            $norm
        );
    }

    /**
     * FIKSNA FAQ TEMA (Faza 4 — FAQ determinizam): pitanja o nepromenljivim
     * činjenicama sa DISTINKTIVNOM leksikom dobijaju fiksni odgovor iz
     * TextUtils::FAQ_TEME (bez modela). Slab model ih je mrcvario, a strogi
     * leksički matcher (FaqZnanje::direktanOdgovor) ih je promašivao zbog klase
     * upitne reči („smem li" ≠ „koliko"). Vraća ključ teme ili null.
     *
     * NAMERNO uzak izbor tema — samo one čija leksika ne dolazi u sudar sa
     * susednim pitanjima. Rok preuzimanja/pozajmice i broj rezervacija su
     * SVESNO izostavljeni: dele leksiku sa brojačkim pitanjima („koliko knjiga
     * mogu da zadužim"), pa bi fiksna ruta obarala tačna pitanja — ta ostaju u
     * FAQ+model toku. Redosled je od najspecifičnije ka najširoj temi.
     */
    public function faqFiksnaTema(string $norm): ?string
    {
        // RESET LOZINKE — distinktivno (lozinka/šifra/pristupni podaci); NE kada
        // se traži PROMENA podataka (drugi tok, samouslužno u profilu).
        if (preg_match('/\blozink\w*|\bsifr\w*|\bpristupn\w*\s+podat/u', $norm)
            && !preg_match('/\bpromen\w*/u', $norm)) {
            return 'lozinka';
        }

        // OTKAZIVANJE REZERVACIJE — otkaz/poništi/povuci/opozovi uz rezervaciju.
        if (preg_match('/\botkaz\w*|\bponist\w*|\bpovuc\w*|\bpovlac\w*|\bopozov\w*/u', $norm)
            && preg_match('/\brezervacij\w*|\brezervis\w*/u', $norm)) {
            return 'otkaz_rezervacije';
        }

        // BROJ REZERVACIJA (najviše jedna) — rezervacija UZ signal množine
        // (istovremeno/odjednom/više/dve). NAMERNO bez „koliko": „koliko dana
        // propada rezervacija" je rok preuzimanja, ne broj — pa se kolizija
        // sa brojačkim/rok pitanjima zaobilazi (samo množinski signal okida).
        if (preg_match('/\brezervacij\w*|\brezervis\w*/u', $norm)
            && preg_match('/\bistovremeno\b|\bodjednom\b|\bvise\b|\bviše\b|\bdve\b|\bdva\b|\bdvije\b|\bu\s+isto\s+vreme\b/u', $norm)) {
            return 'broj_rezervacija';
        }

        // ČLANARINA (besplatna) — trošak/plaćanje/besplatno UZ članstvo. PRE
        // učlanjenja („košta li da se učlanim" je o ceni, ne o postupku).
        if (preg_match('/\bkosta\w*|\bplac\w*|\bplati\w*|\btrosk\w*|\btroskov\w*|\bbesplatn\w*|\bclanarin\w*/u', $norm)
            && preg_match('/\bclan\w*|\buclan\w*|\bclanstv\w*|\bclanarin\w*|koris(ti|nik)/u', $norm)) {
            return 'clanarina';
        }

        // NE-PRODAJE — prodaja/kupovina/otkup knjige od biblioteke.
        if (preg_match('/\bprodaj\w*|\botkup\w*|\bkupovin\w*|\bkupim\w*|\bkupit\w*/u', $norm)
            && preg_match('/\bknjig\w*|\bdel[oa]\b|\bprimer\w*|\bkatalog\w*|\bod\s+vas\b|\bkod\s+vas\b/u', $norm)) {
            return 'ne_prodaje';
        }

        // UČLANJENJE (student + indeks) — učlaniti/upisati se u biblioteku,
        // postati član/korisnik. Pitanje o „klubu" se namerno NE hvata: klub
        // čitalaca ne postoji u aplikaciji, pa takvo pitanje ne sme da
        // dobije odgovor o učlanjenju u biblioteku.
        if (!preg_match('/\bklub\b/u', $norm)
            && (preg_match('/\buclan\w*/u', $norm)
                || preg_match('/\bupis\w*\s+(se\s+)?u\s+bibliotek|\bda\s+se\s+upis\w*/u', $norm)
                || preg_match('/\bpostan\w*\s+(clan|korisnik)|\bpostao\s+(clan|korisnik)|\bpostati\s+clan/u', $norm))) {
            return 'uclanjenje';
        }

        return null;
    }

    /**
     * Da li pitanje imenuje ŽANR koga u fondu NEMA: neki token je poznata
     * žanrovska reč (ZANROVSKE_RECI, fuzzy), a ne poklapa se ni sa jednim
     * tokenom naziva POSTOJEĆEG žanra (fuzzy ili prefiks ≥ 4, ista pravila
     * kao StrukturniUpiti::zanrTokenPogodak). „Avanturistički roman" ovde
     * vraća true iako žanr Roman postoji — tražena KOMBINACIJA ne postoji,
     * pa router vraća pošten fiksni odgovor umesto knjiga pogrešnog žanra.
     */
    /**
     * PODŽANR van fonda (Potez 2, set13 p10/p15) — adjektivski modifikator koji
     * stvara usku vrstu koje u fondu (po žanru) nema: „psihološki triler",
     * „naučnofantastični roman". Router vraća pošten odgovor („nemamo taj žanr"
     * + spisak žanrova), umesto da model izmišlja ili ponudi ceo osnovni žanr.
     * Namerno uzak spisak modifikatora — proširivati po potrebi.
     */
    public function jePodzanrVanFonda(string $norm): bool
    {
        // „ljubavni roman / ljubavna priča / romansa / ljubić" — romantika kao
        // žanr u fondu ne postoji (set16 p7); ne sme da se sведe na „Priče".
        if (preg_match('/\bljubavn\w*|\bromans\w*|\bljubic\w*/u', $norm)) {
            return true;
        }

        return (bool) preg_match('/\bpsiholosk\w*|\bnaucnofantasticn\w*|\bnaucno-fantasticn\w*|\bepsk\w*\s+fantastik/u', $norm)
            && $this->jeZanrovskiUpit($norm);
    }

    public function imaZanrVanFonda(string $norm): bool
    {
        $zanrTokeni = $this->katalog->sviZanrTokeni();

        foreach (TextUtils::tokenizuj(TextUtils::kanonizujZanrPrideve($norm)) as $token) {
            $jeZanrovska = false;
            foreach (self::ZANROVSKE_RECI as $zanr) {
                if (TextUtils::fuzzyJednake($token, $zanr)) {
                    $jeZanrovska = true;
                    break;
                }
            }

            if (!$jeZanrovska) {
                continue;
            }

            $uFondu = false;
            foreach ($zanrTokeni as $zt) {
                if (TextUtils::fuzzyJednake($token, $zt)
                    || (mb_strlen($token) >= 4 && str_starts_with($zt, $token))) {
                    $uFondu = true;
                    break;
                }
            }

            if (!$uFondu) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pitanje o STRUČNOJ literaturi (matematika, fizika, programiranje,
     * baze podataka, elektronika, elektrotehnika…) — takve građe u fondu
     * trenutno nema, pa router (kada ni leksika ništa ne nađe) vraća
     * POŠTEN fiksni odgovor umesto preporuka beletristike ili „teme".
     * Čim se prva stručna knjiga unese u katalog, leksika je pronalazi i
     * pitanje ide normalnim tokom (fiksni odgovor se tada preskače).
     */
    public function jeStrucnaLiteratura(string $norm): bool
    {
        return (bool) preg_match(
            '/matematik|fizik|hemij|programiranj|informatik|racunar|softver|hardver'
            . '|elektronik|elektrotehnik|masinstv|masinsk|mehanik|robotik|automatik|algoritm'
            . '|baz[aeiu]?\s+podataka|bazama\s+podataka|operativn\w*\s+sistem|strucn\w*\s+literatur/u',
            $norm
        );
    }

    /**
     * Da li korisnik TRAŽI AKCIJU koja zahteva nalog (rezervacija, zaduženje,
     * pozajmica) — „želim da rezervišem", „rezerviši mi", „mogu li da zadužim".
     *
     * Pitanja o POSTUPKU („kako da rezervišem", „gde se zadužuje") NISU
     * akcioni zahtev — na njih odgovara FAQ deo prompta (uputstvo ionako
     * kaže da je akcija samo za članove). Isto važi za pitanja o PRAVILIMA
     * sa „koliko" („na koliko dana najviše mogu da zadužim", „koliko knjiga
     * mogu da zadužim") — informativna su i gost sme da ih postavi. Glagol
     * mora biti u prvom licu ili infinitivu — „da li je knjiga zadužena"
     * je pitanje o dostupnosti.
     */
    public function jeAkcioniZahtev(string $norm): bool
    {
        if (preg_match('/\b(kako|gde|koliko|postupak|uputstv|uslov)/u', $norm)) {
            return false;
        }

        $glagoli = '(rezervisem|zaduzim|pozajmim|iznajmim|rezervisati|zaduziti|pozajmiti|iznajmiti)';

        return (bool) (
            preg_match('/\b(zelim|zeleo|zelela|hocu|hteo|htela|voleo|volela|mogu|moze|mozete|da li)\b.{0,40}\b' . $glagoli . '/u', $norm)
            || preg_match('/^\s*(rezervisi|zaduzi|pozajmi|iznajmi)\b/u', $norm)
            || preg_match('/\b(rezervisao|rezervisala|zaduzio|zaduzila|pozajmio|pozajmila)\s+bih\b/u', $norm)
        );
    }

    /**
     * Pitanje PRIJAVLJENOG člana o SOPSTVENIM rezervacijama/zaduženjima:
     * „šta sam rezervisao", „koje su moje rezervacije", „da li mi je odobrena
     * rezervacija", „kad mi ističe rok", „koje knjige sam zadužio". Odgovor se
     * gradi FIKSNO iz baze (clanKontekst, filtrirano po user_id iz sesije).
     *
     * Traži prvo lice / prisvojni marker („moj/moja/moje", „mi", „sam") UZ pojam
     * rezervacije/zaduženja/roka — da se razdvoji od OPŠTIH FAQ pitanja o
     * postupku („kako da otkažem rezervaciju", „koliki je rok pozajmice"), koja
     * ostaju u svom toku. Pitanja o POSTUPKU/pravilima („kako", „gde") se izuzimaju.
     */
    public function jeMojeRezervacijeUpit(string $norm): bool
    {
        // Postupak/pravila („kako da otkažem", „gde se preuzima") nisu upit o
        // sopstvenom stanju naloga — prepuštaju se FAQ toku.
        if (preg_match('/\b(kako|gde|postupak|na koji nacin)\b/u', $norm)) {
            return false;
        }

        // Hipotetičko pitanje o redosledu („ako rezervišem sada, koji sam po redu
        // na listi čekanja") ide u listaCekanja tok, ne u status naloga.
        if ($this->jeListaCekanja($norm) || preg_match('/\bako\s+rezervis\w*/u', $norm)) {
            return false;
        }

        // Eksplicitni obrasci pitanja o SOPSTVENOM stanju naloga. Prisvojno
        // „moj\w*" ili prošlo „sam rezervisao/zadužio", a dativ „mi" samo u
        // spoju koji označava vlasništvo/rok („mi je odobrena", „mi ističe rok")
        // — bare „mi" se izbegava jer je i dativ učtivosti („preporuči mi").
        return (bool) (
            preg_match('/\bmoj\w*\b.{0,30}\b(rezervacij|zaduz|pozajm|knjig)/u', $norm)
            || preg_match('/\b(rezervacij|zaduz|pozajm)\w*\b.{0,20}\bmoj\w*/u', $norm)
            || preg_match('/\bsam\b.{0,20}\b(rezervis|zaduz|pozajm)\w*/u', $norm)
            || preg_match('/\b(rezervis|zaduz|pozajm)\w*\s+sam\b/u', $norm)
            || preg_match('/\bmi\s+(je|su)\b.{0,25}\b(odobren|rezervacij|zaduz|preuz)/u', $norm)
            || preg_match('/\bmi\b.{0,15}\b(istice|istek\w*|rok)\b/u', $norm)
        );
    }

    /**
     * Pitanje o LISTI ČEKANJA / redosledu rezervacija („ako rezervišem sada,
     * koji sam po redu na listi čekanja") — odgovor se RAČUNA iz svežeg
     * stanja baze (broj aktivnih rezervacija + dostupnost), model se ne
     * poziva (nalaz iz ručnog testiranja: model je izmišljao dostupnost).
     */
    public function jeListaCekanja(string $norm): bool
    {
        return (bool) preg_match(
            '/\bpo\s+redu\b|\blist[aeiu]\s+cekanja|\bred\w*\s+cekanja|\blist[aeiu]\s+za\s+cekanje/u',
            $norm
        );
    }

    /**
     * Pitanje o DOSTUPNOSTI konkretne knjige („da li je X dostupna",
     * „da li je zauzeta", „ima li slobodnih primeraka") — kada leksika
     * pogodi naslov, odgovor daje sveže stanje baze kao FIKSNA poruka.
     */
    public function jeDostupnostUpit(string $norm): bool
    {
        return (bool) preg_match(
            '/dostupn|slobodn|zauzet|na\s+stanju|raspoloziv/u',
            $norm
        );
    }

    /**
     * Statistika UČESTALOSTI zaduživanja uopšte („koliko često se zadužuju
     * knjige", „koliko se nedeljno zaduži") — odgovor se RAČUNA iz arhive
     * (StrukturniUpiti), ne pogađa iz konteksta. Pitanje o konkretnoj
     * knjizi („koliko puta je zaduživana X") rešava normalan tok — router
     * statistiku primenjuje samo kada nema leksičkih pogodaka.
     * Povratni oblik „se" razdvaja statistiku od FAQ pitanja o pravilima
     * („koliko puta MOGU da rezervišem" nije statistika).
     */
    public function jeStatistika(string $norm): bool
    {
        return (bool) preg_match(
            '/koliko\s+(cesto|puta)?\s*se\b.{0,40}(zaduz|izda|pozajm|rezervis)'
            . '|(nedeljno|mesecno|dnevno|godisnje|prosecno|u proseku)\b.{0,30}\bse\b.{0,20}(zaduz|izda|pozajm|rezervis)'
            . '|(zaduzuje|zaduzi|izdaje|izda|pozajmljuje|rezervise)\s+se\b.{0,20}\b(nedeljno|mesecno|dnevno|prosecno)/u',
            $norm
        );
    }

    /**
     * „Najčitanija/najtraženija/najčešće izdavana knjiga" — sve formulacije
     * istog pitanja (Nalaz 5: robusnije od ranije verzije koja je glagol
     * tražila ODMAH iza „najviše/najčešće"):
     *  - pridevski oblici: „najčitanija", „najizdavanija", „najtraženija",
     *  - „najviše/najčešće … čita/zadužuje/traži/izdaje/pozajmljuje"
     *    (i imenički: „ima najviše zaduženja", „najviše puta zaduživana"),
     *  - „najveći broj zaduženja/rezervacija/pozajmica".
     * Izuzetak (?!\s+(knjig|primer)): „koliko najviše knjiga mogu da
     * zadužim" je FAQ o pravilima, ne statistika tražnje.
     */
    public function jeNajcitanije(string $norm): bool
    {
        // FAQ o pravilima pozajmice, NE statistika tražnje: „na koliko dana
        // najviše MOGU da zadužim", „koliko knjiga smem da rezervišem" —
        // modalni glagol uz akciju u prvom licu isključuje ovaj tip (na
        // takva pitanja odgovaraju FAQ primeri, ne lista najčitanijih).
        if (preg_match(
            '/\b(mogu|smem|sme|moze|mozes|mozete)\b.{0,30}\b(zaduzim|rezervisem|pozajmim|iznajmim|zadrzim|produzim|vratim|uzmem)\b/u',
            $norm
        )) {
            return false;
        }

        return (bool) preg_match(
            '/najcitanij|najtrazenij|najpopularnij|najizdavanij|najzaduzivanij|najpozajmljivanij'
            . '|\bpopularn\w*\b'
            . '|naj(vise|cesce)\b(?!\s+(knjig|primer)).{0,30}?\b(cita|zaduz|traz|izda|rezervis|pozajm)'
            . '|najvec\w*\s+broj\w*\s+(zaduz|rezervacij|pozajm|izdavanj|citanj)/u',
            $norm
        );
    }

    /**
     * Da li je pitanje o KATALOGU (knjige, autori, žanrovi, preporuke) —
     * regex nad celom porukom + FUZZY dopuna po tokenima, da tipfeler u
     * ključnoj reči („kniga" umesto „knjiga") ne obori prepoznavanje i
     * pošalje pitanje u odbijanje van teme (nalaz B2.5).
     */
    public function jeKatalosko(string $norm): bool
    {
        if (preg_match(
            '/knjig|roman|autor|pisac|zanr|naslov|preporuc|lektir|poezij|pesm|pric|delo|dela|izdanj|izdavac|citanj|procita/u',
            $norm
        )) {
            return true;
        }

        static $osnovne = [
            'knjiga', 'knjige', 'knjigu', 'knjigama', 'roman', 'romani',
            'autor', 'autora', 'pisac', 'pisca', 'zanr', 'zanra', 'zanrovi',
            'naslov', 'naslova', 'lektira', 'lektiru', 'preporuka', 'preporuci',
        ];

        foreach (TextUtils::tokenizuj($norm) as $token) {
            foreach ($osnovne as $rec) {
                if (TextUtils::fuzzyJednake($token, $rec)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * „Koje knjige NIKADA nisu zadužene / nisu nijednom pozajmljene" —
     * strukturni upit: spisak se izračunava iz baze (COUNT zaduženja = 0),
     * model se ne poziva (nalaz A3.26).
     */
    public function jeNikadZaduzene(string $norm): bool
    {
        return (bool) (
            preg_match('/\b(nikad\w*|nijednom|ni\s+jednom)\b.{0,30}\b(zaduz|pozajmlj|izda|citan)/u', $norm)
            || preg_match('/\b(zaduz|pozajmlj|izda)\w*\b.{0,20}\b(nikad\w*|nijednom)/u', $norm)
            || preg_match('/\bbez\s+(ijednog|i\s+jednog|nijednog)\s+zaduz/u', $norm)
        );
    }

    /**
     * POREĐENJE POPULARNOSTI ŽANROVA („uporedi popularnost naučne fantastike
     * i istorije po broju zaduživanja") — signalna reč poređenja; router
     * upit izvršava tek kada u tekstu nađe bar DVA postojeća žanra
     * (StrukturniUpiti::nadjiZanroveUTekstu), inače ovaj signal ne važi.
     */
    public function jePoredjenjePopularnosti(string $norm): bool
    {
        // (?<!naj): „najčitanije/najtraženije" su superlativ (najčitanija
        // lista), ne poređenje dva žanra.
        return (bool) preg_match(
            '/\buporedi|\bporedi\w*|poredjenj|popularnost|popularnij|(?<!naj)trazenij[aei]|(?<!naj)citanij[aei]\b/u',
            $norm
        );
    }

    /**
     * „Koja knjiga ima najveći ODNOS rezervacija u odnosu na broj primeraka"
     * — strukturni upit: odnos se RAČUNA nad bazom (COUNT/COUNT), model se
     * ne poziva (nalaz A3.28).
     */
    public function jeOdnosRezervacija(string $norm): bool
    {
        return (bool) (
            preg_match('/\bodnos\w*\b.{0,40}\brezervacij/u', $norm)
            || preg_match('/\brezervacij\w*\b.{0,30}\bu\s+odnosu\s+na\b/u', $norm)
        );
    }

    /**
     * „Koliko je PROSEČNO puta zadužena jedna knjiga" — AVG nad bazom
     * (ukupno zaduženja / broj naslova), model se ne poziva (nalaz A3.30).
     * Periodične formulacije („koliko se prosečno zaduži nedeljno") NISU
     * ovaj tip — njih pokriva jeStatistika (učestalost u vremenu).
     */
    public function jeProsekZaduzenja(string $norm): bool
    {
        if (preg_match('/nedeljno|mesecno|dnevno|godisnje|cesto/u', $norm)) {
            return false;
        }

        return (bool) preg_match('/\b(prosecn\w*|prosek\w*|u\s+proseku)\b.{0,40}\b(zaduz|pozajmlj|izda)/u', $norm);
    }

    /**
     * Pitanje o podacima VAN KATALOGA (kandidati_ucenje_5, korpa F): globalna/
     * spoljna procena koju bibliotečki katalog ne pokriva — „najpopularniji žanr
     * u Srbiji", „najčitanija knjiga na svetu", „najbolja knjiga svih vremena",
     * „najviše prodatih knjiga na svetu". Router vraća pošten scope-odgovor
     * umesto da katalošku statistiku predstavi kao globalnu.
     *
     * Uslov je SUPERLATIV/procena UZ svetski/nacionalni doseg (ili prodaju, koje
     * biblioteka ne vodi) — „knjige izdate u Srbiji" (bez superlativa) ostaje
     * normalan tok pretrage po mestu.
     */
    public function jeVanKataloga(string $norm): bool
    {
        $procena = (bool) preg_match(
            '/\bnaj\w+|\bnajbolj\w*|najleps\w*|najcitanij\w*|najpopularnij\w*|najprodavanij\w*/u',
            $norm
        );

        $doseg = (bool) preg_match(
            '/\bu\s+srbiji\b|\b(u|na)\s+svetu\b|\bsvetsk\w*|\bsvih\s+vremena\b'
            . '|\bna\s+planeti\b|\bu\s+svetskoj\b|\bprodat\w*|\bprodavanij\w*/u',
            $norm
        );

        // Mora biti iz DOMENA KATALOGA (knjiga/žanr/autor/roman/naslov) — „ko je
        // najbolji fudbaler na svetu" je van teme (VAN_TEME odbijanje), ne scope.
        return $procena && $doseg && $this->jeKatalosko($norm);
    }

    /**
     * DUBLJA ANALITIKA po sekundarnim osama — godina / mesto izdavanja / izdavač
     * (kandidati_ucenje_5, korpa E). Svesna granica dizajna: ukrštanja tih osa
     * (izdavač×žanr, autor/žanr filtriran po godini, grad×popularnost, prosek/
     * egzistencija po izdavaču…) NE računamo automatski, pa router vraća pošteno
     * odbijanje umesto pogrešnog jednoosnog odgovora ili halucinacije.
     *
     * Namerno NE hvata OSNOVNE jednoosne rute koje već rade: „najviše knjiga
     * izdavač", „najpopularniji izdavač", „u kojoj godini najviše knjiga",
     * „najstarija/najnovija" — one nemaju drugu sekundarnu osu uz sebe.
     */
    public function jeDubljaAnalitika(string $norm): bool
    {
        // Analitičko pitanje: superlativ, prosek ili egzistencija nad grupom.
        $analitika = (bool) preg_match(
            '/\bnaj(vise|veci|manje|cesce|zastupljenij|popularnij|citanij|trazenij|prodavanij)\w*/u',
            $norm
        )
        || (bool) preg_match('/\bprosecn\w*|\bprosek\w*|\bu\s+proseku\b/u', $norm)
        || (bool) preg_match('/\bpostoji\s+li\b.{0,80}\b(sve|svih|nijedn\w*|nikad\w*)\b/u', $norm)
        || (bool) preg_match('/\b(sve|svih)\s+knjig\w*\b.{0,40}\b(pozajm|zaduz|citan)/u', $norm);

        if (!$analitika) {
            return false;
        }

        $godina   = (bool) preg_match('/\b(1[89]\d{2}|20\d{2})\b|\bgodin/u', $norm);
        $mesto    = (bool) preg_match('/\bgrad\w*|\bmest[aeou]\w*|\bu\s+beograd\w*|\bvan\s+beograd\w*/u', $norm);
        $izdavac  = (bool) preg_match('/\bizdava[cč]/u', $norm);
        $zanr     = (bool) preg_match('/\bzanr/u', $norm) || $this->jeZanrovskiUpit($norm);
        $autor    = (bool) preg_match('/\bautor|\bpisac|\bpisca|\bpisci\b/u', $norm);
        $zaduz    = (bool) preg_match('/\bpozajm|\bzaduz|\bcitan|\bpopularn|\bprodat/u', $norm);
        $prosek   = (bool) preg_match('/\bprosecn\w*|\bprosek\w*|\bu\s+proseku\b/u', $norm);
        $egzist   = (bool) preg_match('/\bnikad\w*|\bnijedn\w*|\bbez\s+\w{0,10}\s*zaduz|\b(sve|svih)\s+knjig/u', $norm);

        // MESTO kao osa analitike — nema osnovne rute → uvek dublja analitika
        // („koje mesto se najčešće pojavljuje", „grad sa najviše pozajmljenih").
        if ($mesto) {
            return true;
        }

        // IZDAVAČ ukršten sa žanrom/godinom, ili prosek/egzistencija po izdavaču.
        // (izdavač × ukupna popularnost je osnovna ruta „najpopularniji izdavač"
        //  i NAMERNO ostaje — zato bez $zaduz kao samostalnog uslova.)
        if ($izdavac && ($zanr || $godina || $prosek || $egzist)) {
            return true;
        }

        // GODINA ukrštena sa primarnom osom (autor/žanr filtriran po godini →
        // odbij, odluka korisnika) ili sa zaduženjima.
        if ($godina && ($izdavac || $zanr || $autor || $zaduz)) {
            return true;
        }

        return false;
    }

    /**
     * Iz pitanja „ko je X" izvlači X — za proveru da li je ta osoba autor u
     * bazi (bib deo 4: ako nije autor → fiksno odbijanje van teme).
     *
     * Vraća null za oblike koji NISU pitanje o osobi iz opšteg znanja:
     * „ko je zadužio/rezervisao…" (arhivski upit), „ko je napisao/autor…"
     * (kataloško pitanje — rešava ga leksička pretraga naslova).
     */
    public function izvuciKoJe(string $norm): ?string
    {
        if (!preg_match('/\bko\s+je\s+([^?.!]{3,60})/u', $norm, $m)) {
            return null;
        }

        $fraza = trim($m[1]);

        if (preg_match('/^(zaduzi|rezervis|pozajmi|iznajmi|uze|preuze|vrati|napisa|autor|izdava)/u', $fraza)) {
            return null;
        }

        return $fraza;
    }

    /**
     * Iz pitanja izvlači frazu kojom je IMENOVAN IZDAVAČ — izričite
     * formulacije „(od) izdavača X" / „izdavačke kuće X". Velika slova NISU
     * uslov (okidačka reč je izričita, a korisnici izdavače kucaju i malim
     * slovima). Repni deo o žanru/godini i repni glagoli pitanja se odsecaju
     * („izdavača Laguna imate posle 2015" → „Laguna") — router zatim PRESEKOM
     * sužava na knjige tog izdavača u tom žanru / opsegu godine.
     *
     * Fraza koja počinje upitnom/pomoćnom rečju NIJE ime izdavača („od kog
     * izdavača SU najviše knjige…") — takva pitanja rešava izdavacStatistika.
     *
     * Vraća ['prikaz' => oblik za ispis, 'norm' => za poređenje] ili null.
     */
    public function izvuciIzdavacFrazu(string $raw): ?array
    {
        $latin = TextUtils::cyrillicToLatin(trim($raw));

        // Izričito „(od) izdavača X / izdavačke kuće X", ILI oblik „koje knjige
        // je IZDALA/OBJAVILA X" (subjekat je izdavač) — aktivni glagol izdanja
        // (ne pasivno „izdate", koje je pitanje o mestu/godini) uz „koje knjige"
        // imenuje izdavača (kandidati_ucenje_4, set6 p11). Router zatim STROGO
        // proverava postojanje izdavača u bazi (nadjiIzdavaca).
        if (!preg_match('/\bizdava[cč]\w*(?:\s+ku[cć]\w*)?\s+([^?.!,;]{2,60})/iu', $latin, $m)
            && !preg_match(
                // „koje knjige je izdala X" ILI „…knjigu koju je izdala X"
                // (relativna rečenica; deo „knjige" je opcion, jer subjekt-izdavač
                // dolazi i posle imenice: „tražim knjigu koju je izdala Laguna").
                '/\bkoj\w*\s+(?:(?:sve\s+)?knjig\w*\s+)?(?:je\s+|su\s+|sve\s+)?'
                . '(?:izda(?:la|le|li|o)|objavi(?:la|le|li|o))\s+([^?.!,;]{2,40})/iu',
                $latin,
                $m
            )) {
            return null;
        }

        $fraza = preg_replace(
            '/\s+(iz|u|na\s+temu|o\s+temi|za|posle|pre|nakon|izmedju|između|objavljen\w*|izdat\w*'
            . '|stampan\w*|štampan\w*|imate|imamo|ima|nudite|posedujete|drzite|držite)\b.*$/iu',
            '',
            trim($m[1])
        ) ?? trim($m[1]);
        $fraza = preg_replace('/\s+(1[89]\d{2}|20\d{2})\s*$/u', '', $fraza) ?? $fraza;
        $fraza = $this->skiniRepneReci($fraza);

        $norm = TextUtils::normalize($fraza);
        if (mb_strlen($fraza) < 2
            || preg_match(
                '/^(knjig\w*|del[oa]|naslov\w*|koji|koja|koje|kog|kojeg|kojih|je|su|se|li|da|ne'
                . '|nam|vam|sta|trenutno|najvise|postoj\w*|nudite|ovde|kod|ima\w*)\b/u',
                $norm
            )) {
            return null;
        }

        return ['prikaz' => $fraza, 'norm' => $norm];
    }

    /**
     * STATISTIKA O IZDAVAČIMA (strukturni upit — brojevi iz baze, model se
     * ne poziva). Proverava se SAMO kada izdavač nije imenovan:
     *  - 'najpopularniji'  → izdavač sa najviše zaduživanja (arhiva),
     *  - 'najvise_knjiga'  → izdavač sa najviše knjiga u katalogu,
     *  - 'vise_od_jedne'   → spisak izdavača sa VIŠE od jedne knjige,
     *  - 'lista'           → spisak izdavača sa brojem knjiga.
     */
    public function izdavacStatistika(string $norm): ?string
    {
        if (!preg_match('/izdavac/u', $norm)) {
            return null;
        }

        // „koji izdavači imaju više od jedne knjige" (kandidati_ucenje_2 #34 p15).
        if (preg_match('/\bvise\s+od\s+jedne\b|\bvise\s+od\s+1\b/u', $norm)) {
            return 'vise_od_jedne';
        }

        if (preg_match('/najpopularnij|najcitanij|najtrazenij|najzaduzivanij/u', $norm)
            || preg_match('/naj(?:vise|cesce)\b.{0,30}\b(zaduz|cita|traz|pozajm)/u', $norm)
            || preg_match('/najvec\w*\s+broj\w*\s+(zaduz|pozajm|rezervacij|citanj)/u', $norm)) {
            return 'najpopularniji';
        }

        if (preg_match('/naj(?:vise|veci|zastupljenij)/u', $norm)
            // „koji izdavač se NAJČEŠĆE POJAVLJUJE / je najzastupljeniji u
            // katalogu" — najviše NASLOVA (ne zaduženja): set17 p9.
            || preg_match('/najces[ćc]\w*\b.{0,30}(pojavljuj|javlja|zastupljen|katalog|ponavlja|sre[cć])/u', $norm)) {
            return 'najvise_knjiga';
        }

        // „koje izdavače imate", „koliko izdavača", „spisak izdavača" —
        // množina/spisak; jednina („koji izdavač je izdao X") NIJE lista.
        if (preg_match('/(?:koje|koji|kojih|koliko|sve|svih|svi)\b.{0,30}\bizdavac(?:e|i|a)\b/u', $norm)
            || preg_match('/\b(?:spisak|lista|listu|popis|izlistaj|nabroj|nabrojte|navedi|navedite|prikazi|ispisi)\b.{0,30}izdavac/u', $norm)
            || preg_match('/izdavac\w*\s+(?:imate|imamo|postoje|nudite)/u', $norm)) {
            return 'lista';
        }

        return null;
    }

    /**
     * Da li je poruka čisto KONVERZACIONA (pozdrav, zahvalnica, potvrda) —
     * sve reči su iz upitnog šablona ili konverzacionog rečnika. Takva
     * poruka ne sme da dobije odbijanje van teme.
     */
    public function jeKonverzacioni(string $norm): bool
    {
        $tokeni = TextUtils::tokenizuj($norm);

        if (empty($tokeni)) {
            return false;
        }

        foreach ($tokeni as $token) {
            if (!in_array($token, self::STOP_RECI, true)
                && !in_array($token, self::KONVERZACIONE_RECI, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prepoznaje zahtev za SPISKOM knjiga određenog žanra („izlistaj knjige
     * žanra priče", „koje sve knjige imate iz žanra roman", „nabroj romane")
     * i vraća frazu kojom je žanr imenovan (normalizovanu) — router je
     * uparuje sa postojećim žanrom i vraća KOMPLETNU listu iz baze.
     */
    public function zanrListaFraza(string $norm): ?string
    {
        $listaRec = false;
        foreach (TextUtils::tokenizuj($norm) as $token) {
            if (in_array($token, self::LISTA_RECI, true)) {
                $listaRec = true;
                break;
            }
        }

        // „koje (sve) knjige … žanra X" je zahtev za listom i bez reči za spisak.
        $trazilista = $listaRec
            || preg_match('/\b(koje|koja|sve|svih)\b.{0,40}\bzanr/u', $norm)
            || preg_match('/\bzanr\w*\b.{0,40}\b(koje|sve)\b/u', $norm);

        // 1) Fraza posle reči „žanr(a/u)…" — najjasniji oblik.
        if ($trazilista && preg_match('/\bzanr(?:a|u|om|ova|ove)?\s+([a-z0-9 ]{3,40})/u', $norm, $m)) {
            $fraza = trim($m[1]);
            if ($fraza !== '') {
                return $fraza;
            }
        }

        // 2) „Izlistaj (sve) romane" — reč za spisak + žanrovska reč bez „žanr".
        if ($listaRec) {
            foreach (TextUtils::tokenizuj($norm) as $token) {
                if (mb_strlen($token) >= 4 && $this->jeZanrovskaRec($token)) {
                    return $token;
                }
            }
        }

        // 3) „šta imate od pripovetki?", „a od priča?", „imate li romane?" —
        //    pitanje o ponudi u kome su SVE sadržajne reči žanrovske: zahtev
        //    za listom žanra i bez reči „žanr"/reči za spisak. Pokriva i
        //    NASTAVAK razgovora („koje knjige imate u žanru priče" → „a od
        //    pripovetki šta imate"). Uslov „sve reči" čuva mešovita pitanja
        //    („imate li priče o ljubavi", „šta imate od Andrića") za
        //    normalan tok pretrage.
        if (preg_match('/\b(imate|imamo|ima|nudite|ponudi|posedujete|drzite|sta|koje|kojih|od)\b/u', $norm)) {
            $kljucne = $this->katalog->kljucneReci($norm);

            if (!empty($kljucne)) {
                foreach ($kljucne as $rec) {
                    if (!$this->jeZanrovskaRec($rec)) {
                        return null;
                    }
                }

                return implode(' ', $kljucne);
            }
        }

        return null;
    }

    /**
     * Prepoznavanje pitanja „koja/koliko knjiga/naslova sadrži reč X (u
     * naslovu)" u više formulacija — vraća traženu reč ili null.
     *
     * Robusno na navodnike SVIH vrsta („X", "X", 'X'…): posle signalne reči
     * („reč", „sadrži") dozvoljen je bilo koji ne-slovni znak pre same reči
     * ([^a-z0-9]*), pa oblik navodnika ne utiče na prepoznavanje.
     */
    public function detektujCountRec(string $norm): ?string
    {
        // Bez signala o naslovu/broju/sadržanju — nije ovaj tip pitanja.
        if (!preg_match('/koliko|sadrz|naslov|naziv/u', $norm)) {
            return null;
        }

        $obrasci = [
            // „…reč X…" — najjači signal, radi sa svim navodnicima
            '/\brec(?:ju|i)?\b[^a-z0-9]*([a-z0-9]{3,})/u',
            // „…sadrži X u naslovu/nazivu"
            '/sadrz\w*\s+[^a-z0-9]*([a-z0-9]{3,})[^a-z0-9]*\s+u\s+(?:naslovu|nazivu)/u',
            // „koliko knjiga/naslova/dela (u naslovu) sadrži/ima X"
            '/koliko\s+(?:knjiga|naslova|naziva|dela)\s+(?:u\s+(?:nazivu|naslovu)\s+)?(?:sadrzi|ima)\s+(?:odredjenu\s+)?[^a-z0-9]*([a-z0-9]{3,})/u',
            // „u naslovu/nazivu (sadrži) (reč) X"
            '/u\s+(?:nazivu|naslovu)\s+(?:sadrz\w+\s+)?[^a-z0-9]*([a-z0-9]{3,})/u',
        ];

        foreach ($obrasci as $obrazac) {
            if (preg_match($obrazac, $norm, $m) && !in_array($m[1], self::STOP_RECI, true)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * „Koliko knjiga počinje na slovo I" / „koje knjige počinju slovom S" —
     * strukturni upit: broj i spisak se izračunavaju iz kataloga
     * (KatalogPretraga::naslovNaSlovo), model se ne poziva. Uvedeno posle
     * ručnog testiranja: model je za ovakva pitanja IZMIŠLJAO i broj i
     * naslove („U katalogu imamo 4 knjige… Island, Ilija i Dafni…").
     *
     * Vraća traženo slovo (normalizovano, malo) ili null.
     */
    public function pocinjeNaSlovo(string $norm): ?string
    {
        if (!preg_match('/knjig|naslov|naziv/u', $norm)) {
            return null;
        }

        // „prezime/autor na slovo X" NIJE naslov na slovo — to je pretraga po
        // autoru (prezimeNaSlovo), pa ovde ne otimamo takvo pitanje.
        if (preg_match('/\bprezim\w*|\bautor\w*|\bpisac\w*|\bpiscu\w*/u', $norm)
            && !preg_match('/\bnaslov\w*|\bnaziv\w*|\bknjig\w*\s+(?:koj\w*\s+)?(?:pocinj|krec)/u', $norm)) {
            return null;
        }

        if (preg_match(
            '/\b(?:pocinj\w*|pocne|pocnu|krecu|krece)\b.{0,30}\bslov(?:o|om|u)\s*[^a-z0-9]*([a-z0-9])(?![a-z0-9])/u',
            $norm,
            $m
        )) {
            return $m[1];
        }

        if (preg_match('/\bna\s+slovo\s*[^a-z0-9]*([a-z0-9])(?![a-z0-9])/u', $norm, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Opseg GODINE IZDANJA iz pitanja — filtriranje kataloga po godini
     * izdanja je strukturni upit (broj/spisak iz baze, model se ne poziva).
     *
     * Podržani oblici (2026-07-10 — puno filtriranje po godini):
     *  - opseg: „između 1990 i 2000", „od 2010 do 2015" (obrnute granice
     *    se zamene),
     *  - granica: „posle/nakon/od 2010", „novije od 2010"; „pre/do 2000",
     *    „starije od 2000",
     *  - tačna godina: „iz 2018", „u 2018", i BEZ predloga — „izdate 2018",
     *    „objavljena 2019. godine", „…2018. godine",
     *  - relativno: „izdate ove/prošle/pretprošle godine",
     *  - starost: „starije/novije od 10 godina", „iz poslednjih 5 godina".
     *
     * SVE granice su UKLJUČIVE („posle 2010" = od 2010. nadalje) — odgovor
     * izričito kaže „od X. godine"/„do X. godine", pa je značenje jasno.
     *
     * Null kada pitanje nije o katalogu po godini: bez kataloškog konteksta
     * (kataloška reč, pomen godine/starosti ILI žanrovska reč — „šta imate
     * od poezije iz 2015" nema reč „knjiga", a jeste pitanje o katalogu),
     * ili je o arhivi/nalogu (zaduženja rešava parsirajDatumskiOpseg).
     *
     * @return array{od: ?int, do: ?int}|null
     */
    public function godinaIzdanjaOpseg(string $norm): ?array
    {
        // Arhivski/nalog kontekst — nije pitanje o godini izdanja.
        if (preg_match('/zaduz|pozajm|vrati|arhiv|rezervis/u', $norm)) {
            return null;
        }

        if (!preg_match('/knjig|roman|naslov|naziv|del[oa]|izda|objavlj|stampan|godin|novij|starij/u', $norm)
            && !$this->jeZanrovskiUpit($norm)) {
            return null;
        }

        $g = '(1[89]\d{2}|20\d{2})';

        // OPSEG: „između 1990 i 2000", „od 2010 do 2015" — obrnute granice
        // („između 2020 i 2010") se zamene umesto da daju prazan spisak.
        if (preg_match('/\b(?:izmedju|od)\s+' . $g . '\.?\s*(?:i|do|-)\s*' . $g . '/u', $norm, $m)) {
            [$od, $do] = [(int) $m[1], (int) $m[2]];

            return $od <= $do ? ['od' => $od, 'do' => $do] : ['od' => $do, 'do' => $od];
        }

        // „starije od 2000" PRE generičkog „od X" — inače bi „od 2000"
        // pogodilo pogrešan smer (od 2000. nadalje umesto do 2000).
        if (preg_match('/\bstarij\w*\s+od\s+' . $g . '/u', $norm, $m)
            || preg_match('/\b(?:pre|do)\s+' . $g . '/u', $norm, $m)) {
            return ['od' => null, 'do' => (int) $m[1]];
        }

        if (preg_match('/\b(?:posle|nakon|od)\s+' . $g . '/u', $norm, $m)
            || preg_match('/\bnovij\w*\s+od\s+' . $g . '/u', $norm, $m)) {
            return ['od' => (int) $m[1], 'do' => null];
        }

        if (preg_match('/\b(?:iz|u)\s+' . $g . '\b/u', $norm, $m)) {
            return ['od' => (int) $m[1], 'do' => (int) $m[1]];
        }

        // TAČNA GODINA BEZ PREDLOGA: „izdate 2018", „objavljena je 2019",
        // „…2018. godine" — gola godina uz glagol izdanja ili uz reč „godine".
        if (preg_match('/\b(?:izda\w*|objavljen\w*|stampan\w*|izasl\w*)\s+(?:su\s+|je\s+)?' . $g . '\b/u', $norm, $m)
            || preg_match('/\b' . $g . '\.?\s*godin/u', $norm, $m)) {
            return ['od' => (int) $m[1], 'do' => (int) $m[1]];
        }

        $sada = (int) now()->year;

        // RELATIVNA GODINA („ove/prošle/pretprošle godine") je dvosmislena van
        // kataloga: „Ko je osvojio Ligu šampiona prošle godine" NIJE upit o
        // godini izdanja. Zato traži STVARNI kataloški kontekst — ne reč „godin"
        // (ona je deo same fraze i uvek je prisutna, pa bi svaku „prošle godine"
        // proglasila katalogom). Apsolutne godine (broj 19xx/20xx) i „starije od
        // N godina" iznad/ispod ostaju netaknute — one su nedvosmislene.
        $praviKatalog = (bool) preg_match(
            '/knjig|roman|naslov|naziv|del[oa]|izda|objavlj|stampan|novij|starij/u', $norm
        ) || $this->jeZanrovskiUpit($norm);

        if ($praviKatalog) {
            if (preg_match('/\bpretprosle\s+godin/u', $norm)) {
                return ['od' => $sada - 2, 'do' => $sada - 2];
            }
            if (preg_match('/\b(?:prosle|prethodne)\s+godin/u', $norm)) {
                return ['od' => $sada - 1, 'do' => $sada - 1];
            }
            if (preg_match('/\b(?:ove|tekuce)\s+godin/u', $norm)) {
                return ['od' => $sada, 'do' => $sada];
            }
        }

        // STAROST U GODINAMA (broj godina, ne kalendarska godina — kalendarske
        // su već obrađene iznad): „starije od 10 godina" → do danas−10,
        // „novije od 5 godina" / „iz poslednjih 5 godina" → od danas−5.
        $brojevi = implode('|', array_keys(self::BROJ_RECI));

        if (preg_match('/\bstarij\w*\s+od\s+(?<n>\d{1,3}|' . $brojevi . ')\s+godin/u', $norm, $m)) {
            return ['od' => null, 'do' => $sada - $this->uBroj($m['n'])];
        }
        if (preg_match('/\bnovij\w*\s+od\s+(?<n>\d{1,3}|' . $brojevi . ')\s+godin/u', $norm, $m)
            || preg_match('/\b(?:posledn\w+|zadn\w+|protekl\w+)\s+(?<n>\d{1,3}|' . $brojevi . ')\s+godin/u', $norm, $m)) {
            return ['od' => $sada - $this->uBroj($m['n']), 'do' => null];
        }

        // „poslednjih NEKOLIKO/par godina" (neodređen broj) → poslednjih ~5
        // godina (set16 p23: „objavljena u poslednjih nekoliko godina").
        if (preg_match('/\b(?:posledn\w+|zadn\w+|protekl\w+)\s+(?:nekolik\w*|par|nekol\w*)\s+godin/u', $norm)) {
            return ['od' => $sada - 5, 'do' => null];
        }

        return null;
    }

    /** Broj iz cifara ili reči („pet" → 5) — oblik iz BROJ_RECI rečnika. */
    private function uBroj(string $s): int
    {
        return ctype_digit($s) ? (int) $s : self::BROJ_RECI[$s];
    }

    /**
     * Iz pitanja izvlači NAZIV knjige koju je korisnik imenovao, ako postoji:
     *
     *  1. Tekst pod navodnicima bilo koje vrste („X", "X", 'X', »X«) —
     *     najpouzdaniji signal da korisnik imenuje knjigu.
     *  2. Tekst posle reči „knjigu/knjiga/knjige" do kraja pitanja, ali SAMO
     *     ako u originalu počinje velikim slovom („…knjigu Zločin i kazna") —
     *     bez toga bi „knjigu o programiranju" (tema!) bila proglašena naslovom.
     *  3. NESIGURNO imenovanje malim slovima — SAMO kada poruka sadrži marker
     *     nesigurnosti („mislim da se tako zove", „tako nekako", „…"): tada je
     *     i fragment posle „knjiga/knjigu" naslov-kandidat („a da li ima
     *     knjiga isl… mislim da se tako nekako zove"). Bez determinističke
     *     provere u bazi ovakvo pitanje je išlo modelu, koji je IZMIŠLJAO
     *     naslov kao „sličan" (ručno testiranje). Fragment koji počinje
     *     predlogom („o", „na temu"…) je tema, ne naslov — preskače se.
     *
     * Vraća ['prikaz' => oblik za ispis, 'norm' => za poređenje] ili null.
     * Odluku ŠTA je fraza (naslov/autor/žanr) donosi router proverom u bazi.
     */
    public function izvuciImenovanuKnjigu(string $raw): ?array
    {
        $latin = TextUtils::cyrillicToLatin(trim($raw));

        // 1) Navodnici — sadržaj između bilo koja dva znaka navoda.
        if (preg_match('/[„“”"»«\']([^„“”"»«\']{3,80})[„“”"»«\']/u', $latin, $m)) {
            $prikaz = trim($m[1]);
            return ['prikaz' => $prikaz, 'norm' => TextUtils::normalize($prikaz)];
        }

        // 2) „…knjigu Xxxx…" — samo ako naslov počinje VELIKIM slovom.
        //    Pokriven i dativ/instrumental („sličnu knjizi Xxxx", „knjigom Xxxx").
        if (preg_match('/\bknji(?:g(?:u|a|e|om)|zi)\s+([A-ZŠĐŽČĆ][^?.!]{2,80})/u', $latin, $m)) {
            $prikaz = trim($this->skiniRepneReci($m[1]));
            if (mb_strlen($prikaz) >= 3) {
                return ['prikaz' => $prikaz, 'norm' => TextUtils::normalize($prikaz)];
            }
        }

        // 3) Nesigurno imenovanje malim slovima — fragment do prve
        //    interpunkcije, uz obavezan marker nesigurnosti u poruci.
        $norm = TextUtils::normalize($latin);
        if (preg_match('/\.{2,}|…|mislim|tako\s+nekako|ne\s+znam\s+(tacno|kako)|nesto\s+tako|otprilike|valjda/u', $norm)
            && preg_match('/\bknjig(?:u|a|e)\s+([^?.!,…]{3,60})/iu', $latin, $m)) {
            // Marker nesigurnosti ume da stoji u istoj rečenici bez
            // interpunkcije („knjiga isl mislim da se tako zove") — odseca se.
            $fragment = preg_replace(
                '/\s+(mislim|tako\s+nekako|ne\s+znam|nesto\s+tako|otprilike|valjda).*$/iu',
                '',
                $m[1]
            ) ?? $m[1];
            $prikaz = trim($this->skiniRepneReci($fragment));

            $prikazNorm = TextUtils::normalize($prikaz);
            if (mb_strlen($prikaz) >= 3
                && !preg_match('/^(o|od|na\s+temu|o\s+temi|iz|za|koj\w*|nek\w*)\b/u', $prikazNorm)) {
                return ['prikaz' => $prikaz, 'norm' => $prikazNorm];
            }
        }

        return null;
    }

    /**
     * Iz pitanja izvlači frazu kojom je korisnik IMENOVAO AUTORA — samo
     * izričite formulacije „(od) autora X / pisca X" („od Ive Andrića" bez
     * reči „autora" rešava obična leksička pretraga po autoru).
     *
     * Repni deo o žanru/temi/GODINI se odseca („autora Danila Kiša iz žanra
     * pripovetke" → „Danila Kiša"; „autora Selenića posle 1986" → „Selenića")
     * — router zatim STROGO proverava da li autor postoji (sve reči imena
     * moraju pogoditi istog autora) i, ako je žanr ili godina naveden,
     * PRESEKOM sužava na knjige tog autora u tom žanru / opsegu godine
     * (nalaz B2.10: svi navedeni parametri moraju da se poklope).
     *
     * Vraća ['prikaz' => oblik za ispis, 'norm' => za poređenje] ili null.
     */
    public function izvuciAutorFrazu(string $raw): ?array
    {
        $latin = TextUtils::cyrillicToLatin(trim($raw));

        if (!preg_match('/\b(?:od\s+)?(?:autora|autorke|pisca|spisateljice)\s+([^?.!,;]{3,60})/iu', $latin, $m)) {
            return null;
        }

        // Odseci žanrovski/tematski/godišnji rep i repne reči pitanja
        // (godinu obrađuje godinaIzdanjaOpseg zasebno, pa ime autora ostaje čisto).
        $fraza = preg_replace(
            '/\s+(iz|u|na\s+temu|o\s+temi|za|posle|pre|nakon|izmedju|objavljen\w*|izdat\w*|stampan\w*)\s+.*$/iu',
            '',
            trim($m[1])
        ) ?? trim($m[1]);
        // Rezervno: goli četvorocifreni broj na kraju („… Selenića 1986").
        $fraza = preg_replace('/\s+(1[89]\d{2}|20\d{2})\s*$/u', '', $fraza) ?? $fraza;
        $fraza = $this->skiniRepneReci($fraza);

        // Fraza mora da liči na ime (počinje slovom, nije upitni nastavak niti
        // statistički rep „…imate najveći izbor knjiga").
        if (mb_strlen($fraza) < 3
            || preg_match('/^(koji|koja|koje|kog|ima|imate|imas|imamo|nudite|posedujete|drzite|je|su|se|li|da|ne|nam|ovog|ove|tog|te|najv\w*|najm\w*|najbolj\w*|najpopular\w*)\b/iu', $fraza)
            || preg_match('/\bnajv\w*\s+(izbor|broj)\b|\bnajvise\b/u', TextUtils::normalize($fraza))) {
            return null;
        }

        return ['prikaz' => $fraza, 'norm' => TextUtils::normalize($fraza)];
    }

    /**
     * HEURISTIČKI kandidat za autora iz upita BEZ reči „autora": ime posle
     * kataloške reči („knjige/dela/naslovi/romane/poeziju/nešto (od)") ili
     * predloga „od", zapisano VELIKIM početnim slovima („knjige Stevana
     * Sremca", „nešto od Crnjanskog", „romane Ive Andrića").
     *
     * Zahtev za velikim slovom čuva tematske upite („knjige o renesansi",
     * „knjige iz istorije") — mala slova posle okidača ne daju kandidata.
     * Router kandidata STROGO proverava u bazi (nadjiAutoraStrogo); nije autor
     * → normalan tok, pa lažni pogodak (npr. naslov) ne kvari odgovor.
     *
     * Vraća ['prikaz','norm'] ili null.
     */
    public function izvuciAutorKandidat(string $raw): ?array
    {
        $latin = TextUtils::cyrillicToLatin(trim($raw));

        if (!preg_match(
            '/\b(?:knjig\w*|dela?|naslov\w*|romane?|romani|poezij\w*|pesme|nesto|nešto|od)\s+(?:od\s+)?'
            . '([A-ZŠĐŽČĆ][a-zšđžčć]+(?:\s+[A-ZŠĐŽČĆ][a-zšđžčć]+){0,2})/u',
            $latin,
            $m
        )) {
            return null;
        }

        $prikaz = $this->skiniRepneReci(trim($m[1]));
        $norm   = TextUtils::normalize($prikaz);

        // Prva reč ne sme biti stop/žanrovska reč (npr. „Roman", „Priče" na
        // početku rečenice) — to nije ime autora već žanr/tema.
        $prviToken = TextUtils::tokenizuj($norm)[0] ?? '';
        if ($prviToken === ''
            || in_array($prviToken, self::STOP_RECI, true)
            || $this->jeZanrovskaRec($prviToken)) {
            return null;
        }

        return mb_strlen($prikaz) >= 3 ? ['prikaz' => $prikaz, 'norm' => $norm] : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // METAPODACI — statistika autora/godina, mesto, poređenje i relacije
    // (kandidati_ucenje_2.md) — sve rešava StrukturniUpiti, model se ne poziva
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * STATISTIKA O AUTORIMA po broju naslova (traži reč „autor/pisac"):
     *  - 'najvise': „koji autor ima najviše (različitih) naslova/knjiga",
     *  - 'vise_od_jedne': „autori koji imaju više od jedne knjige",
     *  - 'samo_jedna': „autori sa samo jednom knjigom".
     * Vraća null kada pitanje nije o autorskoj statistici.
     */
    /**
     * „Koji DOMAĆI / STRANI pisac ima najviše knjiga" (kandidati_ucenje_6 set13
     * p11) — autorska statistika filtrirana po kuriranoj podeli. Vraća
     * 'domaci' | 'strani' | null. Router je izvršava PRE opšte autorStatistike
     * (koja bi vratila apsolutno najzastupljenijeg, bez obzira na poreklo).
     */
    /**
     * Kvalifikator porekla u upitu (Potez 2): 'domaci' (domaći/naš/srpski) |
     * 'strani' (strani/inostrani/svetski) | null. Za filtriranje žanrovske
     * preporuke i sličnog po kuriranoj podeli pisaca.
     */
    public function podelaKlasa(string $norm): ?string
    {
        if (preg_match('/\bdomac\w*|\bnas\b|\bnasi\w*|\bsrpsk\w*/u', $norm)) {
            return 'domaci';
        }

        if (preg_match('/\bstran\w*|\binostran\w*|\bsvetsk\w*/u', $norm)) {
            return 'strani';
        }

        return null;
    }

    public function autorStatPodela(string $norm): ?string
    {
        if (!preg_match('/\bautor\w*|\bpisac\w*|\bspisatelj\w*/u', $norm)
            || !preg_match('/\bnajvise\b|\bnajveci\s+broj\b|\bnajvece\b/u', $norm)) {
            return null;
        }

        if (preg_match('/\bdomac\w*|\bnas\b|\bnasi\w*|\bsrpsk\w*/u', $norm)) {
            return 'domaci';
        }

        if (preg_match('/\bstran\w*|\binostran\w*|\bsvetsk\w*/u', $norm)) {
            return 'strani';
        }

        return null;
    }

    public function autorStatistika(string $norm): ?string
    {
        if (!preg_match('/\bautor\w*|\bpisac\w*|\bspisatelj\w*/u', $norm)) {
            return null;
        }

        if (preg_match('/\bvise\s+od\s+jedne\b|\bvise\s+od\s+1\b|\bviseknjiznih\b/u', $norm)) {
            return 'vise_od_jedne';
        }

        if (preg_match('/\bsamo\s+jedn\w*\b|\btacno\s+jedn\w*\b|\bjedn(u|om)\s+knjig\w*\s+(imaju|ima)|\bjedan\s+naslov\b/u', $norm)) {
            return 'samo_jedna';
        }

        if (preg_match('/\bnajvise\b|\bnajveci\s+broj\b|\bnajvece\b|\bnajveci\s+izbor\b|\bnajbogatij\w*\s+izbor\b/u', $norm)
            && preg_match('/naslov|knjig|del[oa]|izbor/u', $norm)) {
            return 'najvise';
        }

        return null;
    }

    /**
     * „Postoji li autor koji piše iz VIŠE RAZLIČITIH ŽANROVA / u raznim
     * žanrovima" (kandidati_ucenje_5, korpa A) — analitika raznovrsnosti autora.
     * Router vraća fiksni odgovor sa primerom iz kataloga (StrukturniUpiti).
     */
    public function jeAutorViseZanrova(string $norm): bool
    {
        return (bool) preg_match('/\bautor\w*|\bpisac\w*|\bspisatelj\w*/u', $norm)
            && (bool) preg_match(
                '/\bvise\s+(?:razlicit\w*\s+)?zanr|iz\s+vise\s+zanr|razlicit\w*\s+zanr'
                . '|razn\w*\s+zanr|iz\s+razn\w*\s+zanr/u',
                $norm
            );
    }

    /**
     * „Da li je knjiga X IKAD pozajmljena / zaduživana" (kandidati_ucenje_5,
     * korpa B) — pitanje o istoriji zaduživanja IMENOVANE knjige. Router
     * razrešava knjigu i vraća tačan broj iz arhive (0 → jasno kaže da nije).
     * Traži se marker istorije (ikad/ijednom/do sada), da se ne pomeša sa
     * pitanjem o trenutnoj dostupnosti.
     */
    public function jeIkadZaduzena(string $norm): bool
    {
        return (bool) preg_match(
            '/\b(ikad\w*|ijednom|nekad\w*|do\s+sada)\b.{0,40}\b(pozajm|zaduz|iznajm|izdava)/u',
            $norm
        )
        || (bool) preg_match(
            '/\b(pozajm|zaduz)\w*\b.{0,20}\b(ikad\w*|ijednom|do\s+sada)/u',
            $norm
        );
    }

    /**
     * „Koja je knjiga NAJMANJE pozajmljivana / najređe zaduživana"
     * (kandidati_ucenje_5, korpa B) — router vraća knjige sa najmanje (obično
     * nula) zaduženja iz baze.
     */
    public function jeNajmanjePozajmljivana(string $norm): bool
    {
        return (bool) preg_match('/\b(najmanje|najre[dđ]\w*)\b.{0,25}\b(pozajm|zaduz|citan|trazen)/u', $norm)
            || (bool) preg_match('/\b(pozajm|zaduz|citan)\w*\b.{0,15}\bnajmanje\b/u', $norm);
    }

    /**
     * „Preporuči (manje poznatu) knjigu koja JOŠ NIJE pozajmljena / ima 0
     * zaduženja" (kandidati_ucenje_5, korpa B) — preporuka nezaduživanih
     * naslova iz baze umesto generičke liste najčitanijih.
     */
    public function jeNepozajmljenaPreporuka(string $norm): bool
    {
        return (bool) preg_match('/\bpreporuc\w*|\bpredloz\w*/u', $norm)
            && (bool) preg_match(
                '/\b(jos\s+)?ni(je|su)\s+\w{0,6}\s*(pozajmlj|zaduzen|izdavan)'
                . '|\bnikad\w*\s+\w{0,6}\s*(pozajm|zaduz)|\bbez\s+(ijednog\s+)?zaduz|\b0\s+zaduz|\bnula\s+zaduz/u',
                $norm
            );
    }

    /**
     * AGREGACIJA ZADUŽENJA PO AUTORU / ŽANRU (kandidati_ucenje_5, korpe C i D) —
     * analitika zaduženja grupisana po primarnoj osi (autor ili žanr). Vraća
     * ['grupa' => 'autor'|'zanr', 'metrika' => 'avg'|'sum'|'sve'|'nijedna'|'bez']
     * ili null kada pitanje nije te vrste.
     *
     * Namerno traži i pomen ZADUŽIVANJA i pomen GRUPE (autor/žanr) — bez toga
     * ostaju osnovne rute („koji autor ima najviše knjiga" = broj naslova, ne
     * zaduženja).
     *
     * @return array{grupa: string, metrika: string}|null
     */
    public function grupaZaduzenja(string $norm): ?array
    {
        if (!preg_match('/\bpozajm|\bzaduz|\bcitan/u', $norm)) {
            return null;
        }

        $autor = (bool) preg_match('/\bautor\w*|\bpisac\w*|\bspisatelj\w*/u', $norm);
        $zanr  = (bool) preg_match('/\bzanr\w*/u', $norm);

        if (!$autor && !$zanr) {
            return null;
        }

        $grupa = $autor ? 'autor' : 'zanr';

        $metrika = match (true) {
            (bool) preg_match('/\bprosec\w*|\bprosek\w*|\bu\s+proseku\b/u', $norm) => 'avg',
            (bool) preg_match('/\bcije\s+su\s+sve\b|\bsve\s+(su\s+)?(knjig\w*|del[oa]|naslov\w*)?\s*(pozajmlj|zaduzivan|zaduzen)/u', $norm) => 'sve',
            (bool) preg_match('/\bnijedn\w*|\bni\s+jedn\w*/u', $norm) => 'nijedna',
            (bool) preg_match('/\bnikad\w*/u', $norm) && (bool) preg_match('/\bnajvise\b|\bnajveci\b/u', $norm) => 'bez',
            (bool) preg_match('/\bnajvise\b|\bnajveci\b|\bnajvece\b|\bukupno\b/u', $norm) => 'sum',
            default => null,
        };

        return $metrika === null ? null : ['grupa' => $grupa, 'metrika' => $metrika];
    }

    /**
     * „U kojoj godini imate najviše (izdatih) knjiga" — godina sa najvećim
     * brojem naslova. Mora da pominje i „godin" i „najviše", uz kata/knjige.
     */
    public function jeGodinaNajvise(string $norm): bool
    {
        return (bool) preg_match('/\bgodin\w*/u', $norm)
            && (bool) preg_match('/\bnajvise\b|\bnajveci\s+broj\b/u', $norm)
            && (bool) preg_match('/knjig|naslov|izdat|izdan/u', $norm);
    }

    /**
     * „Koja je najstarija / najnovija knjiga u katalogu" — ekstrem po godini
     * izdanja. „najstarija" → 'najstarija'; „najnovija/najskorija" po godini →
     * 'najnovija'. Mora da pominje knjigu/izdanje/naslov da se ne pomeša sa
     * npr. „najnoviji član".
     */
    public function ekstremKnjiga(string $norm): ?string
    {
        if (!preg_match('/\bknjig\w*|\bizdanj\w*|\bnaslov\w*|\bprimer\w*/u', $norm)) {
            return null;
        }

        if (preg_match('/\bnajstarij\w*|\bnajstarij[ae]\s+izdanj/u', $norm)) {
            return 'najstarija';
        }

        if (preg_match('/\bnajnovij\w*|\bnajskorij\w*|\bnajmladj\w*/u', $norm)) {
            return 'najnovija';
        }

        return null;
    }

    /**
     * Iz pitanja izvlači SVE imenovane knjige (samo iz navodnika — najpouzdaniji
     * signal za poređenje dve knjige i relacione upite). Redom pojavljivanja.
     *
     * @return array<int, array{prikaz: string, norm: string}>
     */
    public function izvuciSveImenovaneKnjige(string $raw): array
    {
        $latin = TextUtils::cyrillicToLatin(trim($raw));

        if (!preg_match_all('/[„“”"»«\']([^„“”"»«\']{3,90})[„“”"»«\']/u', $latin, $m)) {
            return [];
        }

        $rezultat = [];
        foreach ($m[1] as $sadrzaj) {
            $prikaz = trim($sadrzaj);
            if (mb_strlen($prikaz) >= 3) {
                $rezultat[] = ['prikaz' => $prikaz, 'norm' => TextUtils::normalize($prikaz)];
            }
        }

        return $rezultat;
    }

    /**
     * POREĐENJE dve imenovane knjige („koja je starija, X ili Y"): kriterijum
     * je 'starija' | 'novija' (godina), 'izdavac' ili 'mesto'. Poziva se samo
     * kada su imenovane bar dve knjige.
     */
    public function poredjenjeKriterijum(string $norm): ?string
    {
        return match (true) {
            // POPULARNOST (korpa B) — „koja je popularnija/čitanija, X ili Y" —
            // poređenje dve imenovane knjige po broju zaduženja.
            (bool) preg_match('/\bpopularn\w*|\bcitanij\w*|\btrazenij\w*|\bpozajmljivanij\w*|\bzaduzivanij\w*/u', $norm) => 'popularnost',
            (bool) preg_match('/\bvan\s+\w+/u', $norm)                          => 'van_mesta',
            (bool) preg_match('/\bstarij\w*|\branij\w*|\bpre\s+objavljen/u', $norm) => 'starija',
            (bool) preg_match('/\bnovij\w*|\bmladj\w*|\bskorij\w*|\bkasnij\w*/u', $norm) => 'novija',
            (bool) preg_match('/\bist\w*\s+izdavac\w*|\bizdavac\w*/u', $norm)    => 'izdavac',
            (bool) preg_match('/\bobjavljen\w*\s+u\b|\bizdat\w*\s+u\b|\bmest\w*/u', $norm) => 'mesto',
            default                                                             => null,
        };
    }

    /**
     * RELACIONI upit „iste osobine kao knjiga X" (jedna imenovana referenca):
     *  - 'izdavac': „istog izdavača kao X" / „isti izdavač kao X",
     *  - 'godina':  „istu godinu izdanja kao X" / „iste godine kao X",
     *  - 'autor':   „istog autora kao X" ili „(još knjiga) autora knjige X".
     * Null kada nije relacioni upit.
     */
    public function relacioniKriterijum(string $norm): ?string
    {
        return match (true) {
            (bool) preg_match('/\bist\w*\s+izdavac\w*\s+kao\b|\bizdavac\w*\s+kao\b/u', $norm) => 'izdavac',
            (bool) preg_match('/\bist\w*\s+godin\w*.{0,15}\bkao\b|\biste\s+godine\s+kao\b|\bobjavljen\w*\s+ist\w*\s+godin/u', $norm) => 'godina',
            // „istog autora kao X", „autora knjige X", ali i ZAMENIČKI oblik
            // uz imenovanu knjigu: „još knjigu istog/tog/ovog/njegovog autora"
            // (kandidati_ucenje_3 #36 p2/p21/p26, #39 p1/p5/p23).
            (bool) preg_match(
                '/\bist\w*\s+autor\w*|\bautor\w*\s+knjige\b'
                . '|\b(tog|ovog|njegov\w*|njen\w*)\s+autor\w*/u',
                $norm
            ) => 'autor',
            default => null,
        };
    }

    /**
     * „Još neku knjigu [autora]" — traži OSTALE knjige istog autora. Prepoznaje
     * „još neku/jednu/koju knjigu", „još neki naslov", „drugu knjigu", „još
     * knjiga", uz mogući marker „pročitao sam X, imate li još…".
     */
    public function jeJosKnjiga(string $norm): bool
    {
        return (bool) preg_match(
            '/\bjos\s+(nek\w*|jedn\w*|koj\w*|knjig\w*|naslov\w*|del[oa]|imate|ima|postoji)'
            . '|\bdrug[uae]\s+(knjig\w*|naslov\w*|del[oa])'
            . '|\bimate\s+li\s+jos\b|\bsta\s+(jos|drugo)\b'
            . '|\bjos\b.{0,20}\b(od\s+\w+|ovog\s+autor|tog\s+autor|istog\s+autor)/u',
            $norm
        );
    }

    /**
     * Iz pitanja izvlači imenovano MESTO izdavanja („izdate u Beogradu",
     * „objavljene u Nišu"): posle glagola izdanja + „u" uzima grad do
     * interpunkcije/godine/repne reči. Vraća ['prikaz','norm'] ili null.
     */
    public function izvuciMestoFrazu(string $raw): ?array
    {
        $latin = TextUtils::cyrillicToLatin(trim($raw));

        if (!preg_match(
            '/\b(?:izdat\w*|objavljen\w*|stampan\w*|izasl\w*|izdan\w*)\s+u\s+([a-zšđžčćA-ZŠĐŽČĆ][^?.!,;0-9]{1,30})/u',
            $latin,
            $m
        )) {
            return null;
        }

        // Odseci repni deo (godina, „tokom/za vreme", repne reči pitanja).
        $fraza = preg_replace(
            '/\s+(tokom|za\s+vreme|u\s+toku|godin\w*|prosle|ove|te|iste|kao|ili|a\s+koja).*$/iu',
            '',
            trim($m[1])
        ) ?? trim($m[1]);
        $fraza = $this->skiniRepneReci($fraza);

        $norm = TextUtils::normalize($fraza);

        // Grad ne sme biti „katalogu/biblioteci/ponudi", vremenski izraz
        // („poslednjih nekoliko godina") ni upitna reč.
        if (mb_strlen($fraza) < 3
            || preg_match('/^(katalog\w*|biblioteci|biblioteka|ponudi|fondu|koj\w*|kom|kome'
                . '|posledn\w*|zadn\w*|protekl\w*|nekolik\w*|par|nekol\w*)\b/u', $norm)
            || preg_match('/\bgodin/u', $norm)) {
            return null;
        }

        return ['prikaz' => trim($fraza), 'norm' => $norm];
    }

    /**
     * SADRŽAJNO / SUBJEKTIVNO pitanje o knjizi („koja knjiga govori o ratu",
     * „koja je najtužnija", „glavni junak lekar", „najbolja za početnike") —
     * odgovor traži OPIS/sadržaj knjige, koji katalog nema. Router na ovakva
     * pitanja vraća poštenu poruku umesto da izmišlja naslov ili nudi žanrove
     * (kandidati_ucenje_3, pitanja_set10).
     *
     * Isključuje STRUKTURNE atribute (godina, izdavač, primerci, žanr…) — njih
     * rešavaju druge rute; ovde je samo tematika/utisak/radnja/likovi.
     */
    public function jeSadrzajnoPitanje(string $norm): bool
    {
        // Mora se odnositi na knjigu/roman/delo.
        if (!preg_match('/\bknjig\w*|\broman\w*|\bnaslov\w*|\bdel[oa]\b|\bstivo\b/u', $norm)) {
            return false;
        }

        // Strukturni atributi imaju svoje rute — ne tretiraju se kao sadržajni.
        if (preg_match('/\bgodin\w*|\bizdavac\w*|\bizdat\w*|\bobjavljen\w*|\bprimer\w*'
            . '|\bslobodn\w*|\bdostupn\w*|\bzanr\w*|\bstranic\w*|\bisbn\w*|\bmest\w*'
            . '|\bnajstarij\w*|\bnajnovij\w*|\bnajvise\s+(knjig|primer|zaduz|naslov)/u', $norm)) {
            return false;
        }

        return (bool) preg_match(
            '/govori\s+o|prica\s+o|radi\s+se\s+o|opisuje|bavi\s+se|\bo\s+cemu\b'
            . '|glavn\w*\s+(lik|junak|junakinj)|\bjunak\w*|\blikov\w*|\bradnj\w*|\bzaplet\w*|\bobrt\w*|\bpreokret\w*|\bkraj\b'
            . '|inspirisan\w*|zasnovan\w*|istinit\w*|stvarn\w*\s+dogadjaj'
            . '|najsrecn\w*|najtuzn\w*|najuzbudljiv\w*|najzanimljiv\w*|najromanti\w*'
            . '|najstrasn\w*|najdosadn\w*|najlaks\w*|najtez\w*|najbolj\w*\s+za\s+pocetnik'
            . '|za\s+pocetnik|\bdijalog\w*|emotivn\w*|\bnapet\w*|\btuzn\w*|\bsrecn\w*'
            . '|\bo\s+(ljubav\w*|prijateljstv\w*|rat\w*|smrt\w*|zivot\w*|slobod\w*|osvet\w*|detinjstv\w*|porodic\w*)/u',
            $norm
        );
    }

    /**
     * „Postoji li nastavak (knjige X)" / „ima li nastavak" — traži se drugo
     * delo istog autora/serijala (kandidati_ucenje_3 #39 p3, #36 p29).
     */
    public function jeNastavakUpit(string $norm): bool
    {
        return (bool) preg_match('/\bnastavak\w*|\bnastavc\w*|\bnaredn\w*\s+deo|\bsledec\w*\s+deo/u', $norm);
    }

    /**
     * „Knjiga koja se ZAVRŠAVA / POČINJE rečju X" — pretraga po prvoj/poslednjoj
     * reči naslova (kandidati_ucenje_3 #39 p18). Vraća ['rec' => X, 'kraj' =>
     * bool] ili null.
     *
     * @return array{rec: string, kraj: bool}|null
     */
    public function izvuciRecNaslova(string $norm): ?array
    {
        $kraj    = (bool) preg_match('/\bzavrsava\w*|\bkraju\b|\bzadnj\w*\s+rec|\bposlednj\w*\s+rec/u', $norm);
        $pocetak = (bool) preg_match('/\bpocinj\w*\s+rec|\bkrec\w*\s+rec|\bprv\w*\s+rec\w*/u', $norm);
        // „…U NASLOVU/NAZIVU se nalazi / ima / sadrži reč X" — sadržavanje bilo
        // gde (ni početak ni kraj): obične reči („knjiga") model ne nalazi, pa
        // je i to deterministička ruta (set16 p14–p18, set17 p17/p22/p23).
        $sadrzi  = (bool) preg_match('/\bu\s+(?:svom\s+)?(?:naslov\w*|naziv\w*)|(?:naslov\w*|naziv\w*)\s+(?:ima|imaju|sadrz\w*|se\s+nalazi)/u', $norm);

        if (!$kraj && !$pocetak && !$sadrzi) {
            return null;
        }

        // Reč se navodi iza „rečju/reči/rečima X" ili pod navodnicima.
        if (!preg_match('/\brec(?:ju|i|ima|ju)?\b[^a-z0-9]*([a-z0-9]{2,})/u', $norm, $m)) {
            return null;
        }

        $pozicija = $kraj ? 'kraj' : ($pocetak ? 'pocetak' : 'sadrzi');

        return ['rec' => $m[1], 'pozicija' => $pozicija];
    }

    /**
     * „Koje knjige imaju BROJ (cifru) u naslovu" — set17 p18. Deterministička
     * pretraga naslova sa cifrom (npr. „Solunska 28", „Pripovetke 2").
     */
    public function jeBrojUNaslovu(string $norm): bool
    {
        return (bool) preg_match(
            '/\bbroj\w*\s+u\s+(?:svom\s+|njihov\w*\s+)?(?:naslov\w*|naziv\w*)'
            . '|(?:naslov\w*|naziv\w*)\s+(?:ima\w*|sadrz\w*)\s+broj'
            . '|\bcifr\w*\s+u\s+(?:naslov\w*|naziv\w*)/u',
            $norm
        );
    }

    /**
     * „Knjige koje imaju TAČNO N reči u naslovu" — set17 p19. Vraća broj reči
     * ili null. Reč se odnosi na naslov (mora se pomenuti „naslov/naziv").
     */
    public function brojReciNaslova(string $norm): ?int
    {
        if (!preg_match('/\bnaslov\w*|\bnaziv\w*/u', $norm)) {
            return null;
        }

        if (!preg_match('/\b(jedn\w*|dv\w*|tri|cetiri|cetvoro|pet|[1-9])\s+rec\w*\b/u', $norm, $m)) {
            return null;
        }

        $w = $m[1];
        if (is_numeric($w)) {
            return (int) $w;
        }

        foreach (['jedn' => 1, 'dv' => 2, 'tri' => 3, 'cetiri' => 4, 'cetvoro' => 4, 'pet' => 5] as $k => $v) {
            if (str_starts_with($w, $k)) {
                return $v;
            }
        }

        return null;
    }

    /**
     * „Autori čije PREZIME počinje slovom X" — set17 p20. Vraća malo slovo ili
     * null. Ne sme da se pobrka sa naslovom na slovo (pocinjeNaSlovo).
     */
    public function prezimeNaSlovo(string $norm): ?string
    {
        if (!preg_match('/\bprezim\w*/u', $norm)) {
            return null;
        }

        if (preg_match(
            '/\bprezim\w*\s+(?:koj\w*\s+)?(?:pocinj\w*\s+)?(?:na\s+slovo|sa\s+slovom|slovom|na)\s*[^a-z0-9]*([a-z])(?![a-z])/u',
            $norm,
            $m
        )) {
            return $m[1];
        }

        return null;
    }

    /**
     * „Postoje li DVE knjige istog autora objavljene iste godine" — analitičko
     * da/ne nad katalogom (kandidati_ucenje_3 #36 p15).
     */
    public function jeDveIsteGodine(string $norm): bool
    {
        return (bool) preg_match('/\b(dve|dvije)\s+knjig\w*/u', $norm)
            && (bool) preg_match('/\bist\w*\s+autor\w*/u', $norm)
            && (bool) preg_match('/\bist\w*\s+godin\w*|\biste\s+godine\b/u', $norm);
    }

    /**
     * Normalizovan tekst pitanja iz koga su UKLONJENI naslovi pod navodnicima
     * — za detekcije koje ne smeju da „čitaju" reči iz naslova (sadržajno
     * pitanje): u „pročitao sam ‘Solunska 28. Deo 2, o prijateljstvu i izdaji’,
     * imate li još knjigu Neleta Karajlića" fraza „o prijateljstvu" je deo
     * naslova, ne pitanje o radnji.
     */
    private function bezImenovanihNaslova(string $raw): string
    {
        $latin = TextUtils::cyrillicToLatin(trim($raw));
        $bez   = preg_replace('/[„“”"»«\'][^„“”"»«\']*[„“”"»«\']/u', ' ', $latin) ?? $latin;

        return TextUtils::normalize($bez);
    }

    /** Skida uobičajene repne reči pitanja iza naslova („… dostupna", „… kod vas"). */
    private function skiniRepneReci(string $fraza): string
    {
        return preg_replace(
            '/\s+(trenutno|dostupna|dostupan|dostupno|slobodna|slobodan|na stanju|kod vas|u katalogu|u biblioteci|za citanje|molim( vas)?)\s*$/iu',
            '',
            trim($fraza)
        ) ?? $fraza;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DATUMSKI OPSEG (preseljeno iz StrukturniUpiti)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Prepoznaje datumsko pitanje o zaduženjima („koje knjige su zadužene pre
     * nedelju dana / 2 dana / 2 nedelje…") i parsira relativni izraz u broj
     * dana unazad. „Pre N dana" znači „u POSLEDNJIH N dana" (od danas−N do
     * danas) — dogovoreno ponašanje, ne menjati.
     *
     * @param  bool  $zahtevajKontekst  false SAMO za nastavak razgovora čije
     *               je prethodno pitanje već bilo datumski upit nad arhivom
     *               („a za prošlu nedelju?" nema reč „zaduž…" ali nasleđuje
     *               kontekst) — tada se preskače provera arhivskih reči.
     *
     * @return array{dana: int}|array{preko_limita: true}|null
     *         null = pitanje nije datumski upit nad arhivom.
     */
    public function parsirajDatumskiOpseg(string $norm, bool $zahtevajKontekst = true): ?array
    {
        // Mora biti pitanje o zaduženjima/arhivi, ne bilo koje pominjanje vremena.
        if ($zahtevajKontekst && !preg_match('/zaduz|pozajmljen|izdat|izdavan|arhiv/u', $norm)) {
            return null;
        }

        // Lično pitanje („koje sam knjige zadužio…") rešava stanje naloga člana.
        if (preg_match('/\b(sam|moje|moja|mojih|meni|ja)\b/u', $norm)) {
            return null;
        }

        $brojevi  = implode('|', array_keys(self::BROJ_RECI));
        $jedinice = '(?<jed>dana?|nedelj[ueai]|meseci?|meseca|godin[ueai])';

        if (preg_match(
            '/\b(?:pre|unazad|tokom|u toku|posledn\w+|zadn\w+|protekl\w+|prosl\w+|prethodn\w+)\s+'
            . '(?:(?<broj>\d+|' . $brojevi . ')\s+)?' . $jedinice . '\b/u',
            $norm,
            $m
        )) {
            // GODINA IZDANJA, ne opseg unazad: „knjige izdate pre 2000 godine"
            // — kalendarska godina uz jedinicu „godine" nije „2000 godina
            // unazad" (ručno testiranje: takvo pitanje je dobijalo poruku o
            // limitu arhive). Kada arhivski signal dolazi samo od „izdat…",
            // pitanje rešava godinaIzdanjaOpseg; izričito „zaduž…" ostaje arhiva.
            if (str_starts_with($m['jed'], 'godin')
                && ($m['broj'] ?? '') !== ''
                && preg_match('/^(1[89]\d{2}|20\d{2})$/', (string) $m['broj'])
                && !preg_match('/zaduz|pozajmljen|arhiv/u', $norm)) {
                return null;
            }

            $broj = 1;
            if (($m['broj'] ?? '') !== '') {
                $broj = ctype_digit($m['broj']) ? (int) $m['broj'] : self::BROJ_RECI[$m['broj']];
            }

            $dana = $broj * match (true) {
                str_starts_with($m['jed'], 'dan')    => 1,
                str_starts_with($m['jed'], 'nedelj') => 7,
                str_starts_with($m['jed'], 'mesec')  => self::MAX_DANA,
                default                              => 366, // godina — uvek preko limita
            };

            return $dana > self::MAX_DANA ? ['preko_limita' => true] : ['dana' => $dana];
        }

        if (preg_match('/\bjuce\b/u', $norm)) {
            return ['dana' => 1];
        }

        if (preg_match('/\bdanas\b/u', $norm)) {
            return ['dana' => 0];
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // KONVERZACIJSKI KONTEKST — nastavci razgovora
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Da li je poruka očigledan NASTAVAK prethodnog pitanja: kratka je i
     * počinje veznikom ili sadrži pokaznu/anaforičnu referencu („a taj
     * autor?", „koje još?", „i ostale"). Signal je NAMERNO širok — koristi
     * se samo kao rezerva: da nastavak ne dobije odbijanje van teme i da
     * pretraga sme da se osloni na prethodnu razmenu.
     */
    private function jeNastavak(string $norm): bool
    {
        if (count(TextUtils::tokenizuj($norm)) > 8) {
            return false; // duga poruka je samostalno pitanje
        }

        return (bool) (
            preg_match('/^\s*(a|i|pa)\b/u', $norm)
            || preg_match('/\b(taj|ta|to|te|tog|toj|tom|tim|tih|ovih|onih|njega|njemu|njoj|nju|njen\w*|njegov\w*|njihov\w*|ist(i|a|o|e|og|oj)|takv\w*|jos|ostal\w*)\b/u', $norm)
        );
    }

    /**
     * Da li korisnik traži OSTATAK skraćenog spiska — prepoznaje se samo
     * kada je prethodni odgovor zaista bio skraćen (sadrži ponudu ostatka
     * iz StrukturniUpiti::saOgranicenjemListe).
     */
    private function trazenOstatak(string $norm, ?string $prethodniOdgovor): bool
    {
        if ($prethodniOdgovor === null
            || !str_contains(TextUtils::normalize($prethodniOdgovor), 'ostatak spiska')) {
            return false;
        }

        return (bool) preg_match('/\b(ostatak|ostale|ostalih|preostal\w*|nastavi|jos)\b/u', $norm);
    }

    /**
     * Normalizovan tekst poslednje DVE razmene (pitanja + odgovori) za
     * kombinovanu leksičku pretragu nastavka — odgovori su uključeni jer
     * referenca („taj autor") često pokazuje na ime koje je bot pomenuo, ne
     * korisnik. Dve razmene umesto jedne: referenca ume da preskoči jednu
     * razmenu unazad („a ona prva knjiga?" posle međupitanja) — dublje od
     * toga se namerno ne ide, da kontekst ne unese šum u pretragu.
     */
    private function kontekstIzIstorije(array $history): ?string
    {
        $delovi   = [];
        $pitanja  = 0;
        $odgovori = 0;

        // SAMO jedna razmena unazad (poslednje pitanje + poslednji odgovor) —
        // referenca dublja od jedne poruke se namerno ne prati (izričit zahtev:
        // bot čita jednu poruku unazad, ne 3–4).
        foreach (array_reverse($history) as $poruka) {
            $role    = $poruka['role'] ?? '';
            $content = (string) ($poruka['content'] ?? '');

            if ($content === '') {
                continue;
            }

            if ($role === 'user' && $pitanja < 1) {
                $delovi[] = TextUtils::normalize($content);
                $pitanja++;
            } elseif ($role === 'assistant' && $odgovori < 1) {
                $delovi[] = TextUtils::normalize($content);
                $odgovori++;
            }

            if ($pitanja >= 1 && $odgovori >= 1) {
                break;
            }
        }

        return empty($delovi) ? null : implode(' ', array_reverse($delovi));
    }

    /**
     * Poslednje korisničko pitanje i poslednji odgovor bota iz istorije.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function poslednjaRazmena(array $history): array
    {
        $pitanje = null;
        $odgovor = null;

        foreach (array_reverse($history) as $poruka) {
            $role = $poruka['role'] ?? '';
            if ($odgovor === null && $role === 'assistant') {
                $odgovor = (string) ($poruka['content'] ?? '');
            }
            if ($pitanje === null && $role === 'user') {
                $pitanje = (string) ($poruka['content'] ?? '');
            }
            if ($pitanje !== null && $odgovor !== null) {
                break;
            }
        }

        return [$pitanje, $odgovor];
    }
}