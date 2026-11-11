<?php

namespace App\Services\Ai;

use App\Models\Arhiva;
use App\Models\Knjiga;
use App\Models\Zanr;
use Illuminate\Support\Collection;

/**
 * StrukturniUpiti — HANDLER za STRUKTURNA pitanja (sloj 3a): agregacije,
 * brojanja, statistika učestalosti i datumski opsezi nad arhivom.
 *
 * Za ova pitanja se model uopšte ne poziva: broj i spisak se izračunaju
 * nad bazom i vrate kao FIKSNA poruka — model tako ne može da pogreši
 * broj, prećutno skrati listu ili izmisli stavku iz retrievanog teksta.
 *
 * PODELA ODGOVORNOSTI (ciljna arhitektura):
 *  - PREPOZNAVANJE ovih pitanja radi IntentClassifier (parsirajDatumskiOpseg
 *    i ostale detekcije su preseljene tamo),
 *  - SVE upite nad bazom izvršava LibraryDataService (ovde nema SQL/ORM),
 *  - ova klasa SASTAVLJA odgovor: formatiranje, srpska množina, linkovi.
 *
 * PRAVILO SPISKOVA: lista se nikada ne skraćuje prećutno — ako je duža od
 * MAX_STAVKI, ispisuje se ukupan broj, prvih MAX_STAVKI stavki i izričita
 * napomena koliko je stavki izostavljeno (saOgranicenjemListe). Na zahtev
 * „recite mi ostatak" (IntentClassifier::ostatakOd) isti handler vraća
 * OSTATAK spiska ($ostatak = true).
 */
class StrukturniUpiti
{
    /** Najviše stavki u jednom ispisanom spisku — ostatak uz izričitu napomenu. */
    private const MAX_STAVKI = 30;

    /**
     * Najviše knjiga u spisku ŽANRA/AUTORA (bib-izmene): do ovoliko se
     * ispisuju SVE; duži spisak ispisuje UKUPAN broj, prvih MAX_LISTA_KNJIGA
     * stavki („ovo su neke od njih") i ponudu ostatka.
     */
    private const MAX_LISTA_KNJIGA = 5;

    public function __construct(
        private readonly LibraryDataService $data,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // DATUMSKI OPSEG NAD ARHIVOM
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Kompletna lista knjiga zaduženih u datom opsegu (arhiva, tip Zaduzena),
     * grupisano po knjizi, sa brojem zaduženja i linkovima ka postojećim
     * knjigama (obrisana knjiga ostaje kao običan tekst iz snimka u arhivi).
     */
    public function odgovorZaduzeneUOpsegu(int $dana, bool $ostatak = false): string
    {
        $od = now()->startOfDay()->subDays($dana);

        $zaduzenja = $this->data->zaduzenjaOd($od);

        $period = $dana === 0 ? 'danas' : 'od ' . $od->format('d.m.Y.') . ' do danas';

        if ($zaduzenja->isEmpty()) {
            return "U periodu {$period} nije zabeleženo nijedno zaduženje.";
        }

        $postojece = $this->data->postojeceKnjigeIds(
            $zaduzenja->pluck('knjiga_id')->filter()->unique()->all()
        );

        $stavke = $zaduzenja
            ->groupBy(fn (Arhiva $a) => $a->knjiga_id ?? 'obrisana|' . $a->naziv_knjige)
            ->map(function (Collection $grupa) use ($postojece) {
                $prva   = $grupa->first();
                $naslov = ($prva->knjiga_id && in_array($prva->knjiga_id, $postojece, true))
                    ? "[{$prva->naziv_knjige}](/knjige/{$prva->knjiga_id})"
                    : (string) $prva->naziv_knjige;

                if (($prva->autor ?? '') !== '') {
                    $naslov .= " ({$prva->autor})";
                }

                return $grupa->count() > 1 ? "{$naslov} — {$grupa->count()} puta" : $naslov;
            })
            ->values();

        $ukupno = $zaduzenja->count();
        $uvod   = "U periodu {$period} zabeleženo je {$ukupno} "
            . ($ukupno === 1 ? 'zaduženje' : 'zaduženja')
            . " ({$stavke->count()} " . $this->recRazlicitihKnjiga($stavke->count()) . '): ';

        return $this->saOgranicenjemListe($uvod, $stavke, $ostatak);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STATISTIKA UČESTALOSTI ZADUŽENJA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * „Koliko često se zadužuju knjige / koliko se nedeljno zaduži" —
     * izračunato iz istorije u arhivi: ukupan broj zaduženja, period od prvog
     * do poslednjeg i prosek po nedelji, sa objašnjenjem perioda.
     */
    public function odgovorStatistikaZaduzenja(): string
    {
        $statistika = $this->data->statistikaZaduzenja();

        if ($statistika === null) {
            return 'Još uvek nemamo zabeleženih zaduženja, pa statistika učestalosti ne postoji.';
        }

        ['ukupno' => $ukupno, 'prvo' => $prvo, 'poslednje' => $poslednje] = $statistika;

        $nedelja = max(1, (int) ceil(($prvo->diffInDays($poslednje) + 1) / 7));
        $prosek  = str_replace('.', ',', (string) round($ukupno / $nedelja, 1));

        return "Od {$prvo->format('d.m.Y.')} do {$poslednje->format('d.m.Y.')} zabeleženo je ukupno "
            . "{$ukupno} " . ($ukupno === 1 ? 'zaduženje' : 'zaduženja')
            . ", što je u proseku oko {$prosek} zaduženja nedeljno.";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SPISAK ŽANRA — kompletna lista, bez skraćivanja
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nalazi POSTOJEĆI žanr čiji naziv odgovara frazi iz upita (fuzzy po
     * rečima naziva, ista pravila kao KatalogPretraga) — „prica" ≈ „Priče",
     * „naucna fantastika" ≈ „Naučna fantastika".
     */
    public function nadjiZanr(string $frazaNorm): ?Zanr
    {
        $frazaTokeni = $this->tokeniOdTriSlova(TextUtils::kanonizujZanrPrideve($frazaNorm));

        if (empty($frazaTokeni)) {
            return null;
        }

        $najbolji = null;
        $najskor  = 0.0;

        foreach ($this->data->sviZanrovi() as $zanr) {
            $zanrTokeni = $this->tokeniOdTriSlova(TextUtils::normalize($zanr->naziv));

            if (empty($zanrTokeni)) {
                continue;
            }

            $pogodjeno = 0;
            foreach ($zanrTokeni as $zt) {
                foreach ($frazaTokeni as $ft) {
                    if ($this->zanrTokenPogodak($ft, $zt)) {
                        $pogodjeno++;
                        break;
                    }
                }
            }

            $skor = $pogodjeno / count($zanrTokeni);

            if ($pogodjeno > 0 && $skor >= 0.5 && $skor > $najskor) {
                $najskor  = $skor;
                $najbolji = $zanr;
            }
        }

        return $najbolji;
    }

    /**
     * Knjige datog žanra (polje žanr, ne tema!) — linkovi sa autorima,
     * azbučnim redom, uz pravilo kratke liste (listaSaUkupnimBrojem):
     * do 5 knjiga sve, preko 5 ukupan broj + prvih 5 + ponuda ostatka.
     */
    public function odgovorZanrLista(Zanr $zanr, bool $ostatak = false): string
    {
        $knjige = $this->data->knjigeZanra($zanr);

        if ($knjige->isEmpty()) {
            return "U žanru {$zanr->naziv} trenutno nemamo nijednu knjigu.";
        }

        $stavke = $knjige->map(fn (Knjiga $k) => $this->linkKnjige($k))->values();

        return $this->listaSaUkupnimBrojem("U žanru {$zanr->naziv}", $stavke, $ostatak);
    }

    /**
     * „Ima li slobodnih [žanr] za pozajmljivanje" (Potez 2, set13 p20) — knjige
     * datog žanra koje TRENUTNO imaju slobodan primerak. Model se ne poziva.
     */
    public function odgovorZanrDostupnost(Zanr $zanr): string
    {
        $knjige = $this->data->knjigeZanraDostupnost($zanr);

        if ($knjige->isEmpty()) {
            return "U žanru {$zanr->naziv} trenutno nemamo nijednu knjigu.";
        }

        $dostupne = $knjige
            ->filter(fn (array $h) => (int) ($h['knjiga']->slobodni_primerci_count ?? 0) > 0)
            ->values();

        if ($dostupne->isEmpty()) {
            return "Trenutno nijedna knjiga iz žanra {$zanr->naziv} nema slobodan primerak — "
                . 'rezervacija je moguća kada se neki primerak oslobodi.';
        }

        $stavke = $dostupne->map(fn (array $h) => $this->linkKnjige($h['knjiga']))->values();

        return "Iz žanra {$zanr->naziv} trenutno " . ($stavke->count() === 1 ? 'je dostupna' : 'su dostupne')
            . ': ' . $stavke->implode(', ') . '.';
    }

    /**
     * ŽANR VAN FONDA („imate li horor", „nešto od naučne fantastike",
     * „avanturistički roman") — korisnik je imenovao žanr koga u katalogu
     * nema: POŠTEN fiksni odgovor sa spiskom postojećih žanrova, BEZ
     * preporuka iz nesrodnih žanrova (ranije je kaskada „srodnog žanra"
     * za horor nudila romane — ručno testiranje 2026-07-10).
     */
    public function odgovorZanrVanFonda(): string
    {
        $zanrovi = $this->data->zanroviSaKnjigama();

        $poruka = 'Trenutno nemamo knjige iz tog žanra u katalogu. '
            . 'Da li vas možda interesuje neki drugi žanr?';

        if ($zanrovi->isEmpty()) {
            return $poruka;
        }

        return $poruka . ' U ponudi ' . ($zanrovi->count() === 1 ? 'je žanr' : 'su žanrovi')
            . ': ' . $zanrovi->implode(', ') . '.';
    }

    /**
     * Knjige IMENOVANOG AUTORA („koje knjige imate od autora X"), po potrebi
     * sužene na žanr (presek autor ∩ žanr, nalaz B2.10) i/ili GODINU IZDANJA
     * — FIKSNI odgovor sa pravilom kratke liste: do 5 knjiga sve, preko 5
     * ukupan broj + prvih 5 („ovo su neke od njih") + ponuda ostatka.
     *
     * @param  string  $autorPrikaz  fraza kojom je korisnik imenovao autora
     * @param  Collection<int, int>  $knjigaIds  ID-jevi knjiga autora (posle preseka)
     * @param  ?array{od: ?int, do: ?int}  $godinaOpseg  presečen opseg godine izdanja
     */
    public function odgovorAutorLista(string $autorPrikaz, Collection $knjigaIds, ?Zanr $zanr = null, bool $ostatak = false, ?array $godinaOpseg = null, ?string $mestoPrikaz = null): string
    {
        $knjige = $this->data->knjigeAzbucno($knjigaIds->all());
        $stavke = $knjige->map(fn (Knjiga $k) => $this->linkKnjige($k))->values();

        // Rod iz KANONSKOG imena (nominativ iz baze) — prikaz je genitiv, pa se
        // rod iz njega ne vidi. „autora" → „autorke" za spisateljicu.
        $autorRec = ($k0 = $knjige->first()) !== null && TextUtils::jeZenskoIme((string) $k0->autor)
            ? 'autorke' : 'autora';

        $opis = $zanr !== null ? " iz žanra {$zanr->naziv}" : '';
        if ($godinaOpseg !== null) {
            $opis .= ' ' . $this->opisGodina($godinaOpseg);
        }
        if ($mestoPrikaz !== null) {
            $opis .= ' objavljene ' . $this->uMestu($mestoPrikaz);
        }

        if ($stavke->isEmpty()) {
            return $opis === ''
                ? "Trenutno nemamo knjige {$autorRec} {$autorPrikaz} u katalogu."
                : "Od {$autorRec} {$autorPrikaz} trenutno nemamo knjige{$opis}.";
        }

        return $this->listaSaUkupnimBrojem("Od {$autorRec} {$autorPrikaz}{$opis}", $stavke, $ostatak);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IZDAVAČI — knjige imenovanog izdavača + statistika (model se ne poziva)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nalazi knjige čiji IZDAVAČ odgovara frazi iz upita: SVAKA reč fraze
     * mora da pogodi token naziva izdavača (fuzzy za padeže/tipfelere —
     * „Lagune" ≈ „Laguna" — ili prefiks ≥ 4). Vraća kanonski naziv (najčešći
     * tačan upis iz baze) i ID-jeve knjiga, ili null kada izdavača nema.
     *
     * @return array{prikaz: string, ids: Collection<int, int>}|null
     */
    public function nadjiIzdavaca(string $frazaNorm): ?array
    {
        $frazaTokeni = $this->tokeniOdTriSlova($frazaNorm);

        if (empty($frazaTokeni)) {
            return null;
        }

        // Ime izdavača je VLASTITO ime: razlika od jednog slova je DRUGI izdavač
        // („Nolit" ≠ „Nolot", „Antiprint media" ≠ „Artprint media"). Zato prvo
        // tražimo TAČNO poklapanje tokena (razlika samo u velikom slovu/dijakritiku
        // se skida normalizacijom, pa „NOLIT" = „Nolit"), a fuzzy koristimo TEK
        // ako tačnog poklapanja uopšte nema (npr. tipfeler u samom upitu).
        $tacniIds = [];   $tacniNaz = [];
        $fuzzyIds = [];   $fuzzyNaz = [];

        foreach ($this->data->knjigeSaIzdavacem() as $knjiga) {
            $izdavacTokeni = $this->tokeniOdTriSlova(TextUtils::normalize((string) $knjiga->izdavac));

            if (empty($izdavacTokeni)) {
                continue;
            }

            $sveTacno = true;
            $sveFuzzy = true;
            foreach ($frazaTokeni as $ft) {
                if (!in_array($ft, $izdavacTokeni, true)) {
                    $sveTacno = false;
                }
                // Fuzzy-rezerva SME da toleriše samo padežni nastavak („Lagune"
                // ↔ „Laguna"), a NE izmenu u sredini reči („antiprint" ↔
                // „artprint" ostaju različiti izdavači).
                $pogodak = false;
                foreach ($izdavacTokeni as $it) {
                    if ($ft === $it || $this->istiKorenPadez($ft, $it)) {
                        $pogodak = true;
                        break;
                    }
                }
                if (!$pogodak) {
                    $sveFuzzy = false;
                }
            }

            if ($sveTacno) {
                $tacniIds[] = $knjiga->id;
                $tacniNaz[(string) $knjiga->izdavac] = ($tacniNaz[(string) $knjiga->izdavac] ?? 0) + 1;
            } elseif ($sveFuzzy) {
                $fuzzyIds[] = $knjiga->id;
                $fuzzyNaz[(string) $knjiga->izdavac] = ($fuzzyNaz[(string) $knjiga->izdavac] ?? 0) + 1;
            }
        }

        [$ids, $nazivi] = !empty($tacniIds) ? [$tacniIds, $tacniNaz] : [$fuzzyIds, $fuzzyNaz];

        if (empty($ids)) {
            return null;
        }

        arsort($nazivi);

        return ['prikaz' => (string) array_key_first($nazivi), 'ids' => collect($ids)];
    }

    /**
     * Knjige IMENOVANOG IZDAVAČA („da li imate knjige izdavača Laguna"), po
     * potrebi sužene PRESEKOM na žanr i/ili godinu izdanja (svi navedeni
     * parametri moraju da se poklope) — pravilo kratke liste. Nepostojeći
     * izdavač → pošten odgovor sa spiskom izdavača koje imamo.
     *
     * @param  ?array{od: ?int, do: ?int}  $godinaOpseg
     */
    public function odgovorIzdavacLista(
        string $frazaNorm,
        string $frazaPrikaz,
        ?Zanr $zanr = null,
        ?array $godinaOpseg = null,
        bool $ostatak = false,
    ): string {
        $izdavac = $this->nadjiIzdavaca($frazaNorm);

        if ($izdavac === null) {
            $svi = $this->data->izdavaciSaBrojemKnjiga()->pluck('izdavac');

            $poruka = "Trenutno nemamo knjige izdavača „{$frazaPrikaz}” u katalogu.";

            if ($svi->isEmpty()) {
                return $poruka;
            }

            $prikaz = $svi->take(15)->implode(', ');
            $vise   = $svi->count() > 15 ? ' i još ' . ($svi->count() - 15) : '';

            return "{$poruka} Izdavači čije knjige imamo: {$prikaz}{$vise}.";
        }

        $ids  = $izdavac['ids'];
        $opis = '';

        if ($zanr !== null) {
            $ids  = $ids->intersect($this->data->knjigeZanra($zanr)->pluck('id'))->values();
            $opis = " iz žanra {$zanr->naziv}";
        }

        if ($godinaOpseg !== null) {
            $uGodini = collect($this->data->idsUOpseguGodina(
                $godinaOpseg['od'] ?? null,
                $godinaOpseg['do'] ?? null
            )['ids']);
            $ids   = $ids->intersect($uGodini)->values();
            $opis .= ' ' . $this->opisGodina($godinaOpseg);
        }

        if ($ids->isEmpty()) {
            return "Od izdavača {$izdavac['prikaz']} trenutno nemamo knjige{$opis}.";
        }

        $stavke = $this->data->knjigeAzbucno($ids->all())
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        return $this->listaSaUkupnimBrojem("Od izdavača {$izdavac['prikaz']}{$opis}", $stavke, $ostatak);
    }

    /**
     * STATISTIKA O IZDAVAČIMA — oblici iz jednog handlera:
     *  - 'najvise_knjiga': izdavač sa najviše knjiga u katalogu (+ sledeća dva),
     *  - 'najpopularniji': izdavač sa najviše ZADUŽIVANJA (arhiva); bez
     *    zaduženja pošten odgovor + najzastupljeniji po broju knjiga,
     *  - 'vise_od_jedne': spisak samo izdavača sa VIŠE od jedne knjige, bez
     *    napomene o neupisanom izdavaču (kandidati_ucenje_2 #34 p15),
     *  - 'lista': spisak svih izdavača sa brojem knjiga + napomena za knjige
     *    bez upisanog izdavača.
     */
    public function odgovorIzdavacStatistika(string $vrsta, bool $ostatak = false): string
    {
        $izdavaci = $this->data->izdavaciSaBrojemKnjiga();

        if ($izdavaci->isEmpty()) {
            return 'Za knjige u katalogu izdavač trenutno nije upisan, pa podatke o izdavačima nemam.';
        }

        // „Koji izdavači imaju više od jedne knjige" — filter na broj > 1, bez
        // repne napomene o neupisanom izdavaču (ona ovde nije relevantna).
        if ($vrsta === 'vise_od_jedne') {
            $viseod1 = $izdavaci->filter(fn ($i) => (int) $i->broj > 1)->values();

            if ($viseod1->isEmpty()) {
                return 'Nijedan izdavač trenutno nema više od jedne knjige u katalogu.';
            }

            $stavke = $viseod1
                ->map(fn ($i) => "{$i->izdavac} ({$this->brojKnjiga((int) $i->broj)})")
                ->values();

            $uvod = $stavke->count() === 1
                ? 'Više od jedne knjige u katalogu ima jedan izdavač: '
                : "Više od jedne knjige u katalogu ima {$stavke->count()} izdavača: ";

            return $this->saOgranicenjemListe($uvod, $stavke, $ostatak);
        }

        if ($vrsta === 'najvise_knjiga') {
            $prvi   = $izdavaci->first();
            $poruka = "Najviše knjiga u katalogu je od izdavača {$prvi->izdavac} — "
                . $this->brojKnjiga((int) $prvi->broj) . '.';

            $sledeci = $izdavaci->slice(1, 2)
                ->map(fn ($i) => "{$i->izdavac} ({$this->brojKnjiga((int) $i->broj)})")
                ->implode(', ');

            return $sledeci === '' ? $poruka : "{$poruka} Slede: {$sledeci}.";
        }

        if ($vrsta === 'najpopularniji') {
            $pop = $this->data->najpopularnijiIzdavac();

            if ($pop === null) {
                $prvi = $izdavaci->first();

                return 'Još uvek nemamo zabeleženih zaduženja, pa se popularnost izdavača ne može '
                    . "izračunati. Najzastupljeniji izdavač po broju knjiga je {$prvi->izdavac} "
                    . "({$this->brojKnjiga((int) $prvi->broj)}).";
            }

            return "Najpopularniji izdavač u katalogu je {$pop['izdavac']} — knjige ovog izdavača "
                . 'su do sada zaduživane ' . $this->brojPuta($pop['zaduzenja']) . '.';
        }

        // 'lista' — spisak izdavača sa brojem knjiga, pravilo spiskova.
        $stavke = $izdavaci
            ->map(fn ($i) => "{$i->izdavac} ({$this->brojKnjiga((int) $i->broj)})")
            ->values();

        $uvod = $stavke->count() === 1
            ? 'U katalogu imamo knjige jednog izdavača: '
            : "U katalogu imamo knjige {$stavke->count()} izdavača: ";

        $bez      = $this->data->brojKnjigaBezIzdavaca();
        $napomena = $bez > 0 && !$ostatak
            ? " Napomena: za {$bez} " . $this->recRazlicitihKnjiga($bez) . ' izdavač nije upisan.'
            : '';

        return $this->saOgranicenjemListe($uvod, $stavke, $ostatak) . $napomena;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MESTO IZDAVANJA — knjige po gradu (model se ne poziva)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nalazi knjige čije MESTO izdavanja odgovara frazi iz upita: SVAKA reč
     * fraze mora da pogodi token naziva mesta (fuzzy za padeže/tipfelere —
     * „nisu" ≈ „Niš"? ne — pa i prefiks ≥ 4). Vraća kanonski naziv (najčešći
     * upis) i ID-jeve knjiga, ili null kada mesta nema.
     *
     * @return array{prikaz: string, ids: Collection<int, int>}|null
     */
    public function nadjiMesto(string $frazaNorm): ?array
    {
        $frazaTokeni = $this->tokeniOdTriSlova($frazaNorm);

        if (empty($frazaTokeni)) {
            return null;
        }

        $ids    = [];
        $nazivi = [];

        foreach ($this->data->knjigeSaMestom() as $knjiga) {
            $mestoTokeni = $this->tokeniOdTriSlova(TextUtils::normalize((string) $knjiga->mesto_izdavanja));

            if (empty($mestoTokeni)) {
                continue;
            }

            $sveReci = true;
            foreach ($frazaTokeni as $ft) {
                $pogodak = false;
                foreach ($mestoTokeni as $mt) {
                    if ($this->mestoTokenPogodak($ft, $mt)) {
                        $pogodak = true;
                        break;
                    }
                }
                if (!$pogodak) {
                    $sveReci = false;
                    break;
                }
            }

            if ($sveReci) {
                $ids[] = $knjiga->id;
                $nazivi[(string) $knjiga->mesto_izdavanja] = ($nazivi[(string) $knjiga->mesto_izdavanja] ?? 0) + 1;
            }
        }

        if (empty($ids)) {
            return null;
        }

        arsort($nazivi);

        return ['prikaz' => (string) array_key_first($nazivi), 'ids' => collect($ids)];
    }

    /**
     * „Koje knjige su izdate u Beogradu" (+ eventualna godina) — FIKSNA lista
     * knjiga po mestu izdavanja, pravilo kratke liste (kandidati_ucenje_2 #34
     * p12/p13, #34 p19). Nepostojeće mesto → pošten odgovor.
     *
     * @param  ?array{od: ?int, do: ?int}  $godinaOpseg
     */
    public function odgovorMestoLista(
        string $frazaNorm,
        string $frazaPrikaz,
        ?array $godinaOpseg = null,
        bool $ostatak = false,
    ): string {
        $mesto = $this->nadjiMesto($frazaNorm);

        if ($mesto === null) {
            return "Trenutno nemamo knjige izdate u mestu „{$frazaPrikaz}” u katalogu.";
        }

        $ids  = $mesto['ids'];
        $opis = '';

        if ($godinaOpseg !== null) {
            $uGodini = collect($this->data->idsUOpseguGodina(
                $godinaOpseg['od'] ?? null,
                $godinaOpseg['do'] ?? null
            )['ids']);
            $ids   = $ids->intersect($uGodini)->values();
            $opis  = ' ' . $this->opisGodina($godinaOpseg);
        }

        if ($ids->isEmpty()) {
            return ucfirst($this->uMestu($mesto['prikaz'])) . " trenutno nemamo knjige{$opis}.";
        }

        $stavke = $this->data->knjigeAzbucno($ids->all())
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        return $this->listaSaUkupnimBrojem('Izdate ' . $this->uMestu($mesto['prikaz']) . $opis, $stavke, $ostatak);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STATISTIKA AUTORA I GODINA — brojevi iz baze, model se ne poziva
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * STATISTIKA O AUTORIMA po broju RAZLIČITIH naslova:
     *  - 'najvise': autor sa najviše knjiga u katalogu (+ sledeća dva),
     *  - 'vise_od_jedne': spisak autora sa više od jedne knjige,
     *  - 'samo_jedna': spisak autora sa tačno jednom knjigom.
     * (kandidati_ucenje_2 #34 p2/p3, #35 p7/p10)
     */
    public function odgovorAutorStatistika(string $vrsta, bool $ostatak = false): string
    {
        $autori = $this->data->autoriPoBrojuNaslova();

        if ($autori->isEmpty()) {
            return 'Za knjige u katalogu autor trenutno nije upisan, pa podatke o autorima nemam.';
        }

        if ($vrsta === 'najvise') {
            $prvi   = $autori->first();
            $poruka = "Najviše naslova u katalogu ima {$prvi->autor} — "
                . $this->brojKnjiga((int) $prvi->broj) . '.';

            $sledeci = $autori->slice(1, 2)
                ->filter(fn ($a) => (int) $a->broj > 1)
                ->map(fn ($a) => "{$a->autor} ({$this->brojKnjiga((int) $a->broj)})")
                ->implode(', ');

            return $sledeci === '' ? $poruka : "{$poruka} Slede: {$sledeci}.";
        }

        if ($vrsta === 'vise_od_jedne') {
            $viseod1 = $autori->filter(fn ($a) => (int) $a->broj > 1)->values();

            if ($viseod1->isEmpty()) {
                return 'Trenutno nijedan autor nema više od jedne knjige u katalogu.';
            }

            $stavke = $viseod1
                ->map(fn ($a) => "{$a->autor} ({$this->brojKnjiga((int) $a->broj)})")
                ->values();

            $uvod = $stavke->count() === 1
                ? 'Više od jedne knjige u katalogu ima jedan autor: '
                : "Više od jedne knjige u katalogu ima {$stavke->count()} autora: ";

            return $this->saOgranicenjemListe($uvod, $stavke, $ostatak);
        }

        // 'samo_jedna' — autori sa tačno jednim naslovom.
        $samo1 = $autori->filter(fn ($a) => (int) $a->broj === 1)->pluck('autor')->sort()->values();

        if ($samo1->isEmpty()) {
            return 'Trenutno svaki autor u katalogu ima više od jedne knjige.';
        }

        $uvod = $samo1->count() === 1
            ? 'Samo jednu knjigu u katalogu ima jedan autor: '
            : "Samo jednu knjigu u katalogu ima {$samo1->count()} autora: ";

        return $this->saOgranicenjemListe($uvod, $samo1, $ostatak);
    }

    /**
     * „Koji autor ima najviše knjiga iz žanra Z" (korpa A) — rangiranje autora
     * PO BROJU naslova UNUTAR datog žanra (+ sledeća dva). Model se ne poziva.
     * Žanr koga u fondu nema router hvata ranije (odgovorZanrVanFonda).
     */
    public function odgovorAutorNajviseUZanru(Zanr $zanr): string
    {
        $autori = $this->data->autoriPoBrojuNaslovaUZanru($zanr);

        if ($autori->isEmpty()) {
            return "U žanru {$zanr->naziv} trenutno nemamo nijednu knjigu sa upisanim autorom.";
        }

        $prvi   = $autori->first();
        $poruka = "Najviše knjiga iz žanra {$zanr->naziv} ima {$prvi->autor} — "
            . $this->brojKnjiga((int) $prvi->broj) . '.';

        $sledeci = $autori->slice(1, 2)
            ->filter(fn ($a) => (int) $a->broj > 1)
            ->map(fn ($a) => "{$a->autor} ({$this->brojKnjiga((int) $a->broj)})")
            ->implode(', ');

        return $sledeci === '' ? $poruka : "{$poruka} Slede: {$sledeci}.";
    }

    /**
     * „Postoji li autor koji piše iz više različitih žanrova" (korpa A) —
     * analitičko da/ne sa primerom iz kataloga: autor sa najviše različitih
     * žanrova i spisak tih žanrova. Model se ne poziva.
     */
    public function odgovorAutorViseZanrova(): string
    {
        $r = $this->data->autorSaNajviseZanrova();

        if ($r === null) {
            return 'U katalogu trenutno nema autora čije knjige pokrivaju više različitih žanrova.';
        }

        $broj    = (int) $r['broj'];
        $recZanr = $this->dvaDoCetiri($broj) ? 'žanra' : 'žanrova';

        return "Da, ima. Na primer, {$r['autor']} ima knjige iz {$broj} {$recZanr}: "
            . $r['zanrovi']->implode(', ') . '.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ZADUŽENJA NAD KNJIGOM (korpa B) — istorija/popularnost imenovane knjige
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * „Da li je knjiga X ikad pozajmljena" (korpa B) — TAČAN broj zaduženja
     * imenovane knjige iz arhive; nula → jasno kaže da nije (halucinacija p5:
     * model je za nezaduživanu knjigu odgovarao dvosmisleno).
     */
    public function odgovorIkadZaduzena(Knjiga $knjiga): string
    {
        $broj = $this->data->zaduzenjaPoKnjigama([$knjiga->id])[$knjiga->id] ?? 0;
        $link = $this->linkKnjige($knjiga);

        return $broj === 0
            ? "Knjiga {$link} do sada nije bila zaduživana nijednom."
            : "Da, knjiga {$link} je do sada zaduživana " . $this->brojPuta($broj) . '.';
    }

    /**
     * „Koja je knjiga najmanje pozajmljivana" (korpa B) — kada postoje knjige
     * bez ijednog zaduženja, to su najmanje pozajmljivane (ukupan broj + par
     * primera). Ako je svaka zaduživana bar jednom → pošten odgovor.
     */
    public function odgovorNajmanjePozajmljivana(): string
    {
        $nikad = $this->data->knjigeNikadZaduzene();

        if ($nikad->isEmpty()) {
            return 'Sve knjige u katalogu su do sada bar jednom zaduživane, '
                . 'pa nema knjige koja se izdvaja kao najmanje pozajmljivana.';
        }

        $primeri = $nikad->take(self::MAX_LISTA_KNJIGA)
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->implode(', ');

        if ($nikad->count() === 1) {
            return "Najmanje se pozajmljuje jedna knjiga koja do sada nije zaduživana nijednom: {$primeri}.";
        }

        return 'Najmanje se pozajmljuju knjige koje do sada nisu zaduživane nijednom — ima ih '
            . $this->brojKnjiga($nikad->count()) . '. Na primer: ' . $primeri . '.';
    }

    /**
     * „Preporuči knjigu koja još nije pozajmljena" (korpa B) — nekoliko
     * nasumičnih nezaduživanih naslova kao preporuka (umesto generičke liste
     * najčitanijih, koju je router ranije nudio — p22).
     */
    public function odgovorPreporukaNepozajmljene(): string
    {
        $nikad = $this->data->knjigeNikadZaduzene();

        if ($nikad->isEmpty()) {
            return 'Svaka knjiga iz kataloga je do sada bar jednom zaduživana, '
                . 'pa nemam nezaduživan naslov za preporuku.';
        }

        $izbor = $nikad->shuffle()->take(self::MAX_LISTA_KNJIGA)
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->implode(', ');

        if ($nikad->count() === 1) {
            return "Do sada je nezaduživana samo jedna knjiga, pa preporučujem nju: {$izbor}.";
        }

        return 'Evo nekoliko naslova koji do sada nisu zaduživani nijednom (ukupno '
            . $this->brojKnjiga($nikad->count()) . '): ' . $izbor . '.';
    }

    /**
     * „Koja je popularnija, X ili Y" (korpa B) — poređenje dve imenovane knjige
     * po broju zaduženja iz arhive. Obe bez zaduženja → pošteno kaže da ne može
     * da proceni.
     */
    public function odgovorPoredjenjePopularnost(Knjiga $a, Knjiga $b): string
    {
        $z  = $this->data->zaduzenjaPoKnjigama([$a->id, $b->id]);
        $za = $z[$a->id] ?? 0;
        $zb = $z[$b->id] ?? 0;
        $la = $this->linkKnjige($a);
        $lb = $this->linkKnjige($b);

        if ($za === 0 && $zb === 0) {
            return "Nijedna od te dve knjige do sada nije zaduživana, "
                . "pa ne mogu da procenim koja je popularnija — {$la} i {$lb}.";
        }

        if ($za === $zb) {
            return "Obe knjige su jednako popularne — i {$la} i {$lb} su zaduživane "
                . $this->brojPuta($za) . '.';
        }

        [$vise, $viseBroj, $manje, $manjeBroj] = $za > $zb ? [$la, $za, $lb, $zb] : [$lb, $zb, $la, $za];

        return "Popularnija je {$vise} — zaduživana je " . $this->brojPuta($viseBroj)
            . ", dok je {$manje} zaduživana " . $this->brojPuta($manjeBroj) . '.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AGREGACIJA ZADUŽENJA PO AUTORU / ŽANRU (korpa C+D) — jedan motor
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Zaduženja agregirano po grupi ($grupa = 'autor' | 'zanr') za traženu
     * metriku: 'avg' (najveći prosek po knjizi), 'sum' (najviše ukupnih
     * zaduženja), 'sve' (grupa čije su sve knjige zaduživane), 'nijedna' (grupa
     * čija nijedna nije), 'bez' (najviše nezaduživanih knjiga). Model se ne
     * poziva (kandidati_ucenje_5, korpe C i D).
     */
    public function odgovorGrupaZaduzenja(string $grupa, string $metrika): string
    {
        $stavke = $grupa === 'autor'
            ? $this->data->zaduzenjaPoAutoru()
            : $this->data->zaduzenjaPoZanru();

        if ($stavke->isEmpty()) {
            return $grupa === 'autor'
                ? 'Za knjige u katalogu autor trenutno nije upisan, pa tu analizu ne mogu da uradim.'
                : 'U katalogu trenutno nema žanrova sa knjigama, pa tu analizu ne mogu da uradim.';
        }

        return match ($metrika) {
            'avg'     => $this->grupaProsek($stavke, $grupa),
            'sve'     => $this->grupaSve($stavke, $grupa),
            'nijedna' => $this->grupaNijedna($stavke, $grupa),
            'bez'     => $this->grupaNajviseBez($stavke, $grupa),
            default   => $this->grupaSuma($stavke, $grupa),
        };
    }

    /** „kod autora X" / „u žanru X" — prirodan padež uz ime grupe. */
    private function grupaFraza(string $grupa, string $ime): string
    {
        return $grupa === 'autor' ? "autora {$ime}" : "žanra {$ime}";
    }

    private function grupaProsek(Collection $stavke, string $grupa): string
    {
        $rang = $stavke->filter(fn ($g) => $g->knjiga > 0 && $g->zaduzenja > 0)
            ->sortByDesc(fn ($g) => $g->zaduzenja / $g->knjiga)
            ->values();

        if ($rang->isEmpty()) {
            return 'Još uvek nema dovoljno zaduženja da bih izračunao prosek po '
                . ($grupa === 'autor' ? 'autoru' : 'žanru') . '.';
        }

        $p      = $rang->first();
        $prosek = str_replace('.', ',', (string) round($p->zaduzenja / $p->knjiga, 1));

        return 'Najveći prosečan broj zaduženja po knjizi ima ' . $this->grupaFraza($grupa, $p->ime)
            . " — u proseku {$prosek} (" . $this->brojPuta((int) $p->zaduzenja)
            . ' na ' . $this->brojKnjiga((int) $p->knjiga) . ').';
    }

    private function grupaSuma(Collection $stavke, string $grupa): string
    {
        $rang = $stavke->filter(fn ($g) => $g->zaduzenja > 0)->sortByDesc('zaduzenja')->values();

        if ($rang->isEmpty()) {
            return 'Još uvek nema zabeleženih zaduženja, pa taj podatak ne mogu da dam.';
        }

        $p    = $rang->first();
        $uvod = $grupa === 'autor'
            ? "Najviše ukupnih zaduženja ima autor {$p->ime}"
            : "Najviše ukupnih zaduženja ima žanr {$p->ime}";

        return "{$uvod} — knjige su zaduživane " . $this->brojPuta((int) $p->zaduzenja) . '.';
    }

    private function grupaSve(Collection $stavke, string $grupa): string
    {
        // „sve knjige zaduživane" je smisleno tek uz bar dve knjige.
        $rang = $stavke->filter(fn ($g) => $g->knjiga >= 2 && $g->bez === 0)
            ->sortByDesc('knjiga')->values();

        if ($rang->isEmpty()) {
            return $grupa === 'autor'
                ? 'Trenutno nema autora (sa bar dve knjige) čije su sve knjige bar jednom zaduživane.'
                : 'Trenutno nema žanra u kome su baš sve knjige bar jednom zaduživane.';
        }

        $p = $rang->first();

        return 'Da, ima. Na primer, kod ' . $this->grupaFraza($grupa, $p->ime)
            . ' su sve knjige (' . $this->brojKnjiga((int) $p->knjiga) . ') bar jednom zaduživane.';
    }

    private function grupaNijedna(Collection $stavke, string $grupa): string
    {
        $rang = $stavke->filter(fn ($g) => $g->knjiga > 0 && $g->zaduzenja === 0)
            ->sortByDesc('knjiga')->values();

        if ($rang->isEmpty()) {
            return $grupa === 'autor'
                ? 'Trenutno nema autora čija baš nijedna knjiga nije zaduživana.'
                : 'Trenutno nema žanra u kome baš nijedna knjiga nije zaduživana.';
        }

        $p = $rang->first();

        return 'Da, ima. Na primer, kod ' . $this->grupaFraza($grupa, $p->ime)
            . ' nijedna knjiga (' . $this->brojKnjiga((int) $p->knjiga) . ') do sada nije zaduživana.';
    }

    private function grupaNajviseBez(Collection $stavke, string $grupa): string
    {
        $rang = $stavke->filter(fn ($g) => $g->bez > 0)->sortByDesc('bez')->values();

        if ($rang->isEmpty()) {
            return $grupa === 'autor'
                ? 'Svaki autor u katalogu ima bar jednu zaduživanu knjigu.'
                : 'Svaki žanr u katalogu ima bar jednu zaduživanu knjigu.';
        }

        $p    = $rang->first();
        $uvod = $grupa === 'autor' ? "Autor {$p->ime}" : "Žanr {$p->ime}";

        return "{$uvod} ima najviše knjiga koje do sada nisu zaduživane — "
            . $this->brojKnjiga((int) $p->bez) . " od ukupno {$p->knjiga}.";
    }

    /**
     * „U kojoj godini imate najviše izdatih knjiga" — godina sa najvećim
     * brojem knjiga u katalogu (kandidati_ucenje_2 #34 p10).
     */
    public function odgovorGodinaNajvise(): string
    {
        $g = $this->data->godinaSaNajviseKnjiga();

        if ($g === null) {
            return 'Za knjige u katalogu godina izdanja trenutno nije upisana, pa taj podatak nemam.';
        }

        return "Najviše knjiga u katalogu izdato je {$g['godina']}. godine — "
            . $this->brojKnjiga($g['broj']) . '.';
    }

    /**
     * „Koja je najstarija / najnovija knjiga u katalogu" — knjiga sa najmanjom
     * odnosno najvećom godinom izdanja (kandidati_ucenje_2 #34 p8/p9).
     */
    public function odgovorEkstremPoGodini(bool $najstarija): string
    {
        $k = $this->data->ekstremPoGodini($najstarija);

        if ($k === null) {
            return 'Za knjige u katalogu godina izdanja trenutno nije upisana, pa taj podatak nemam.';
        }

        return $najstarija
            ? "Najstarija knjiga u katalogu je {$this->linkKnjige($k)} — izdata {$k->godina_izdavanja}. godine."
            : "Najnovija knjiga po godini izdanja je {$this->linkKnjige($k)} — izdata {$k->godina_izdavanja}. godine.";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RELACIONI UPITI — „iste osobine kao knjiga X" (izdavač / godina)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * „Koje knjige imaju istog IZDAVAČA kao X" — ostale knjige istog izdavača
     * (kandidati_ucenje_2 #34 p18/p21/p22, #35 p21). Kada izdavač reference
     * nije upisan → pošten odgovor; kada drugih nema → iskreno kaže.
     */
    public function odgovorIstiIzdavacKao(Knjiga $ref): string
    {
        $refLink = "[{$ref->naziv_knjige}](/knjige/{$ref->id})";

        if (($ref->izdavac ?? '') === '') {
            return "Za knjigu {$refLink} izdavač nije upisan u katalog, pa ne mogu da pronađem knjige istog izdavača.";
        }

        $knjige = $this->data->knjigeIstogIzdavaca((string) $ref->izdavac, $ref->id);

        if ($knjige->isEmpty()) {
            return "Osim knjige {$refLink}, trenutno nemamo drugih knjiga izdavača {$ref->izdavac} u katalogu.";
        }

        $stavke = $knjige->map(fn (Knjiga $k) => $this->linkKnjige($k))->values();

        return $this->listaSaUkupnimBrojem("Od istog izdavača kao {$refLink} ({$ref->izdavac})", $stavke, false);
    }

    /**
     * „Koje knjige imaju istu GODINU izdanja kao X" — ostale knjige iste
     * godine (kandidati_ucenje_2 #34 p23, #35 p24). Bez upisane godine
     * reference → pošten odgovor; bez drugih → iskreno kaže.
     */
    public function odgovorIstaGodinaKao(Knjiga $ref): string
    {
        $refLink = "[{$ref->naziv_knjige}](/knjige/{$ref->id})";

        if (!$ref->godina_izdavanja) {
            return "Za knjigu {$refLink} godina izdanja nije upisana u katalog, pa ne mogu da pronađem knjige iste godine.";
        }

        $knjige = $this->data->knjigeIsteGodine((int) $ref->godina_izdavanja, $ref->id);

        if ($knjige->isEmpty()) {
            return "Osim knjige {$refLink}, iz {$ref->godina_izdavanja}. godine trenutno nemamo drugih knjiga u katalogu.";
        }

        $stavke = $knjige->map(fn (Knjiga $k) => $this->linkKnjige($k))->values();

        return $this->listaSaUkupnimBrojem("Iste godine kao {$refLink} ({$ref->godina_izdavanja}.)", $stavke, false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POREĐENJE DVE IMENOVANE KNJIGE — godina / izdavač / mesto
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * „Koja je starija / novija, X ili Y" — poređenje po godini izdanja; iste
     * godine → izričito kaže; nedostajuća godina → pošten odgovor
     * (kandidati_ucenje_2 #35 p16/p17/p20).
     */
    public function odgovorPoredjenjeGodina(Knjiga $a, Knjiga $b, bool $trazeStariju): string
    {
        $la = $this->linkKnjige($a);
        $lb = $this->linkKnjige($b);

        if (!$a->godina_izdavanja || !$b->godina_izdavanja) {
            $koja = !$a->godina_izdavanja ? $la : $lb;
            return "Ne mogu da uporedim godine izdanja jer za knjigu {$koja} godina nije upisana u katalog.";
        }

        $ga = (int) $a->godina_izdavanja;
        $gb = (int) $b->godina_izdavanja;

        if ($ga === $gb) {
            return "Obe knjige su iz iste godine — i {$la} i {$lb} su izdate {$ga}. godine.";
        }

        if ($trazeStariju) {
            [$koja, $g, $druga, $gd] = $ga < $gb ? [$la, $ga, $lb, $gb] : [$lb, $gb, $la, $ga];
            return "Starija je {$koja} — izdata je {$g}. godine, dok je {$druga} iz {$gd}. godine.";
        }

        [$koja, $g, $druga, $gd] = $ga > $gb ? [$la, $ga, $lb, $gb] : [$lb, $gb, $la, $ga];
        return "Novija je {$koja} — izdata je {$g}. godine, dok je {$druga} iz {$gd}. godine.";
    }

    /**
     * „Koja od ove dve knjige ima istog izdavača" — porede se izdavači dve
     * imenovane knjige (kandidati_ucenje_2 #35 p18).
     */
    public function odgovorPoredjenjeIzdavac(Knjiga $a, Knjiga $b): string
    {
        $la = $this->linkKnjige($a);
        $lb = $this->linkKnjige($b);
        $ia = trim((string) $a->izdavac);
        $ib = trim((string) $b->izdavac);

        if ($ia === '' || $ib === '') {
            $koja = $ia === '' ? $la : $lb;
            return "Ne mogu da uporedim izdavače jer za knjigu {$koja} izdavač nije upisan u katalog.";
        }

        if (mb_strtolower($ia) === mb_strtolower($ib)) {
            return "Obe knjige imaju istog izdavača — {$la} i {$lb} je izdala {$ia}.";
        }

        return "Knjige imaju različite izdavače — {$la} je izdala {$ia}, a {$lb} {$ib}.";
    }

    /**
     * „Koja od ove dve knjige je objavljena u <mesto>" — poredi mesta
     * izdavanja dve imenovane knjige (kandidati_ucenje_2 #35 p19). Pripadnost
     * mestu se čita iz skupa ID-jeva koje je router dobio iz nadjiMesto
     * (kanonsko poklapanje grada, tolerantno na padež). Nijedna → pošteno kaže.
     *
     * @param  Collection<int, int>  $idsUMestu  ID-jevi svih knjiga u tom mestu
     */
    public function odgovorPoredjenjeMesto(Knjiga $a, Knjiga $b, string $mestoPrikaz, Collection $idsUMestu): string
    {
        $la = $this->linkKnjige($a);
        $lb = $this->linkKnjige($b);

        $aU = $idsUMestu->contains($a->id);
        $bU = $idsUMestu->contains($b->id);

        $uMestu = $this->uMestu($mestoPrikaz);

        if ($aU && $bU) {
            return "Obe knjige su izdate {$uMestu} — i {$la} i {$lb}.";
        }
        if ($aU) {
            return ucfirst($uMestu) . " izdata je {$la}.";
        }
        if ($bU) {
            return ucfirst($uMestu) . " izdata je {$lb}.";
        }

        return "Nijedna od te dve knjige nije izdata {$uMestu} — "
            . "{$la} je izdata " . ($a->mesto_izdavanja ? $this->uMestu((string) $a->mesto_izdavanja) : 'u mestu koje nije upisano')
            . ", a {$lb} " . ($b->mesto_izdavanja ? $this->uMestu((string) $b->mesto_izdavanja) : 'u mestu koje nije upisano') . '.';
    }

    /**
     * Poklapanje reči upita sa tokenom naziva mesta — tolerantno na LOKATIV
     * grada („u Nišu" → „nisu" vs nominativ „nis", „u Beogradu" → „beogradu"
     * vs „beograd"): fuzzy, ILI reč upita počinje nazivom mesta (lokativni
     * nastavak), ILI naziv mesta počinje rečju upita (padež samog naziva).
     */
    private function mestoTokenPogodak(string $recUpita, string $mestoToken): bool
    {
        return TextUtils::fuzzyJednake($recUpita, $mestoToken)
            || (mb_strlen($mestoToken) >= 3 && str_starts_with($recUpita, $mestoToken))
            || (mb_strlen($recUpita) >= 4 && str_starts_with($mestoToken, $recUpita))
            || $this->istiKorenPadez($recUpita, $mestoToken);
    }

    /**
     * Poklapanje reči koje se razlikuju SAMO u kratkom padežnom nastavku dok im
     * je koren isti — za pridevski deo naziva mesta gde lokativ menja i osnovu
     * („Novi Sad" → „u Novom Sadu": „novom" ↔ „novi"). Fuzzy to promašuje jer je
     * isključen za reči kraće od 5 slova. Uslov je tesan (obe reči ≥ 4 slova,
     * razlika dužine ≤ 2, zajednički prefiks tik do kraja kraće reči), pa ne
     * spaja različite gradove („sadu" ↔ „sabac" ostaje promašaj).
     */
    private function istiKorenPadez(string $a, string $b): bool
    {
        $la = mb_strlen($a);
        $lb = mb_strlen($b);

        if ($la < 4 || $lb < 4 || abs($la - $lb) > 2) {
            return false;
        }

        $minLen = min($la, $lb);
        $prefiks = 0;
        for ($i = 0; $i < $minLen; $i++) {
            if (mb_substr($a, $i, 1) !== mb_substr($b, $i, 1)) {
                break;
            }
            $prefiks++;
        }

        return $prefiks >= $minLen - 1 && $prefiks >= 3;
    }

    /**
     * „Koja od ove dve knjige je objavljena VAN <mesto>" — knjiga koja NIJE u
     * datom mestu (kandidati_ucenje_3 #36 p19).
     *
     * @param  Collection<int, int>  $idsUMestu  ID-jevi knjiga u tom mestu
     */
    public function odgovorPoredjenjeVanMesta(Knjiga $a, Knjiga $b, string $mestoPrikaz, Collection $idsUMestu): string
    {
        $la = $this->linkKnjige($a);
        $lb = $this->linkKnjige($b);

        $aVan   = !$idsUMestu->contains($a->id);
        $bVan   = !$idsUMestu->contains($b->id);
        $vanMesta = $this->vanMesta($mestoPrikaz);

        if ($aVan && $bVan) {
            return "Obe knjige su izdate {$vanMesta} — i {$la} i {$lb}.";
        }
        if ($aVan) {
            return ucfirst($vanMesta) . " izdata je {$la}"
                . ($a->mesto_izdavanja ? ' — ona je izdata ' . $this->uMestu((string) $a->mesto_izdavanja) : '') . '.';
        }
        if ($bVan) {
            return ucfirst($vanMesta) . " izdata je {$lb}"
                . ($b->mesto_izdavanja ? ' — ona je izdata ' . $this->uMestu((string) $b->mesto_izdavanja) : '') . '.';
        }

        return "Obe knjige su izdate {$this->uMestu($mestoPrikaz)} — ni {$la} ni {$lb} nije van njega.";
    }

    /**
     * „Postoje li dve knjige istog autora objavljene iste godine" — analitičko
     * da/ne sa primerom iz kataloga (kandidati_ucenje_3 #36 p15).
     */
    public function odgovorDveIsteGodine(): string
    {
        $par = $this->data->dveKnjigeIstiAutorIstaGodina();

        if ($par === null) {
            return 'U katalogu trenutno nema dve knjige istog autora objavljene iste godine.';
        }

        $stavke = $par['knjige']->map(fn (Knjiga $k) => $this->linkKnjige($k))->implode(' i ');

        return "Da, ima. Na primer, autor {$par['autor']} ima dve knjige iz {$par['godina']}. godine: {$stavke}.";
    }

    /**
     * „Knjiga koja se ZAVRŠAVA / POČINJE rečju X" — pravilo kratke liste
     * (kandidati_ucenje_3 #39 p18).
     *
     * @param  array<int, int>  $ids
     */
    public function odgovorNaslovPoReci(string $rec, string $pozicija, array $ids): string
    {
        if (empty($ids)) {
            return $pozicija === 'sadrzi'
                ? "U katalogu nema knjige čiji naslov sadrži reč „{$rec}”."
                : 'U katalogu nema knjige čiji se naslov ' . ($pozicija === 'kraj' ? 'završava' : 'počinje') . " rečju „{$rec}”.";
        }

        $stavke = $this->data->knjigeAzbucno($ids)
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        if ($pozicija === 'sadrzi') {
            if ($stavke->count() === 1) {
                return "Naslov sa rečju „{$rec}” ima " . $stavke->first() . '.';
            }

            return $this->listaSaUkupnimBrojem("Sa rečju „{$rec}” u naslovu", $stavke, false);
        }

        // „se završava" je povratni glagol; „počinje" NIJE povratno („se
        // počinje" bi bilo pogrešno).
        $glagol = $pozicija === 'kraj' ? 'se završava' : 'počinje';

        if ($stavke->count() === 1) {
            return "Naslov koji {$glagol} rečju „{$rec}” ima " . $stavke->first() . '.';
        }

        return $this->listaSaUkupnimBrojem("Naslovom koji {$glagol} rečju „{$rec}”", $stavke, false);
    }

    /**
     * „Koje knjige imaju BROJ u naslovu" (set17 p18) — pravilo kratke liste.
     *
     * @param  array<int, int>  $ids
     */
    public function odgovorBrojUNaslovu(array $ids): string
    {
        if (empty($ids)) {
            return 'U katalogu nema knjige koja u naslovu sadrži broj.';
        }

        $stavke = $this->data->knjigeAzbucno($ids)
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        return $this->listaSaUkupnimBrojem('Sa brojem u naslovu', $stavke, false);
    }

    /**
     * „Knjige koje imaju TAČNO N reči u naslovu" (set17 p19).
     *
     * @param  array<int, int>  $ids
     */
    public function odgovorReciUNaslovu(int $n, array $ids): string
    {
        $recRec = $n === 1 ? 'jednu reč' : "{$n} reči";

        if (empty($ids)) {
            return "U katalogu nema knjige čiji naslov ima {$recRec}.";
        }

        $stavke = $this->data->knjigeAzbucno($ids)
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        return $this->listaSaUkupnimBrojem("Sa {$recRec} u naslovu", $stavke, false);
    }

    /**
     * „Autori čije PREZIME počinje slovom X" (set17 p20).
     *
     * @param  Collection<int, string>  $autori
     */
    public function odgovorPrezimeNaSlovo(string $slovo, Collection $autori): string
    {
        $slovoPrikaz = mb_strtoupper($slovo);

        if ($autori->isEmpty()) {
            return "U katalogu nema autora čije prezime počinje slovom „{$slovoPrikaz}”.";
        }

        if ($autori->count() === 1) {
            return "Prezime na slovo „{$slovoPrikaz}” ima " . $autori->first() . '.';
        }

        $n = $autori->count();

        if ($n <= self::MAX_LISTA_KNJIGA) {
            return "Prezime na slovo „{$slovoPrikaz}” ima {$n} autora: " . $autori->implode(', ') . '.';
        }

        return "Prezime na slovo „{$slovoPrikaz}” ima {$n} autora. Na primer: "
            . $autori->take(self::MAX_LISTA_KNJIGA)->implode(', ') . '.';
    }

    /**
     * „Postoji li još knjiga autora X" / „još knjiga autora knjige Y" —
     * FIKSNA lista OSTALIH knjiga autora (bez već pomenutih), prirodnog tona
     * (kandidati_ucenje_2 #34 p20/p24, #35 p9/p11/p14/p15/p23). Kada osim
     * pomenutih nema drugih → iskreno kaže.
     *
     * @param  Collection<int, int>  $sviIds     sve knjige autora
     * @param  array<int, int>        $izuzmiIds  knjige koje su već pomenute
     */
    public function odgovorJosKnjigaAutora(string $autorPrikaz, Collection $sviIds, array $izuzmiIds = []): string
    {
        $preostali = $sviIds->reject(fn (int $id) => in_array($id, $izuzmiIds, true))->values();

        // Rod iz kanonskog imena (bilo koje knjige autora) — „autora"/„autorke".
        $k0  = $sviIds->isNotEmpty() ? $this->data->nadjiKnjigu((int) $sviIds->first()) : null;
        $rec = $k0 !== null && TextUtils::jeZenskoIme((string) $k0->autor) ? 'autorke' : 'autora';

        if ($preostali->isEmpty()) {
            return empty($izuzmiIds)
                ? "Trenutno nemamo knjige {$rec} {$autorPrikaz} u katalogu."
                : "Osim te knjige, trenutno nemamo drugih knjiga {$rec} {$autorPrikaz} u katalogu.";
        }

        $stavke = $this->data->knjigeAzbucno($preostali->all())
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        // Prirodan ton („Naravno, imamo još…") + pravilo kratke liste: do 5
        // sve, preko 5 ukupan broj + prvih 5 + ponuda ostatka.
        if ($stavke->count() <= self::MAX_LISTA_KNJIGA) {
            return "Naravno, u katalogu imamo još " . $this->brojKnjigaAkuzativ($stavke->count())
                . " {$rec} {$autorPrikaz}: " . $stavke->implode(', ') . '.';
        }

        return "Naravno, {$rec} {$autorPrikaz} imamo još ukupno " . $this->brojKnjigaAkuzativ($stavke->count())
            . '. Ovo su neke od njih: ' . $stavke->take(self::MAX_LISTA_KNJIGA)->implode(', ')
            . '. Recite ukoliko želite ostatak spiska.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BROJ KNJIGA SA REČJU U NASLOVU — tačan broj + SVE stavke
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * „Koliko knjiga ima naslov sa rečju X" — TAČAN broj i kompletno
     * nabrajanje (bib deo 1A: nikad skraćena lista bez napomene).
     *
     * @param  array{rec: string, broj: int, primeri: array<int, int>}  $rezultat
     *         rezultat KatalogPretraga::brojKnjigaSaRecju (primeri = SVI ID-jevi)
     */
    public function odgovorBrojKnjigaSaRecju(array $rezultat, bool $ostatak = false): string
    {
        if ($rezultat['broj'] === 0) {
            return "Nijedna knjiga u katalogu ne sadrži reč \"{$rezultat['rec']}\" u nazivu.";
        }

        $stavke = $this->data->knjigeAzbucno($rezultat['primeri'])
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        $broj = $rezultat['broj'];
        $uvod = "U nazivu tačno " . $this->brojKnjiga($broj)
            . " sadrži" . ($this->dvaDoCetiri($broj) ? 'e' : '')
            . " reč \"{$rezultat['rec']}\": ";

        return $this->saOgranicenjemListe($uvod, $stavke, $ostatak);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NASLOVI NA SLOVO — tačan broj + kompletna lista iz kataloga
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * „Koliko knjiga počinje na slovo X / koje knjige počinju slovom X" —
     * broj i spisak se izračunavaju iz kataloga (model se ne poziva; ručno
     * testiranje: model je za ovakvo pitanje izmišljao i broj i naslove).
     *
     * @param  array<int, int>  $ids  SVI ID-jevi (KatalogPretraga::naslovNaSlovo)
     */
    public function odgovorPocinjeNaSlovo(string $slovo, array $ids, bool $ostatak = false): string
    {
        $slovoPrikaz = mb_strtoupper($slovo);

        if (empty($ids)) {
            return "Nijedna knjiga u katalogu ne počinje slovom \"{$slovoPrikaz}\".";
        }

        $stavke = $this->data->knjigeAzbucno($ids)
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        $broj = $stavke->count();
        $uvod = "Slovom \"{$slovoPrikaz}\" počinje tačno " . $this->brojKnjiga($broj) . ': ';

        return $this->saOgranicenjemListe($uvod, $stavke, $ostatak);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GODINA IZDANJA — filtriranje kataloga po godini, model se ne poziva
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Čitljiv opis opsega godine izdanja (granice su UKLJUČIVE — parser u
     * IntentClassifier::godinaIzdanjaOpseg): „od 2010. godine", „do 2000.
     * godine", „između 1990. i 2000. godine", „iz 2018. godine".
     *
     * @param  array{od: ?int, do: ?int}  $opseg
     */
    public function opisGodina(array $opseg): string
    {
        $od = $opseg['od'] ?? null;
        $do = $opseg['do'] ?? null;

        return match (true) {
            $od !== null && $do !== null && $od === $do => "iz {$od}. godine",
            $od !== null && $do !== null                => "objavljene između {$od}. i {$do}. godine",
            $od !== null                                => "objavljene od {$od}. godine",
            default                                     => "objavljene do {$do}. godine",
        };
    }

    /**
     * „Koje knjige imate objavljene posle 2010" (+ eventualni žanr) — spisak
     * iz baze po koloni godina_izdavanja, uz pravilo kratke liste. POŠTENA
     * napomena kada za deo fonda godina nije upisana — bez nje bi mali broj
     * delovao kao ceo fond.
     *
     * $kaoPreporuka (nalaz #3, „preporuči/predloži mi roman posle 2020"): isti
     * podaci, ali oblik PREPORUKE — nekoliko naslova, bez isticanja ukupnog
     * broja i bez ponude ostatka spiska.
     *
     * @param  array{od: ?int, do: ?int}  $opseg
     */
    public function odgovorGodinaOpseg(array $opseg, ?Zanr $zanr = null, bool $ostatak = false, bool $kaoPreporuka = false): string
    {
        $rezultat = $this->data->idsUOpseguGodina($opseg['od'] ?? null, $opseg['do'] ?? null);
        $ids      = collect($rezultat['ids']);

        if ($zanr !== null) {
            $ids = $ids->intersect($this->data->knjigeZanra($zanr)->pluck('id'))->values();
        }

        $opis     = $this->opisGodina($opseg);
        $zanrOpis = $zanr !== null ? " iz žanra {$zanr->naziv}" : '';

        if ($ids->isEmpty()) {
            $napomena = $rezultat['bez_godine'] > 0
                ? " Napomena: za {$rezultat['bez_godine']} " . $this->recRazlicitihKnjiga($rezultat['bez_godine'])
                    . ' godina izdanja nije upisana, pa nisu ušle u ovaj spisak.'
                : '';
            return "U katalogu trenutno nemamo knjige {$opis}{$zanrOpis}.{$napomena}";
        }

        // PREPORUKA: nekoliko naslova, bez ukupnog broja i bez „ostatka".
        // „naslova iz žanra X" izbegava deklinaciju naziva žanra, a raspon
        // godine ide u zagradu da se izbegne slaganje prideva („objavljenih").
        if ($kaoPreporuka) {
            $izbor  = $this->data->knjigeAzbucno($ids->shuffle()->take(3)->all())
                ->map(fn (Knjiga $k) => $this->linkKnjige($k))
                ->values();
            $raspon = trim((string) preg_replace('/^objavljene\s+/u', '', $opis));

            return "Evo nekoliko naslova{$zanrOpis} ({$raspon}): " . $izbor->implode(', ') . '.';
        }

        // Bez „Napomena: za N knjiga godina nije upisana…" u odgovoru sa
        // spiskom — meta-podatak o stanju kataloga zbunjuje korisnika (eval
        // za_kod.md, #19 p8 i #22 p10); napomena ostaje samo kada je
        // rezultat PRAZAN, jer tamo sprečava netačno „nemamo nijednu".
        $stavke = $this->data->knjigeAzbucno($ids->all())
            ->map(fn (Knjiga $k) => $this->linkKnjige($k))
            ->values();

        // „Od 2010. godine iz žanra Romani imamo 3 knjige: …" — uvod bez
        // reči „objavljene", da se složi sa oblikom „…imamo N knjiga".
        $uvod = ucfirst(trim((string) preg_replace('/^objavljene\s+/u', '', $opis)));

        return $this->listaSaUkupnimBrojem($uvod . $zanrOpis, $stavke, $ostatak);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOSTUPNOST I LISTA ČEKANJA — sveže stanje baze, model se ne poziva
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * „Da li je knjiga X dostupna / mogu li da je zadužim" — dostupnost se
     * čita iz SVEŽEG stanja primeraka i vraća fiksno: model je za zauzetu
     * knjigu umeo da kaže da je slobodna (nalaz iz ručnog testiranja).
     *
     * @param  string  $preporukeRep  gotova rečenica sa preporukama sličnih
     *         naslova (sastavlja je router iz kaskade preporuka) — dodaje se
     *         SAMO kada knjiga nije dostupna, da korisnik ne ode praznih ruku.
     */
    public function odgovorDostupnost(int $knjigaId, string $preporukeRep = ''): string
    {
        $s = $this->data->stanjeKnjige($knjigaId);

        if ($s === null) {
            return 'Tu knjigu više nemamo u katalogu.';
        }

        $link = $this->linkKnjige($s['knjiga']);

        if ($s['slobodno'] > 0) {
            return "Knjiga {$link} je trenutno dostupna — slobodno je "
                . "{$s['slobodno']} od {$s['ukupno']} " . $this->recPrimeraka($s['ukupno'])
                . '. Ukoliko ste ulogovani kao član, možete je rezervisati dugmetom '
                . '„Rezerviši" na strani knjige — knjiga se preuzima u biblioteci '
                . 'tek nakon što bibliotekarka odobri rezervaciju.';
        }

        return "Knjiga {$link} trenutno nije dostupna — "
            . ($s['ukupno'] === 1 ? 'jedini primerak je zauzet'
                : "svi primerci (ukupno {$s['ukupno']}) su zauzeti")
            . '. Rezervacija je moguća tek kada se primerak oslobodi.'
            . ($preporukeRep !== '' ? ' ' . $preporukeRep : '');
    }

    /**
     * KRATKA rečenica o dostupnosti — rep za kombinovana pitanja („ko je
     * napisao X i da li je dostupna?"), bez ponavljanja linka knjige i bez
     * uputstva o rezervaciji (kandidati_ucenje #33 p23).
     */
    public function dostupnostRecenica(int $knjigaId): string
    {
        $s = $this->data->stanjeKnjige($knjigaId);

        if ($s === null) {
            return '';
        }

        if ($s['slobodno'] > 0) {
            return "Knjiga je trenutno dostupna — slobodno je {$s['slobodno']} od "
                . "{$s['ukupno']} " . $this->recPrimeraka($s['ukupno']) . '.';
        }

        return 'Knjiga trenutno nije dostupna — rezervacija je moguća tek kada se primerak oslobodi.';
    }

    /**
     * „Koliko ukupno knjiga imate u katalogu?" — čist agregat, FIKSNO iz
     * baze (kandidati_ucenje #33 p7: model je uz tačne agregate u promptu
     * vraćao besmislen broj).
     */
    public function odgovorUkupanBrojKnjiga(): string
    {
        $a = $this->data->agregatiKataloga();

        return "U katalogu imamo ukupno {$a['knjiga']} "
            . $this->recRazlicitihKnjiga((int) $a['knjiga']) . '.';
    }

    /**
     * „KOJA OD TIH TI JE NAJBOLJA?" — rangiranje prethodno izlistanih knjiga
     * BROJEM ZADUŽENJA iz arhive (kandidati_ucenje #33 p24b: rangiranje ide
     * po broju zaduženja; bez ijednog zaduženja poštena poruka).
     *
     * @param  array<int, int>  $ids  knjige iz prethodnog spiska
     */
    public function odgovorNajboljaOdListe(array $ids): string
    {
        $zaduzenja = $this->data->zaduzenjaPoKnjigama($ids);

        if (empty($zaduzenja)) {
            return 'Ove knjige do sada nisu zaduživane nijednom, pa ne mogu da procenim koja je najbolja.';
        }

        arsort($zaduzenja);
        $prviId = (int) array_key_first($zaduzenja);
        $knjiga = $this->data->nadjiKnjigu($prviId);

        if ($knjiga === null) {
            return 'Ove knjige do sada nisu zaduživane nijednom, pa ne mogu da procenim koja je najbolja.';
        }

        return 'Po broju zaduženja najčitanija je ' . $this->linkKnjige($knjiga)
            . ' — do sada je zaduživana ' . $this->brojPuta((int) $zaduzenja[$prviId]) . '.';
    }

    /**
     * „Ako rezervišem X sada, koji sam po redu na listi čekanja" — redosled
     * se RAČUNA iz broja aktivnih rezervacija (na čekanju + odobrene), a
     * dostupnost iz slobodnih primeraka. Kratko i fiksno.
     */
    public function odgovorListaCekanja(int $knjigaId): string
    {
        $s = $this->data->stanjeKnjige($knjigaId);

        if ($s === null) {
            return 'Tu knjigu više nemamo u katalogu.';
        }

        $link = $this->linkKnjige($s['knjiga']);
        $m    = $s['rezervacije'];

        if ($s['slobodno'] === 0) {
            return "Knjiga {$link} trenutno nema slobodnih primeraka"
                . ($m > 0 ? " (aktivnih rezervacija: {$m})" : '')
                . ', pa rezervacija nije moguća dok se primerak ne oslobodi.';
        }

        if ($m === 0) {
            return "Knjiga {$link} je trenutno dostupna i nema nijednu aktivnu "
                . 'rezervaciju — ukoliko je rezervišete sada, bili biste prvi po redu.';
        }

        return "Knjiga {$link} je trenutno dostupna, a aktivnih rezervacija je {$m} — "
            . 'ukoliko je rezervišete sada, bili biste ' . ($m + 1) . '. po redu.';
    }

    /**
     * DVA ŽANRA u pitanju („imate li nešto iz istorije i umetnosti", „krimi
     * roman") — knjige koje pripadaju OBA žanra (presek), uz pravilo kratke
     * liste; prazan presek → pošten odgovor sa stanjem oba žanra.
     */
    public function odgovorDvaZanra(Zanr $prvi, Zanr $drugi): string
    {
        $presekIds = $this->data->knjigeZanra($prvi)->pluck('id')
            ->intersect($this->data->knjigeZanra($drugi)->pluck('id'))
            ->values();

        if ($presekIds->isNotEmpty()) {
            $stavke = $this->data->knjigeAzbucno($presekIds->all())
                ->map(fn (Knjiga $k) => $this->linkKnjige($k))
                ->values();

            return $this->listaSaUkupnimBrojem(
                "Iz oba žanra ({$prvi->naziv} i {$drugi->naziv})",
                $stavke,
                false
            );
        }

        $a = $this->data->knjigeZanra($prvi)->count();
        $b = $this->data->knjigeZanra($drugi)->count();

        return "Trenutno nemamo knjige koje istovremeno pripadaju žanrovima "
            . "{$prvi->naziv} i {$drugi->naziv}. Iz žanra {$prvi->naziv} imamo "
            . $this->brojKnjigaAkuzativ($a) . ", a iz žanra {$drugi->naziv} "
            . $this->brojKnjigaAkuzativ($b) . ' — recite ukoliko želite spisak nekog od njih.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // KO JE ZADUŽIO KNJIGU — poslednje zaduženje iz arhive (nepoverljivo)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Poslednje zaduženje date knjige iz arhive — ime člana nije poverljiv
     * podatak (bib deo 3), a sve tvrdnje dolaze iz baze.
     */
    public function odgovorKoJeZaduzio(int $knjigaId): string
    {
        $knjiga = $this->data->nadjiKnjigu($knjigaId);

        if ($knjiga === null) {
            return 'Tu knjigu više nemamo u katalogu.';
        }

        $naslov = "[{$knjiga->naziv_knjige}](/knjige/{$knjiga->id})";

        $poslednje = $this->data->poslednjeZaduzenje($knjigaId);

        if ($poslednje === null) {
            return "Knjiga {$naslov} do sada nije zaduživana.";
        }

        $clan  = ($poslednje->ime_clana ?? '') !== '' ? $poslednje->ime_clana : 'član čije ime nije zabeleženo';
        $datum = $poslednje->datum_akcije?->format('d.m.Y.');

        return "Knjigu {$naslov} je poslednji put zadužio/zadužila {$clan}"
            . ($datum ? ", {$datum}" : '') . '.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AGREGACIONA ANALITIKA — COUNT / SUM / AVG / MIN / MAX
    // (odgovori se RAČUNAJU nad bazom, model se ne poziva)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * „Ima li knjiga koje NIKADA nisu zadužene" — knjige bez ijednog
     * zaduženja u arhivi (COUNT = 0), kompletna lista uz pravilo spiskova.
     */
    public function odgovorNikadZaduzene(bool $ostatak = false): string
    {
        $knjige = $this->data->knjigeNikadZaduzene();

        if ($knjige->isEmpty()) {
            return 'Svaka knjiga iz kataloga je do sada bar jednom zaduživana.';
        }

        $stavke = $knjige->map(fn (Knjiga $k) => $this->linkKnjige($k))->values();

        $uvod = 'Do sada nikada ' . ($knjige->count() === 1 ? 'nije zadužena ' : 'nisu zaduživane ')
            . $this->brojKnjiga($knjige->count()) . ': ';

        return $this->saOgranicenjemListe($uvod, $stavke, $ostatak);
    }

    /**
     * ŽANROVI koje je korisnik pomenuo u pitanju — ista fuzzy pravila kao
     * nadjiZanr, ali vraća SVE pogođene žanrove (za poređenje popularnosti),
     * redom pojavljivanja u tekstu.
     *
     * @return Collection<int, Zanr>
     */
    public function nadjiZanroveUTekstu(string $norm): Collection
    {
        $tokeni = $this->tokeniOdTriSlova(TextUtils::kanonizujZanrPrideve($norm));

        if (empty($tokeni)) {
            return collect();
        }

        $pogodjeni = [];

        foreach ($this->data->sviZanrovi() as $zanr) {
            $zanrTokeni = $this->tokeniOdTriSlova(TextUtils::normalize($zanr->naziv));

            if (empty($zanrTokeni)) {
                continue;
            }

            $pozicije = [];
            foreach ($zanrTokeni as $zt) {
                foreach ($tokeni as $i => $ft) {
                    if ($this->zanrTokenPogodak($ft, $zt)) {
                        $pozicije[] = $i;
                        break;
                    }
                }
            }

            if (!empty($pozicije) && count($pozicije) / count($zanrTokeni) >= 0.5) {
                $pogodjeni[] = [
                    'zanr'          => $zanr,
                    'pozicija'      => min($pozicije),
                    'pozicije'      => $pozicije,
                    'specificnost'  => count($zanrTokeni),
                ];
            }
        }

        // Redom pojavljivanja u tekstu; specifičniji žanr (više reči naziva)
        // ima prednost, a žanr čije su SVE pogođene reči već „potrošene" na
        // specifičniji se preskače — „naučne fantastike" ne sme da vrati i
        // „Naučna fantastika" i „Fantastika" kao dva žanra za poređenje.
        $uzetePozicije = [];
        $rezultat      = [];

        foreach (collect($pogodjeni)->sortBy([['pozicija', 'asc'], ['specificnost', 'desc']]) as $kandidat) {
            if (empty(array_diff($kandidat['pozicije'], $uzetePozicije))) {
                continue;
            }
            $uzetePozicije = array_merge($uzetePozicije, $kandidat['pozicije']);
            $rezultat[]    = $kandidat['zanr'];
        }

        return collect($rezultat);
    }

    /**
     * POREĐENJE POPULARNOSTI dva žanra po ukupnom broju zaduživanja (SUM
     * nad arhivom) — vraća oba broja i zaključak koji je žanr popularniji.
     */
    public function odgovorPoredjenjeZanrova(Zanr $prvi, Zanr $drugi): string
    {
        $a = $this->data->zaduzenjaZanra($prvi);
        $b = $this->data->zaduzenjaZanra($drugi);

        $opis = fn (Zanr $z, array $s) => "žanr {$z->naziv} ({$this->brojKnjiga($s['knjiga'])}) "
            . 'zaduživan je ' . $this->brojPuta($s['zaduzenja']);

        $poredjenje = ucfirst($opis($prvi, $a)) . ', a ' . $opis($drugi, $b) . '. ';

        if ($a['zaduzenja'] === $b['zaduzenja']) {
            return $poredjenje . 'Po broju zaduživanja su podjednako popularni.';
        }

        $pobednik = $a['zaduzenja'] > $b['zaduzenja'] ? $prvi : $drugi;

        return $poredjenje . "Po broju zaduživanja popularniji je žanr {$pobednik->naziv}.";
    }

    /**
     * Poređenje je traženo, ali u fondu postoji samo JEDAN od pomenutih
     * žanrova — pošten delimičan odgovor sa statistikom postojećeg žanra.
     */
    public function odgovorPoredjenjeJedanZanr(Zanr $zanr): string
    {
        $s = $this->data->zaduzenjaZanra($zanr);

        return "Od pomenutih žanrova u katalogu imamo samo žanr {$zanr->naziv} "
            . "({$this->brojKnjiga($s['knjiga'])}) — zaduživan je "
            . $this->brojPuta($s['zaduzenja'])
            . '. Drugi pomenuti žanr trenutno nemamo, pa potpuno poređenje nije moguće.';
    }

    /**
     * Knjiga sa NAJVEĆIM ODNOSOM broja rezervacija prema broju primeraka
     * (MAX nad COUNT/COUNT) — odnos se računa i ispisuje iz baze.
     */
    public function odgovorNajveciOdnosRezervacija(): string
    {
        $rezultat = $this->data->najveciOdnosRezervacijaPrimeraka();

        if ($rezultat === null) {
            return 'Trenutno nijedna knjiga nema zabeležene rezervacije, pa taj odnos još ne postoji.';
        }

        $odnos = str_replace('.', ',', (string) round($rezultat['odnos'], 2));

        return 'Najveći odnos broja rezervacija prema broju primeraka ima '
            . $this->linkKnjige($rezultat['knjiga'])
            . " — {$rezultat['rezervacije']} " . $this->recRezervacija($rezultat['rezervacije'])
            . " na {$rezultat['primeraka']} " . ($rezultat['primeraka'] === 1 ? 'primerak' : 'primeraka')
            . " (odnos {$odnos}).";
    }

    /**
     * PROSEČAN broj zaduženja po naslovu — AVG: ukupna zaduženja / broj
     * naslova, oba broja iz baze.
     */
    public function odgovorProsekZaduzenja(): string
    {
        $rezultat = $this->data->prosekZaduzenjaPoNaslovu();

        if ($rezultat === null || $rezultat['ukupno'] === 0) {
            return 'Još uvek nemamo zabeleženih zaduženja, pa prosek po knjizi ne postoji.';
        }

        $prosek = str_replace('.', ',', (string) round($rezultat['prosek'], 2));

        return "U katalogu je {$rezultat['naslova']} naslova sa ukupno {$rezultat['ukupno']} "
            . ($rezultat['ukupno'] === 1 ? 'zaduženjem' : 'zaduženja')
            . " — u proseku je svaka knjiga zadužena oko {$prosek} puta.";
    }

    /**
     * NAJČITANIJE / NAJTRAŽENIJE — jedinstven strukturni odgovor za sve
     * formulacije (nalazi B3.4, B3.5, B3.9, A3.24, A3.29). Oblik pitanja se
     * čita iz normalizovanog teksta:
     *  - pominje AUTORA → autor sa najviše ukupnih zaduženja (SUM),
     *  - pominje ŽANR → žanr sa najviše zaduženja (SUM po žanru),
     *  - „koja se knjiga najviše traži/izdaje" (jednina) → JEDNA knjiga sa
     *    najviše zaduženja — isti odgovor za sve sinonime,
     *  - inače LISTA (top N iz pitanja, podrazumevano 10); „rezervisane" se
     *    mere AKTIVNIM rezervacijama, „dostupne odmah" filtrira slobodne.
     */
    public function odgovorNajcitanije(string $norm): string
    {
        if (preg_match('/autor|pisac|spisatelj/u', $norm)) {
            $autor = $this->data->najcitanijiAutor();

            return $autor === null
                ? 'Još uvek nemamo zabeleženih zaduženja, pa statistika po autorima ne postoji.'
                : "Najviše ukupnih zaduživanja ima autor {$autor['autor']} — "
                    . $this->brojPuta($autor['zaduzenja']) . '.';
        }

        if (preg_match('/zanr/u', $norm)) {
            $zanr = $this->data->najcitanijiZanr();

            return $zanr === null
                ? 'Još uvek nemamo zabeleženih zaduženja, pa statistika po žanrovima ne postoji.'
                : "Najviše se zadužuje žanr {$zanr['naziv']} — ukupno "
                    . $this->brojPuta($zanr['zaduzenja']) . '.';
        }

        $metrika      = preg_match('/rezervis|rezervacij/u', $norm) ? 'rezervacije' : 'zaduzenja';
        $samoDostupne = (bool) preg_match('/dostupn|slobodn|odmah/u', $norm);
        $jedna        = (bool) preg_match(
            '/\bkoja\s+(se\s+|je\s+)?knjiga|najcitanija\b|najtrazenija\b|najpopularnija\b|najzaduzivanija\b/u',
            $norm
        );

        $limit = $jedna ? 1 : 10;
        if (preg_match('/\btop\s*(\d{1,2})\b|\bprv(?:ih|e)\s+(\d{1,2})\b/u', $norm, $m)) {
            $limit = max(1, (int) ($m[1] !== '' ? $m[1] : $m[2]));
            $jedna = $limit === 1;
        }

        $knjige = $this->data->najcitanijeSaBrojem($limit, $metrika, $samoDostupne);

        if ($knjige->isEmpty()) {
            return $metrika === 'rezervacije'
                ? 'Trenutno nijedna knjiga nema aktivnu rezervaciju.'
                : 'Još uvek nemamo zabeleženih zaduženja, pa statistika čitanosti ne postoji.';
        }

        $broj = fn (Knjiga $k) => $metrika === 'rezervacije'
            ? (int) $k->aktivne_rezervacije_count . ' ' . $this->recRezervacija((int) $k->aktivne_rezervacije_count)
            : $this->brojPuta((int) $k->broj_zaduzenja_count);

        if ($jedna) {
            $prva = $knjige->first();

            return $metrika === 'rezervacije'
                ? 'Trenutno je najviše rezervisana knjiga ' . $this->linkKnjige($prva)
                    . ' — ' . $broj($prva) . '.'
                : 'Najviše se traži i zadužuje ' . $this->linkKnjige($prva)
                    . ' — do sada je zaduživana ' . $broj($prva) . '.';
        }

        $stavke = $knjige->map(fn (Knjiga $k) => $this->linkKnjige($k) . ' — ' . $broj($k))->values();

        $uvod = match (true) {
            $metrika === 'rezervacije' => 'Trenutno su najviše rezervisane: ',
            $samoDostupne              => 'Najčitanije knjige koje su trenutno dostupne su: ',
            default                    => 'Najčitanije knjige u biblioteci su: ',
        };

        return $this->saOgranicenjemListe($uvod, $stavke);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POMOĆNE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Kratka lista žanra/autora (bib-izmene): do MAX_LISTA_KNJIGA stavki se
     * ispisuju SVE („Od autora X imamo 3 knjige: …"), duži spisak ispisuje
     * UKUPAN broj, prvih MAX_LISTA_KNJIGA stavki i ponudu ostatka
     * („…imamo ukupno 12 knjiga. Ovo su neke od njih: …"). Spisak se ni
     * ovde ne skraćuje prećutno — ukupan broj je uvek izrečen.
     *
     * $ostatak = true: preskače prvih MAX_LISTA_KNJIGA (već ispisanih) i
     * vraća sledeći deo spiska (dalji višak po opštem pravilu spiskova).
     */
    private function listaSaUkupnimBrojem(string $uvod, Collection $stavke, bool $ostatak): string
    {
        if ($ostatak) {
            $stavke = $stavke->slice(self::MAX_LISTA_KNJIGA)->values();

            if ($stavke->isEmpty()) {
                return 'To je bio ceo spisak — nema više stavki.';
            }

            return $this->saOgranicenjemListe('Ostatak spiska: ', $stavke);
        }

        if ($stavke->count() <= self::MAX_LISTA_KNJIGA) {
            return "{$uvod} imamo " . $this->brojKnjigaAkuzativ($stavke->count()) . ': '
                . $stavke->implode(', ') . '.';
        }

        return "{$uvod} imamo ukupno " . $this->brojKnjigaAkuzativ($stavke->count())
            . '. Ovo su neke od njih: ' . $stavke->take(self::MAX_LISTA_KNJIGA)->implode(', ')
            . '. Recite ukoliko želite ostatak spiska.';
    }

    /**
     * Sastavlja uvod + listu; lista preko MAX_STAVKI se seče UZ IZRIČITU
     * napomenu koliko je stavki izostavljeno i ponudu ostatka — spisak se
     * nikada ne skraćuje prećutno.
     *
     * $ostatak = true (nastavak razgovora „recite mi ostatak"): preskače
     * prvih MAX_STAVKI (već ispisanih) i vraća sledeći deo spiska, po
     * potrebi ponovo uz napomenu za dalji ostatak.
     */
    private function saOgranicenjemListe(string $uvod, Collection $stavke, bool $ostatak = false): string
    {
        if ($ostatak) {
            $stavke = $stavke->slice(self::MAX_STAVKI)->values();

            if ($stavke->isEmpty()) {
                return 'To je bio ceo spisak — nema više stavki.';
            }

            $uvod = 'Ostatak spiska: ';
        }

        if ($stavke->count() <= self::MAX_STAVKI) {
            return $uvod . $stavke->implode(', ') . '.';
        }

        $preostalo = $stavke->count() - self::MAX_STAVKI;

        return $uvod . $stavke->take(self::MAX_STAVKI)->implode(', ')
            . " — i još {$preostalo} " . ($preostalo === 1 ? 'stavka' : 'stavki')
            . '; recite ukoliko želite ostatak spiska.';
    }

    /**
     * ŽANROVSKA PREPORUKA („preporuči mi neki roman za vikend", „nešto
     * smešno za čitanje") — FIKSNI odgovor iz baze: najčitanije knjige
     * žanra (po broju zaduženja iz arhive) dopunjene nedavno dodatim
     * knjigama tog žanra. Model se ne poziva — za ovakva pitanja je
     * izmišljao naslove i mešao jezike (eval za_kod.md, #22 p11).
     */
    public function odgovorZanrPreporuka(Zanr $zanr): string
    {
        // Žanr sa TAČNO jednom knjigom (Potez 2, set13 p7/p17) — jasno kaži da
        // je to jedina, umesto da je serviraš kao „neke od naših knjiga".
        $sveZanra = $this->data->knjigeZanra($zanr);
        if ($sveZanra->count() === 1) {
            return "U žanru {$zanr->naziv} imamo samo jednu knjigu, pa vam mogu preporučiti samo nju: "
                . $this->linkKnjige($sveZanra->first()) . '.';
        }

        $najcitanije = $this->data->najcitanijeKnjigeZanra($zanr, 3);
        $najnovije   = $this->data->najnovijeKnjigeZanra($zanr, 3)
            ->reject(fn (Knjiga $k) => $najcitanije->contains('id', $k->id))
            ->values();

        // Žanr bez statistike čitanosti i bez knjiga u sekciji „Najnovije" →
        // preporuka od prvih nekoliko knjiga žanra (isti ton odgovora);
        // prazan žanr → pošteno „nemamo nijednu knjigu".
        if ($najcitanije->isEmpty() && $najnovije->isEmpty()) {
            $rezervne = $this->data->knjigeZanra($zanr)->take(3)->values();

            if ($rezervne->isEmpty()) {
                return "U žanru {$zanr->naziv} trenutno nemamo nijednu knjigu.";
            }

            return "Iz žanra {$zanr->naziv} preporučujem vam sledeće knjige: "
                . $rezervne->map(fn (Knjiga $k) => $this->linkKnjige($k))->implode(', ')
                . '. Recite mi ukoliko vas interesuje neki određeni naslov ili autor.';
        }

        $delovi = [];

        if ($najcitanije->isNotEmpty()) {
            $delovi[] = "Iz žanra {$zanr->naziv} preporučujem vam neke od naših najčitanijih knjiga: "
                . $najcitanije->map(fn (Knjiga $k) => $this->linkKnjige($k))->implode(', ') . '.';
        }

        if ($najnovije->isNotEmpty()) {
            $delovi[] = ($najcitanije->isEmpty()
                    ? "Iz žanra {$zanr->naziv} preporučujem vam sledeće knjige: "
                    : 'Dodao bih i knjige nedavno dodate u katalog: ')
                . $najnovije->map(fn (Knjiga $k) => $this->linkKnjige($k))->implode(', ') . '.';
        }

        $delovi[] = 'Recite mi ukoliko vas interesuje neki određeni naslov ili autor.';

        return implode(' ', $delovi);
    }

    /**
     * Detalj IMENOVANE knjige (autor / izdavač / godina / opis) — čita se
     * direktno iz baze; za prazno polje pošten odgovor, bez modela
     * (eval za_kod.md: izmišljena radnja, „izgubljeni" izdavač i godina).
     */
    public function odgovorDetaljKnjige(int $knjigaId, string $vrsta): string
    {
        $s = $this->data->stanjeKnjige($knjigaId);
        if ($s === null) {
            return 'Tu knjigu više nemamo u katalogu.';
        }

        $k    = $s['knjiga'];
        $link = $this->linkKnjige($k);
        $golLink = "[{$k->naziv_knjige}](/knjige/{$k->id})";
        $opis    = trim((string) $k->opis);

        return match ($vrsta) {
            'autor' => ($k->autor ?? '') !== ''
                ? "Knjigu {$golLink} je " . (TextUtils::jeZenskoIme((string) $k->autor) ? 'napisala' : 'napisao') . " {$k->autor}."
                : "Za knjigu {$golLink} autor nije upisan u katalog.",
            'izdavac' => ($k->izdavac ?? '') !== ''
                ? "Izdavač knjige {$link} je {$k->izdavac}."
                : "Knjiga {$link} nema navedenog izdavača, tako da na to pitanje ne mogu da vam dam odgovor.",
            'godina' => $k->godina_izdavanja
                ? "Knjiga {$link} je izdata {$k->godina_izdavanja}. godine."
                : "Za knjigu {$link} godina izdanja nije upisana u katalog, pa nemam tu informaciju.",
            // Radnja/likovi bez opisa → ISTA poštena poruka kao za „slično"
            // (eval za_kod.md, #20 p1–p9: bez prepričavanja iz opšteg znanja).
            'opis' => $opis !== ''
                ? "Evo o čemu govori {$link}: " . (mb_strlen($opis) > 600 ? mb_substr($opis, 0, 600) . '…' : $opis)
                : TextUtils::SLICNO_NEPOZNATO,
            'ima_opis' => $opis !== ''
                ? "Da, knjiga {$golLink} ima opis: " . (mb_strlen($opis) > 600 ? mb_substr($opis, 0, 600) . '…' : $opis)
                : "Trenutno nemam informacije o opisu za knjigu {$golLink}.",
            // „Koliko primeraka imate od X?" — brojevi iz svežeg stanja baze
            // (kandidati_ucenje #31 p14: pitanje je promašivalo u žanrovsku rutu).
            'primerci' => $this->odgovorPrimerciKnjige($golLink, $s),
            'zanr' => $this->odgovorZanrKnjige($k, $golLink),
            default => 'Nemam taj podatak.',
        };
    }

    /**
     * „Koliko primeraka imate od X?" — broj primeraka i njihovo stanje iz
     * baze, u obliku iz evaluacije: „…ima 1 primerak koji je slobodan, što
     * znači da nije rezervisan ili zadužen."
     *
     * @param  array{knjiga: Knjiga, ukupno: int, slobodno: int, rezervacije: int}  $s
     */
    private function odgovorPrimerciKnjige(string $golLink, array $s): string
    {
        if ($s['ukupno'] === 0) {
            return "Za knjigu {$golLink} trenutno nema unetih primeraka u katalogu.";
        }

        if ($s['ukupno'] === 1) {
            return $s['slobodno'] === 1
                ? "Knjiga {$golLink} trenutno ima 1 primerak koji je slobodan, "
                    . 'što znači da nije rezervisan ili zadužen.'
                : "Knjiga {$golLink} ima 1 primerak koji je trenutno zauzet (rezervisan ili zadužen).";
        }

        return "Knjiga {$golLink} ima {$s['ukupno']} " . $this->recPrimeraka($s['ukupno'])
            . " — slobodno je {$s['slobodno']}.";
    }

    /** „Koji je žanr knjige X?" → „Knjiga X pripada žanru Roman." */
    private function odgovorZanrKnjige(Knjiga $k, string $golLink): string
    {
        $zanrovi = $k->zanrovi->pluck('naziv');

        return match (true) {
            $zanrovi->isEmpty()     => "Za knjigu {$golLink} žanr nije upisan u katalog.",
            $zanrovi->count() === 1 => "Knjiga {$golLink} pripada žanru {$zanrovi->first()}.",
            default                 => "Knjiga {$golLink} pripada žanrovima: {$zanrovi->implode(', ')}.",
        };
    }

    /**
     * „Da li imate knjigu X" — deterministička potvrda POSTOJANJA iz baze.
     * Kada VIŠE naslova deli isto ime (npr. dve „Pesme" iz različitih žanrova),
     * nabrajaju se SVE, sa žanrom radi razlikovanja — model je od istoimenih
     * knjiga prikazivao samo jednu (ručno testiranje 2026-07-21).
     *
     * @param  Collection<int, int>  $ids
     */
    public function odgovorPostojanjeKnjige(Collection $ids): string
    {
        $knjige = Knjiga::with('zanrovi')
            ->whereIn('id', $ids->all())
            ->get()
            ->sortBy('id')
            ->values();

        if ($knjige->isEmpty()) {
            return 'Tu knjigu trenutno nemamo u katalogu.';
        }

        if ($knjige->count() === 1) {
            return 'Da, u fondu imamo knjigu ' . $this->linkKnjige($knjige->first()) . '.';
        }

        // Više istoimenih (ili srodnih) naslova → nabroji SVE, sa žanrom da
        // korisnik razlikuje koje su knjige u pitanju.
        $stavke = $knjige->map(function (Knjiga $k) {
            $link = $this->linkKnjige($k);
            $zanr = $k->zanrovi->pluck('naziv')->implode(', ');

            return $zanr !== '' ? "{$link} — žanr {$zanr}" : $link;
        });

        return 'Da, u fondu imamo ' . $this->brojKnjigaAkuzativ($knjige->count())
            . ' pod tim naslovom: ' . $stavke->implode('; ') . '.';
    }

    private function linkKnjige(Knjiga $k): string
    {
        $link  = "[{$k->naziv_knjige}](/knjige/{$k->id})";
        // Autor ume da ima prelom reda (dva koautora upisana u dva reda) —
        // sažmi razmake da se ne pojavi prelom usred rečenice (set6 p23).
        $autor = trim((string) preg_replace('/\s+/u', ' ', (string) ($k->autor ?? '')));

        return $autor !== '' ? "{$link} ({$autor})" : $link;
    }

    /**
     * KURIRANI lokativ / genitiv poznatih gradova izdavanja (normalizovan
     * naziv → padežni oblik) — prirodnije „u Beogradu" / „van Beograda" umesto
     * bezbednog ali nezgrapnog „u mestu Beograd". Grad koga NEMA u mapi ostaje
     * u obliku „u mestu X" / „van mesta X" (bez pogađanja padeža).
     *
     * @var array<string, array{lok: string, gen: string}>
     */
    private const PADEZI_MESTA = [
        'beograd'            => ['lok' => 'u Beogradu',            'gen' => 'Beograda'],
        'novi sad'           => ['lok' => 'u Novom Sadu',          'gen' => 'Novog Sada'],
        'nis'                => ['lok' => 'u Nišu',                 'gen' => 'Niša'],
        'kragujevac'         => ['lok' => 'u Kragujevcu',          'gen' => 'Kragujevca'],
        'subotica'           => ['lok' => 'u Subotici',            'gen' => 'Subotice'],
        'kraljevo'           => ['lok' => 'u Kraljevu',            'gen' => 'Kraljeva'],
        'zrenjanin'          => ['lok' => 'u Zrenjaninu',          'gen' => 'Zrenjanina'],
        'pancevo'            => ['lok' => 'u Pančevu',             'gen' => 'Pančeva'],
        'cacak'              => ['lok' => 'u Čačku',               'gen' => 'Čačka'],
        'novi pazar'         => ['lok' => 'u Novom Pazaru',        'gen' => 'Novog Pazara'],
        'leskovac'           => ['lok' => 'u Leskovcu',            'gen' => 'Leskovca'],
        'valjevo'            => ['lok' => 'u Valjevu',             'gen' => 'Valjeva'],
        'krusevac'           => ['lok' => 'u Kruševcu',            'gen' => 'Kruševca'],
        'vranje'             => ['lok' => 'u Vranju',              'gen' => 'Vranja'],
        'uzice'              => ['lok' => 'u Užicu',               'gen' => 'Užica'],
        'sombor'             => ['lok' => 'u Somboru',             'gen' => 'Sombora'],
        'pirot'              => ['lok' => 'u Pirotu',              'gen' => 'Pirota'],
        'zajecar'            => ['lok' => 'u Zaječaru',            'gen' => 'Zaječara'],
        'smederevo'          => ['lok' => 'u Smederevu',           'gen' => 'Smedereva'],
        'jagodina'           => ['lok' => 'u Jagodini',            'gen' => 'Jagodine'],
        'loznica'            => ['lok' => 'u Loznici',             'gen' => 'Loznice'],
        'sremska mitrovica'  => ['lok' => 'u Sremskoj Mitrovici',  'gen' => 'Sremske Mitrovice'],
        'sremski karlovci'   => ['lok' => 'u Sremskim Karlovcima', 'gen' => 'Sremskih Karlovaca'],
        'gornji milanovac'   => ['lok' => 'u Gornjem Milanovcu',   'gen' => 'Gornjeg Milanovca'],
        'sarajevo'           => ['lok' => 'u Sarajevu',            'gen' => 'Sarajeva'],
        'zagreb'             => ['lok' => 'u Zagrebu',             'gen' => 'Zagreba'],
        'podgorica'          => ['lok' => 'u Podgorici',           'gen' => 'Podgorice'],
        'cetinje'            => ['lok' => 'na Cetinju',            'gen' => 'Cetinja'],
    ];

    /** „u Beogradu" za poznat grad; inače bezbedno „u mestu Beograd". */
    private function uMestu(string $grad): string
    {
        $padez = self::PADEZI_MESTA[TextUtils::normalize($grad)]['lok'] ?? null;

        return $padez ?? "u mestu {$grad}";
    }

    /** „van Beograda" za poznat grad; inače bezbedno „van mesta Beograd". */
    private function vanMesta(string $grad): string
    {
        $padez = self::PADEZI_MESTA[TextUtils::normalize($grad)]['gen'] ?? null;

        return $padez !== null ? "van {$padez}" : "van mesta {$grad}";
    }

    /** „1 knjiga", „2 knjige", „5 knjiga" — srpska množina (nominativ). */
    private function brojKnjiga(int $n): string
    {
        if ($n === 1) {
            return '1 knjiga';
        }

        return $n . ' ' . ($this->dvaDoCetiri($n) ? 'knjige' : 'knjiga');
    }

    /**
     * „imamo 1 knjigU", „imamo 2 knjige", „imamo 5 knjiga" — oblik uz glagol
     * „imamo" (akuzativ): brojevi na 1 (osim 11) traže „knjigu".
     */
    private function brojKnjigaAkuzativ(int $n): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) {
            return $n . ' knjigu';
        }

        return $n . ' ' . ($this->dvaDoCetiri($n) ? 'knjige' : 'knjiga');
    }

    /** „1 put", „2/3/4 puta", „5 i više puta" — srpska množina za zaduženja. */
    private function brojPuta(int $n): string
    {
        return $n . ' ' . ($n % 10 === 1 && $n % 100 !== 11 ? 'put' : 'puta');
    }

    /**
     * Poklapanje reči upita sa tokenom naziva žanra: fuzzy (tipfeleri,
     * padeži) ILI prefiks od ≥ 4 slova — „krimi" pogađa „kriminalistika"
     * (skraćeni nazivi žanrova koje korisnici stvarno kucaju).
     */
    private function zanrTokenPogodak(string $recUpita, string $zanrToken): bool
    {
        return TextUtils::fuzzyJednake($recUpita, $zanrToken)
            || (mb_strlen($recUpita) >= 4 && str_starts_with($zanrToken, $recUpita));
    }

    /** „1 primerak", „2–4 primerka", „5 primeraka" — srpska množina. */
    private function recPrimeraka(int $n): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) {
            return 'primerka'; // „od 1 primerka" — uz predlog „od" ide genitiv
        }

        return $this->dvaDoCetiri($n) ? 'primerka' : 'primeraka';
    }

    /** „1 rezervacija", „2 rezervacije", „5 rezervacija" — srpska množina. */
    private function recRezervacija(int $n): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) {
            return 'rezervacija';
        }

        return $this->dvaDoCetiri($n) ? 'rezervacije' : 'rezervacija';
    }

    private function recRazlicitihKnjiga(int $n): string
    {
        if ($n === 1) {
            return 'različita knjiga';
        }

        return $this->dvaDoCetiri($n) ? 'različite knjige' : 'različitih knjiga';
    }

    /** Da li broj u srpskom traži oblik „2–4" (dve knjige), ne „5+" (pet knjiga). */
    private function dvaDoCetiri(int $n): bool
    {
        return in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true);
    }

    /** Tokeni dužine ≥ 3 (oblik koji fuzzy poređenje žanrova očekuje). @return array<int, string> */
    private function tokeniOdTriSlova(string $norm): array
    {
        return array_values(array_filter(
            TextUtils::tokenizuj($norm),
            fn (string $t) => mb_strlen($t) >= 3
        ));
    }
}