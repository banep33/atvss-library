<?php

namespace App\Support;

/**
 * Preslovljavanje latinica → ćirilica na SERVERU.
 *
 * Do sada je posao radio isključivo public/js/pismo.js, i to na
 * `DOMContentLoaded` — dakle pošto je stranica već iscrtana. Korisnik sa
 * izabranom ćirilicom video bi ceo sadržaj prvo u latinici, pa prelom u
 * ćirilicu. Ovde se isti posao radi pre slanja odgovora, pa prvo iscrtavanje
 * već ima ispravno pismo.
 *
 * PARITET: pravila su verno preslikana iz pismo.js (digrafi, izuzeci,
 * preslikavanje veličine slova). Test tests/Unit/PismoParitetTest.php pušta
 * korpus snimljen POKRETANJEM pismo.js kroz node — ako se dve strane raziđu,
 * test pada. Zato svaka izmena pravila mora da ide u OBA fajla i da se
 * regeneriše korpus (uputstvo u testu).
 */
class Pismo
{
    /** Latinično slovo → ćirilično (posle zamene digrafa). */
    private const SLOVA = [
        'A' => 'А', 'B' => 'Б', 'V' => 'В', 'G' => 'Г', 'D' => 'Д', 'Đ' => 'Ђ',
        'E' => 'Е', 'Ž' => 'Ж', 'Z' => 'З', 'I' => 'И', 'J' => 'Ј', 'K' => 'К',
        'L' => 'Л', 'M' => 'М', 'N' => 'Н', 'O' => 'О', 'P' => 'П', 'R' => 'Р',
        'S' => 'С', 'T' => 'Т', 'Ć' => 'Ћ', 'U' => 'У', 'F' => 'Ф', 'H' => 'Х',
        'C' => 'Ц', 'Č' => 'Ч', 'Š' => 'Ш',
        'a' => 'а', 'b' => 'б', 'v' => 'в', 'g' => 'г', 'd' => 'д', 'đ' => 'ђ',
        'e' => 'е', 'ž' => 'ж', 'z' => 'з', 'i' => 'и', 'j' => 'ј', 'k' => 'к',
        'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о', 'p' => 'п', 'r' => 'р',
        's' => 'с', 't' => 'т', 'ć' => 'ћ', 'u' => 'у', 'f' => 'ф', 'h' => 'х',
        'c' => 'ц', 'č' => 'ч', 'š' => 'ш',
    ];

    /** Digrafi idu PRE pojedinačnih slova, inače bi „lj" dalo „лј". */
    private const DIGRAFI = [
        'Lj' => 'Љ', 'LJ' => 'Љ', 'lj' => 'љ',
        'Nj' => 'Њ', 'NJ' => 'Њ', 'nj' => 'њ',
        'Dž' => 'Џ', 'DŽ' => 'Џ', 'dž' => 'џ',
    ];

    /** Reči sa ustaljenim ćiriličnim oblikom (ostalo prolazi slovo po slovo). */
    private const RECI = [
        'email'   => 'имејл',
        'e-mail'  => 'имејл',
        'excel'   => 'ексел',
        'online'  => 'онлајн',
        'offline' => 'офлајн',
    ];

    private const EKSTENZIJE = 'xlsx|xls|csv|pdf|docx|doc|pptx|ppt|txt|rtf|zip|rar|'
                             . 'json|xml|html|htm|md|png|jpg|jpeg|gif|webp|svg|ico';

    private const OZNAKE = 'ISBN|ISSN|ISO|DOI|COBISS|N/A|PDF|CSV|HTML|CSS|URL';

    /**
     * Broj članske karte (CLAN-001) — identifikator, ne reč.
     *
     * Bez ovoga bi ćirilica dala „ЦЛАН-001": broj koji član pokazuje na
     * pultu, kuca pri preuzimanju e-knjige i po kom ga bibliotekar traži u
     * spisku, prestao bi da se poklapa sa onim što piše u bazi i na kartici.
     * Format je isti onaj koji generiše ClanController (CLAN- + cifre).
     */
    private const BROJ_KARTICE = 'CLAN-[0-9]+';

    /** Elementi čiji sadržaj po HTML specifikaciji NIJE markup. */
    private const SIROV_TEKST = ['script', 'style', 'textarea'];

    /** Elementi čiji se sadržaj NE preslovljava (ogledalo SKIP_TAGS iz pismo.js). */
    private const PRESKOCI_TAGOVE = ['code', 'pre', 'noscript'];

    /**
     * Prazni (void) elementi — nemaju sadržaj ni zatvarajući tag.
     *
     * Bez ove liste `data-no-tr` na, recimo, <img> otvara preskakanje koje
     * čeka </img> — tag koji ne postoji — pa ostatak stranice ostane
     * nepreslovljen. Isto važi za <input>, <br> i ostale iz spiska.
     */
    private const PRAZNI_ELEMENTI = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** Tekst koji sadrži e-adresu ili URL ostaje netaknut. */
    private const PRESKOCI_TEKST = '/[\w.+-]+@|https?:\/\//';

    /**
     * Preslovljava jedan komad teksta (ne HTML — vidi preslovljajHtml()).
     */
    public function uCirilicu(string $tekst): string
    {
        if ($tekst === '') {
            return $tekst;
        }

        /* Delimiter je # a ne / , jer lista oznaka sadrzi "N/A" - sa kosom
           crtom kao delimiterom regex bi se prekinuo na njoj i izuzeci se
           ne bi ni primenili (uhvaceno testom pariteta). */
        $izuzetak = '#[A-Za-z0-9_\x{00C0}-\x{024F}-]*\.(?:' . self::EKSTENZIJE . ')\b'
                  . '|\b(?:' . self::BROJ_KARTICE . ')\b'
                  . '|\b(?:' . self::OZNAKE . ')\b'
                  . '|\b(?:' . implode('|', array_map(
                        static fn (string $r) => preg_quote($r, '#'),
                        array_keys(self::RECI)
                    )) . ')\b#iu';

        $izlaz = '';
        $od    = 0;

        if (preg_match_all($izuzetak, $tekst, $pogoci, PREG_OFFSET_CAPTURE)) {
            foreach ($pogoci[0] as [$deo, $poz]) {
                $izlaz .= $this->osnovno(substr($tekst, $od, $poz - $od));
                $izlaz .= $this->izuzetak($deo);
                $od     = $poz + strlen($deo);
            }
        }

        return $izlaz . $this->osnovno(substr($tekst, $od));
    }

    /**
     * Preslovljava VIDLJIV tekst u HTML dokumentu: sadržaj elemenata i
     * atribute `title` i `placeholder`. Ne dira markup, atribute sa
     * vrednostima (href, class, id…), sadržaj skripti i stilova, niti
     * podstabla označena sa `data-no-tr`.
     */
    public function preslovljajHtml(string $html): string
    {
        $izlaz          = '';
        $poz            = 0;
        $duzina         = strlen($html);
        $preskoci       = null;   // naziv elementa čiji sadržaj preskačemo
        $dubinaPreskoka = 0;

        while ($poz < $duzina) {
            $pocetakTaga = strpos($html, '<', $poz);

            if ($pocetakTaga === false) {
                $izlaz .= $preskoci ? substr($html, $poz) : $this->tekstNode(substr($html, $poz));
                break;
            }

            $tekst  = substr($html, $poz, $pocetakTaga - $poz);
            $izlaz .= $preskoci ? $tekst : $this->tekstNode($tekst);

            /* Komentar, DOCTYPE i procesne instrukcije nisu tagovi i ne smeju
               kroz preslovljavanje — komentar se traži do „-->", jer sme da
               sadrži i „>" u sebi. */
            if (substr_compare($html, '<!--', $pocetakTaga, 4) === 0) {
                $krajKomentara = strpos($html, '-->', $pocetakTaga);
                $kraj          = $krajKomentara === false ? $duzina : $krajKomentara + 3;
                $izlaz        .= substr($html, $pocetakTaga, $kraj - $pocetakTaga);
                $poz           = $kraj;
                continue;
            }

            if (substr_compare($html, '<!', $pocetakTaga, 2) === 0
                || substr_compare($html, '<?', $pocetakTaga, 2) === 0) {
                $krajDekl = strpos($html, '>', $pocetakTaga);
                $kraj     = $krajDekl === false ? $duzina : $krajDekl + 1;
                $izlaz   .= substr($html, $pocetakTaga, $kraj - $pocetakTaga);
                $poz      = $kraj;
                continue;
            }

            $krajTaga = strpos($html, '>', $pocetakTaga);
            if ($krajTaga === false) {
                $izlaz .= substr($html, $pocetakTaga);
                break;
            }

            $tag = substr($html, $pocetakTaga, $krajTaga - $pocetakTaga + 1);
            $poz = $krajTaga + 1;

            if (! preg_match('/^<(\/?)([a-zA-Z][\w:-]*)/', $tag, $m)) {
                // Nije tag nego znak „<" u tekstu (npr. „a < b") — prepiši ga.
                $izlaz .= $preskoci ? $tag : $this->tekstNode($tag);
                continue;
            }

            $zatvara = $m[1] === '/';
            $naziv   = strtolower($m[2]);

            if ($preskoci !== null) {
                if ($naziv === $preskoci) {
                    $dubinaPreskoka += $zatvara ? -1 : 1;
                    if ($dubinaPreskoka === 0) {
                        $preskoci = null;
                    }
                }
                $izlaz .= $tag;
                continue;
            }

            if ($zatvara) {
                $izlaz .= $tag;
                continue;
            }

            $imaNoTr = (bool) preg_match('/\sdata-no-tr\b/i', $tag);

            // Placeholder i title su vidljiv tekst i kod polja čiji se
            // sadržaj preskače — osim ako je element označen sa data-no-tr.
            if (! $imaNoTr) {
                $tag = $this->preslovljajAtribute($tag);
            }

            $izlaz .= $tag;

            if (str_ends_with(rtrim($tag), '/>') || in_array($naziv, self::PRAZNI_ELEMENTI, true)) {
                continue;   // element bez sadržaja — nema šta da se preskače
            }

            /* RAW TEXT elementi (script/style/textarea): njihov sadržaj po
               specifikaciji nije markup, pa se NE sme tokenizovati. Bez ovoga
               bi „i < l.length" u skripti izgledalo kao početak taga i
               progutalo `</script>` — a sa njim i preslovljavanje ostatka
               stranice (uhvaćeno na pravoj stranici). */
            if (in_array($naziv, self::SIROV_TEKST, true)) {
                $zatvarajuci = stripos($html, '</' . $naziv, $poz);
                $kraj        = $zatvarajuci === false ? $duzina : $zatvarajuci;
                $izlaz      .= substr($html, $poz, $kraj - $poz);
                $poz         = $kraj;
                continue;
            }

            if ($imaNoTr || in_array($naziv, self::PRESKOCI_TAGOVE, true)) {
                $preskoci       = $naziv;
                $dubinaPreskoka = 1;
            }
        }

        return $izlaz;
    }

    /** Tekst između tagova — e-adrese i URL-ovi se ne diraju. */
    private function tekstNode(string $tekst): string
    {
        if (trim($tekst) === '' || preg_match(self::PRESKOCI_TEKST, $tekst)) {
            return $tekst;
        }

        /* HTML entiteti (&times;, &nbsp;, &#215;) ostaju netaknuti: preslovljen
           entitet („&тимес;") prestaje da bude entitet i ispisuje se doslovno.
           Klijentska strana ovaj problem nema — pregledač joj daje već
           dekodiran tekst („×"), koji nema latiničnih slova. */
        $delovi = preg_split(
            '/(&(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});)/',
            $tekst,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($delovi === false) {
            return $this->uCirilicu($tekst);
        }

        $izlaz = '';
        foreach ($delovi as $i => $deo) {
            // Neparni delovi su uhvaćeni entiteti — prepisuju se doslovno.
            $izlaz .= ($i % 2 === 1) ? $deo : $this->uCirilicu($deo);
        }

        return $izlaz;
    }

    private function preslovljajAtribute(string $tag): string
    {
        return (string) preg_replace_callback(
            '/\s(title|placeholder|alt)=(["\'])(.*?)\2/is',
            function (array $m): string {
                if (preg_match(self::PRESKOCI_TEKST, $m[3])) {
                    return $m[0];
                }

                return ' ' . $m[1] . '=' . $m[2] . $this->uCirilicu($m[3]) . $m[2];
            },
            $tag
        );
    }

    /** Slovo po slovo, sa digrafima — bez izuzetaka. */
    private function osnovno(string $tekst): string
    {
        if ($tekst === '') {
            return $tekst;
        }

        $tekst = strtr($tekst, self::DIGRAFI);

        return strtr($tekst, self::SLOVA);
    }

    /**
     * Pogodak izuzetka: reč iz mape dobija ćirilični oblik sa zatečenom
     * veličinom slova; naziv fajla i oznaka (ISBN, N/A) ostaju u latinici.
     */
    private function izuzetak(string $deo): string
    {
        $rec = self::RECI[mb_strtolower($deo, 'UTF-8')] ?? null;

        return $rec === null ? $deo : $this->primeniVelicinu($deo, $rec);
    }

    /** EMAIL → ИМЕЈЛ, Email → Имејл, email → имејл. */
    private function primeniVelicinu(string $uzor, string $rec): string
    {
        if (mb_strlen($uzor, 'UTF-8') > 1
            && $uzor === mb_strtoupper($uzor, 'UTF-8')
            && $uzor !== mb_strtolower($uzor, 'UTF-8')) {
            return mb_strtoupper($rec, 'UTF-8');
        }

        $prvo = mb_substr($uzor, 0, 1, 'UTF-8');

        if ($prvo === mb_strtoupper($prvo, 'UTF-8') && $prvo !== mb_strtolower($prvo, 'UTF-8')) {
            return mb_strtoupper(mb_substr($rec, 0, 1, 'UTF-8'), 'UTF-8')
                 . mb_substr($rec, 1, null, 'UTF-8');
        }

        return $rec;
    }
}
