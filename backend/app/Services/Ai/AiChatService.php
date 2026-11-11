<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Knjiga;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AiChatService — ROUTER pipeline-a (sloj 2) + biznis logika chata.
 *
 * SLOJEVI (ciljna arhitektura):
 *   1. IntentClassifier  — klasifikacija namere + parametri (bez pretrage),
 *   2. Router            — retrieveContext(): na osnovu namere bira handler,
 *   3. Handleri          — StrukturniUpiti (strukturna pitanja, fiksni
 *                          odgovori), semantički tok (RagService), auth-gate
 *                          i van-tematsko odbijanje (fiksne poruke),
 *   4. Zajednički servisi — LibraryDataService (SVI upiti nad bazom),
 *                          RagClient → Python /retrieve (SVA vektorska
 *                          pretraga; RagService + VectorStore samo indeksiraju).
 *
 * TOK retrieveContext():
 *   0. klasifikacija (IntentClassifier, deterministički) → auth-gate za
 *      gosta, datumski opseg nad arhivom, ostatak skraćenog spiska;
 *   1. Python mikroservis /retrieve — pretprocesiranje (ćirilica→latinica,
 *      normalizacija dijakritika, langdetect) + embedding OČIŠĆENOG upita
 *      + sqlite-vec KNN (sirovi top-K semantički pogoci, bez praga).
 *      Nije srpski → understood=false → "Nisam razumeo poruku", bez modela;
 *   2. KatalogPretraga — deterministički leksički sloj (naslov/autor/žanr,
 *      score 1.0); za očigledan NASTAVAK bez sopstvenih pogodaka pretraga
 *      se ponavlja nad prethodnom razmenom (kontekst razgovora);
 *   2.1 strukturni upiti (statistika, lista žanra, broj knjiga sa rečju,
 *      naslovi na slovo, godina izdanja, „ko je zadužio") → SQL/ORM +
 *      fiksni odgovor, bez modela;
 *   2.2 čisto TEMATSKO pitanje dok u fondu nema nijednog opisa → pošten
 *      fiksni odgovor (tema se čita iz opisa — žanr NIJE tema, Nalaz 1);
 *   3. prag ai.rag_min_score + filter ravnog šuma nad pogocima iz koraka 1;
 *      3.5 imenovana knjiga (anti-halucinacija);
 *   4. kontra-upit / „ko je X" / van teme → fiksni odgovori (nastavak
 *      razgovora se ne odbija kao van teme);
 *   5. spajanje pogodaka + preporuke + agregati/liste/stanje naloga.
 *   → buildMessages(): jedan system prompt (SystemPromptService) sa celim
 *     kontekstom + istorija + pitanje → getReply()/streamReply(): Ollama.
 *
 * OTPORNOST: ako mikroservis nije dostupan, LEKSIČKA pretraga i dalje radi
 * (PHP normalizacija u TextUtils::normalize), samo semantika izostaje.
 *
 * Perzistencija razgovora (ai_conversations / ai_messages) je SAMO za
 * ulogovane članove — gost nema user_id.
 *
 * Controller ostaje odgovoran samo za HTTP sloj: validaciju Request-a,
 * JSON/SSE response building i output buffering za streaming.
 */
class AiChatService
{
    /**
     * Ispod ovog sirovog cosine score-a semantika se ne računa ni kao TRAG
     * namere (koristi se samo za odluku o kontra-upitu, ne za pogotke).
     * Izmereno: besmislen unos („asdf qwert") dobija šum do ~0.55, pa prag
     * traga mora biti iznad toga.
     */
    private const MIN_TRAG_SCORE = 0.60;

    /** Vrh semantike od koga se pogoci prihvataju i bez odvajanja od dna. */
    private const SEM_VISOK_SCORE = 0.70;

    /** Minimalno odvajanje vrha od dna sirove liste da semantika nije ravan šum. */
    private const SEM_MIN_RASPON = 0.03;

    /**
     * Prag pokrivenosti tokena upita pitanjem few-shot para (FaqZnanje) od
     * koga se pitanje tretira kao FAQ i kada ga regex klasifikacija nije
     * prepoznala — takvo pitanje se nikada ne odbija kao van teme, već ide
     * kroz model sa FAQ primerima u promptu (eval za_kod.md). 0.6 = većina
     * sadržajnih reči pitanja postoji u nekom zvaničnom P/O paru.
     */
    private const FAQ_MD_PRAG = 0.6;

    public function __construct(
        private readonly OllamaClient        $ollama,
        private readonly SystemPromptService $promptService,
        private readonly RagClient           $ragClient,
        private readonly KatalogPretraga     $katalog,
        private readonly StrukturniUpiti     $strukturni,
        private readonly IntentClassifier    $classifier,
        private readonly LibraryDataService  $data,
        private readonly FaqZnanje           $faqZnanje,
        private readonly NauceniPrimeriService $nauceni,
    ) {}

    /**
     * Pre-LLM deo pipeline-a: klasifikacija namere → rutiranje na handler →
     * pretraga i prikupljanje celog konteksta za system prompt.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     *         prethodne poruke razgovora — klasifikacija ih koristi za
     *         razrešavanje nastavaka („a za prošlu nedelju?", „koje još?")
     *
     * @return array{
     *     understood: bool,
     *     poruka: ?string,
     *     knjige: Collection,
     *     preporuke: Collection,
     *     agregati: array,
     *     najnovije: ?Collection,
     *     najcitanije: ?Collection,
     *     clan: ?array,
     * }
     */
    public function retrieveContext(string $message, array $history = []): array
    {
        $sirovi = [];
        $norm   = TextUtils::normalize($message);

        // 0) KLASIFIKACIJA NAMERE (sloj 1, deterministički, pre svake
        //    pretrage). PHP normalizacija je ogledalo one u mikroservisu,
        //    pa klasifikacija radi i pre (i bez) mikroservisa.
        $namera = $this->classifier->klasifikuj($norm, $message, $history);

        // 0.1) FAQ POGODAK IZ FEW-SHOT FAJLA (nezavisno od regex klasifikacije):
        //      pitanje čije sadržajne reči dobro pokriva PITANJE nekog zvaničnog
        //      P/O para ima odgovor u few-shot fajlu — nikada se ne odbija kao
        //      "van teme" / "nisam razumeo", već ide kroz model sa FAQ primerima
        //      u promptu. Time novo, prvi put viđeno FAQ pitanje dobija miran i
        //      tačan odgovor čim se par doda u fajl, bez izmene koda.
        $faqIzMd = $this->faqZnanje->najboljaPokrivenost($norm) >= self::FAQ_MD_PRAG;

        // 0a) NASTAVAK — OSTATAK SPISKA: „recite mi ostatak" ponovo izvršava
        //     nameru PRETHODNOG pitanja sa pomerajem (preskače ispisano).
        if ($namera->ostatakOd !== null
            && ($ostatak = $this->ostatakSpiska($namera->ostatakOd)) !== null) {
            return $this->fiksniOdgovor($ostatak);
        }

        // 0b) AUTH-GATE (deterministički, pre svake pretrage): NEULOGOVANI
        //     posetilac traži akciju koja zahteva nalog (rezervacija,
        //     zaduženje, pozajmica) ILI pita o SVOJIM rezervacijama/roku →
        //     fiksni odgovor, model se ne poziva i tabeli rezervacija se ne
        //     pristupa (bez pokušaja da se korisnik prepozna iz pitanja).
        //     Informativna pitanja (pretrage, statistike) gost sme da postavlja.
        if ($this->resolveGuestMode()
            && ($namera->akcioniZahtev || $namera->mojeRezervacijeUpit)) {
            return $this->fiksniOdgovor(TextUtils::AUTH_REQUIRED);
        }

        // 0b0) EVIDENCIJA ZADUŽENJA (deterministički, PRE svake pretrage):
        //      „ko je zadužio knjigu X" otkriva ime člana i datum pozajmice.
        //      To je lični podatak koji van asistenta postoji samo u
        //      administrativnom delu aplikacije, iza BibliotekarMiddleware-a.
        //      Provera se namerno izvršava OVDE — pre leksičke i semantičke
        //      pretrage i pre poziva modelu — da odgovor na neovlašćen upit ne
        //      zavisi od toga da li je naslov pronađen u katalogu (inače bi
        //      razlika između „nije dostupno" i normalnog toka sama odavala
        //      postojanje zapisa). Isti kriterijum kao u ostatku sistema, pa
        //      prirodni jezik ne otvara put do podatka koji korisnik nema.
        if ($namera->koJeZaduzio && !$this->smeDaVidiEvidencijuZaduzenja()) {
            return $this->fiksniOdgovor(TextUtils::EVIDENCIJA_PRIVATNA);
        }

        // 0b1) IDENTITET (deterministički, Faza 2): „ko si ti / da li si AI" →
        //      fiksni odgovor (Bib), model se ne poziva — nikad ne kaže da je
        //      program/model/AI niti se predstavlja kao neko drugi.
        if ($namera->identitetUpit) {
            return $this->fiksniOdgovor(TextUtils::IDENTITET);
        }

        // 0b1a) KONTAKT (deterministički): ime bibliotekarke, mejl i telefon su
        //       nepromenljive činjenice — ne prepričavaju se kroz slab model
        //       (koji je parafraze „sa kim da razgovaram", „postoji li mejl ili
        //       telefon" umeo da promaši), već se vraća fiksni kontakt.
        if ($namera->kontaktUpit) {
            return $this->fiksniOdgovor(TextUtils::KONTAKT);
        }

        // 0b1b) FIKSNA FAQ TEMA (Faza 4 — FAQ determinizam): nepromenljive
        //       činjenice sa distinktivnom leksikom (reset lozinke, otkazivanje
        //       rezervacije, članarina, ne-prodaja, učlanjenje) → fiksni odgovor.
        //       Slab model ih je mrcvario, a strogi leksički matcher ih je
        //       promašivao zbog klase upitne reči — zato deterministički gejt.
        if ($namera->faqFiksnaTema !== null
            && isset(TextUtils::FAQ_TEME[$namera->faqFiksnaTema])) {
            return $this->fiksniOdgovor(TextUtils::FAQ_TEME[$namera->faqFiksnaTema]);
        }

        // 0b1c) SOPSTVENE REZERVACIJE ČLANA (bib-izmene tačka 1): prijavljen
        //       član pita o svojim rezervacijama/statusu/roku → FIKSNI odgovor
        //       iz baze, filtriran po user_id iz POUZDANE serverske sesije
        //       (auth()->id()), nikada iz pitanja/frontenda. Gost je već
        //       presretnut auth-gate-om (0b), pa dovde stiže samo član. Model
        //       se ne poziva — podaci o rezervacijama se ne nagađaju.
        if ($namera->mojeRezervacijeUpit && !$this->resolveGuestMode()) {
            return $this->odgovorMojNalog();
        }

        // 0b2) TAČAN FAQ POGODAK (deterministički): SVE sadržajne reči pitanja
        //      pokrivene PITANJEM zvaničnog P/O para → odgovor iz few-shot
        //      fajla se vraća FIKSNO, bez modela. Model je i uz primere u
        //      promptu umeo da odgovori na pogrešno pitanje ili izmisli
        //      pravilo (kandidati_ucenje: #32 p4, p14–p18). Uvodno „Da./Ne,"
        //      iz odgovora se skida kada pitanje nije da/ne oblika („Kako da
        //      otkažem rezervaciju?" ne sme da počne sa „Da.").
        if (($namera->faq || $faqIzMd)
            && !$namera->tematsko
            && $namera->imenovanaKnjiga === null
            && ($faqOdgovor = $this->faqZnanje->direktanOdgovor($norm)) !== null) {
            return $this->fiksniOdgovor($this->bezDaNePrefiksa($faqOdgovor, $norm));
        }

        // 0b3) SEMANTIČKI DIREKTAN FAQ ODGOVOR (Faza B, preporučeni put za
        //      3b/CPU): parafrazu koju leksičko 0b2 promaši (drugi red reči,
        //      sinonimi), a jako je slična kuriranom paru, semantika pogodi i
        //      vrati kurirani odgovor DIREKTNO — bez modela. Rasterećuje hardver
        //      (fiksni odgovor umesto 30–50 s inferencije) i ne zavisi od toga
        //      da li slab model ume da iskoristi primer. Prag + margina (servis)
        //      štite od pogrešnog para kod „zbijenih" cosine skorova; katalog i
        //      tematska pitanja se preskaču (embed samo za opšta/FAQ pitanja).
        if (config('ai.nauceni_direktno')
            && !$namera->tematsko && !$namera->katalosko
            && $namera->imenovanaKnjiga === null
            && ($semOdg = $this->nauceni->direktanOdgovor($message)) !== null) {
            return $this->fiksniOdgovor($this->bezDaNePrefiksa($semOdg, $norm));
        }

        // 0b4) VAN KATALOGA (scope, deterministički): pitanja o podacima kojih
        //      u katalogu nema — globalne/spoljne procene („najpopularniji žanr
        //      u Srbiji", „najčitanija knjiga na svetu", „najbolja svih vremena",
        //      „najviše prodatih na svetu"). Bot pošteno kaže da raspolaže samo
        //      katalogom, umesto da katalošku statistiku predstavi kao globalnu
        //      (kandidati_ucenje_5, korpa F). PRE superlativnih ruta
        //      (najčitanije, izdavacStatistika) da im ne preotmu pitanje.
        if ($namera->vanKataloga) {
            return $this->fiksniOdgovor(TextUtils::VAN_KATALOGA);
        }

        // 0b5) DUBLJA ANALITIKA po godini / mestu / izdavaču (svesna granica):
        //      ukrštanja tih sekundarnih osa (izdavač×žanr, autor/žanr×godina,
        //      grad×popularnost, prosek/egzistencija po izdavaču…) NAMERNO ne
        //      radimo — umesto pogrešnog jednoosnog odgovora ili halucinacije,
        //      pošteno se odbija i nudi pretraga po naslovu/autoru/žanru
        //      (kandidati_ucenje_5, korpa E). PRE osnovnih statističkih ruta
        //      (autorStatistika, izdavacStatistika) koje bi ukršteno pitanje
        //      shvatile jednoosno i vratile pogrešan broj.
        if ($namera->dubljaAnalitika) {
            return $this->fiksniOdgovor(TextUtils::DUBLJA_ANALITIKA);
        }

        // 0c) DATUMSKI OPSEG NAD ARHIVOM (deterministički): „koje knjige su
        //     zadužene pre nedelju dana / 2 dana…" → relativni izraz je
        //     parsiran u opseg (IntentClassifier) i arhiva se filtrira
        //     SQL-om po datumu. Preko mesec dana → fiksna poruka o limitu.
        if ($namera->datumskiOpseg !== null) {
            if ($namera->datumskiOpseg['preko_limita'] ?? false) {
                return $this->fiksniOdgovor(TextUtils::ARHIVA_LIMIT);
            }

            return $this->fiksniOdgovor(
                $this->strukturni->odgovorZaduzeneUOpsegu($namera->datumskiOpseg['dana'])
            );
        }

        // 0d) „SPISAK SVIH KNJIGA" (deterministički): ceo fond se ne ispisuje
        //     — broj knjiga i primeraka + poziv na sužavanje (eval za_kod.md).
        //     Kada je uz „sve knjige" navedena GODINA („daj mi sve knjige
        //     izdate između 2010 i 2020"), pitanje NIJE ceo fond — rešava ga
        //     godišnji tok (c3).
        if ($namera->godinaOpseg === null
            && preg_match('/spis(ak|ka) svih knjig|sv(e|ih) knjig[ae] koje (imate|imas)|daj mi sve knjige|listu svih knjig/u', $norm)) {
            $a = $this->data->agregatiKataloga();
            return $this->fiksniOdgovor(
                "U našem katalogu imamo {$a['knjiga']} različitih knjiga sa ukupno "
                . "{$a['primeraka']} primeraka — ceo spisak je predugačak za poruku. "
                . 'Recite mi ukoliko vas interesuje određeni žanr, naslov ili autor, '
                . 'ili pogledajte katalog na sajtu.'
            );
        }

        // 0d5) UKUPAN BROJ KNJIGA („koliko ukupno knjiga imate u katalogu?")
        //      — čist agregat, FIKSNO iz baze: model je i uz tačne agregate u
        //      promptu umeo da vrati besmislen broj („Ukupno različitih knjiga
        //      u katalogu nema" — kandidati_ucenje #33 p7). Ruta važi SAMO
        //      kada pitanje osim broja knjiga ne traži ništa drugo (nijedna
        //      druga sadržajna reč: bez autora, žanra, reči u naslovu…).
        if ($namera->countRec === null && $namera->pocinjeSlovo === null
            && $namera->godinaOpseg === null && !$namera->tematsko
            && !$namera->najcitanije && !$namera->najnovije
            && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->imenovanaKnjiga === null
            && preg_match('/\bkoli?ko\s+(?:ukupno\s+)?(?:razlicitih\s+)?(?:knjig|naslova)/u', $norm)) {
            $ostaleReci = array_diff(
                $this->katalog->kljucneReci($norm),
                ['ukupno', 'razlicitih', 'razlicite', 'kolko', 'naslova', 'trenutno', 'tacno']
            );

            if (empty($ostaleReci)) {
                return $this->fiksniOdgovor($this->strukturni->odgovorUkupanBrojKnjiga());
            }
        }

        // 0d6) STATISTIKA / RELACIJE NAD METAPODACIMA (kandidati_ucenje_2):
        //      autor sa najviše naslova, godina sa najviše knjiga, najstarija/
        //      najnovija, poređenje dve knjige, „iste osobine kao X", mesto
        //      izdavanja — sve deterministički iz baze, model se ne poziva.
        if (($meta = $this->metapodatakOdgovor($namera)) !== null) {
            return $meta;
        }

        // 0d7) SADRŽAJNO / SUBJEKTIVNO PITANJE O KNJIZI („koja knjiga govori o
        //      ratu", „koja je najtužnija", „glavni junak lekar", „najbolja za
        //      početnike") — odgovor traži OPIS knjige; dok fond nema opise,
        //      pošten fiksni odgovor umesto izmišljanja naslova, žanr-liste ili
        //      preporuke (kandidati_ucenje_3, pitanja_set10). Kada opisi
        //      postoje, pitanje ide semantičkim tokom (kao i tematsko).
        if ($namera->sadrzajnoPitanje && $this->data->brojKnjigaSaOpisom() === 0) {
            return $this->fiksniOdgovor(TextUtils::SLICNO_NEPOZNATO);
        }

        // 0e) GENERIČKA PREPORUKA („preporuči mi nešto baš dobro") — bez
        //     žanra, autora, teme i naslova: FIKSNI odgovor iz baze
        //     (najčitanije + nedavno dodate knjige) — model je i za ovako
        //     jednostavno pitanje umeo da izmišlja naslove i meša jezike
        //     (eval za_kod.md, set1 #24 i #22 p11). NE važi kada je pomenut
        //     izdavač, godina ili tražimo „još knjigu [autora]" — to su
        //     konkretni filteri (kandidati_ucenje_2 #35 p12/p13).
        // 0d9) NAJČEŠĆE PREPORUČENE (set13 p25): „koje tri knjige najčešće
        //      preporučujete korisnicima" → prve tri najčitanije, bez repа
        //      „nedavno dodate".
        if (preg_match('/\bnajcesce\b.{0,25}preporuc|preporuc\w*.{0,25}\bnajcesce\b|\btri\b.{0,20}(?:najpopularnij|najcitanij|najtrazenij)/u', $norm)) {
            return $this->odgovorNajcescePreporucene();
        }

        // 0d10) DRUGI NAJČITANIJI ŽANR (set13 p3): „koji je najčitaniji žanr posle
        //       romana" → žanr na 2. mestu po zaduženjima.
        if ((preg_match('/(?:najcitanij|najpopularnij|najtrazenij)\w*\s+zanr|zanr\w*.{0,20}(?:najcitanij|najpopularnij|najtrazenij)/u', $norm))
            && preg_match('/\bposle\b|\bnakon\b|\bdrugi\b|\bsledec\w*|\bnaredn\w*/u', $norm)) {
            return $this->odgovorDrugiNajcitanijiZanr();
        }

        // 0d11) PREPORUKA PO IMENOVANOM AUTORU (set13 p12): „preporuci mi knjigu
        //       agate kristi" — lowercase autor se ne izvlači u autorKandidat, pa
        //       se ovde izvuče fraza posle „knjigu/od" i STROGO proveri u bazi;
        //       nađen → njegove/njene knjige umesto generičke liste najčitanijih.
        //       Gejtovano SAMO na preporuku bez žanra/mesta/godine (druge
        //       slučajeve rešavaju svoje rute), pa ne dira ne-preporuc pitanja.
        if (preg_match('/preporuc|predloz/u', $norm)
            && !$namera->zanrovskiUpit && !$namera->josKnjiga
            && $namera->godinaOpseg === null && $namera->mestoFraza === null
            && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->imenovanaKnjiga === null
            && preg_match(
                '/\b(?:knjig\w*|dela?|nesto|od)\s+(?:od\s+)?([a-zšđžčćA-ZŠĐŽČĆ][\p{L}.\-]+(?:\s+[a-zšđžčćA-ZŠĐŽČĆ][\p{L}.\-]+){0,2})/u',
                TextUtils::cyrillicToLatin($message),
                $m
            )) {
            $fraza = trim($m[1]);
            $normA = TextUtils::normalize($fraza);
            $prvi  = TextUtils::tokenizuj($normA)[0] ?? '';

            if ($prvi !== '' && !in_array($prvi, IntentClassifier::STOP_RECI, true)) {
                $ids = $this->katalog->nadjiAutoraStrogo($normA);

                if ($ids->isNotEmpty()) {
                    $prikaz = $this->punoImeAutora(['prikaz' => $fraza, 'norm' => $normA], $ids);

                    return $this->fiksniOdgovor($this->strukturni->odgovorAutorLista($prikaz, $ids));
                }
            }
        }

        // 0d12) LISTA KNJIGA IMENOVANOG AUTORA (lowercase „od X"): „reci mi koje
        //       knjige imaš od milovana vitezovica" — „od <ime>" je jak signal
        //       autora; izvuče se i STROGO proveri u bazi. Bez preporuke (0d11),
        //       žanra/mesta/godine/„još knjiga"/dostupnosti. Gejt „od" izbegava
        //       homografe („knjigu kući") i slučajeve koje leksika već rešava
        //       („kolko knjiga bekman fredrik") — oni nemaju „od".
        if (!preg_match('/preporuc|predloz/u', $norm)
            && !$namera->zanrovskiUpit && !$namera->josKnjiga && !$namera->dostupnostUpit
            && $namera->godinaOpseg === null && $namera->mestoFraza === null
            && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->imenovanaKnjiga === null
            && preg_match('/\bod\s+([a-zšđžčćA-ZŠĐŽČĆ][\p{L}.\-]+(?:\s+[a-zšđžčćA-ZŠĐŽČĆ][\p{L}.\-]+){0,2})/u', TextUtils::cyrillicToLatin($message), $m)) {
            $fraza = trim($m[1]);
            $normA = TextUtils::normalize($fraza);
            $prvi  = TextUtils::tokenizuj($normA)[0] ?? '';

            if ($prvi !== '' && !in_array($prvi, IntentClassifier::STOP_RECI, true)) {
                $ids = $this->katalog->nadjiAutoraStrogo($normA);

                if ($ids->isNotEmpty()) {
                    $prikaz = $this->punoImeAutora(['prikaz' => $fraza, 'norm' => $normA], $ids);

                    return $this->fiksniOdgovor($this->strukturni->odgovorAutorLista($prikaz, $ids));
                }
            }
        }

        // 0d13) „POKAŽI / KOJE KNJIGE <ime>" bez „od" (set15 p3, set16 p11/p12):
        //       „pokaži mi sve knjige fredrika bekmana", „koje knjige hermana
        //       hesea imate" — ime posle kataloške reči, malim slovima. Proba SVE
        //       kandidate iz upita i vraća prvog koji se POTVRDI u bazi
        //       (nadjiAutoraStrogo); nepotvrđeni kandidat (npr. „knjige imate")
        //       ne gejtuje ništa, pa nema lažnih pogodaka. Zahteva „prikaži/koje/
        //       imate…" kontekst da ne otima slučajne pomene naslova.
        if (!$namera->zanrovskiUpit && !$namera->josKnjiga && !$namera->dostupnostUpit
            && !$namera->tematsko && !$namera->slicnoUpit && !$namera->faq
            && !$namera->akcioniZahtev && !$namera->najnovije && !$namera->najcitanije
            && $namera->godinaOpseg === null && $namera->mestoFraza === null
            && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->imenovanaKnjiga === null
            && preg_match('/\b(?:koje|koja|koji|pokazi|pokazes|pokazete|prikazi|prikazite|sta|imate|imas|nadje\w*|pronadje\w*|pronaci)\b/u', $norm)
            && preg_match_all(
                '/\b(?:knjig\w*|naslov\w*|dela?|romane|poeziju|stvaralastv\w*)\s+(?:od\s+)?'
                . '([a-zšđžčćA-ZŠĐŽČĆ][\p{L}.\-]+(?:\s+[a-zšđžčćA-ZŠĐŽČĆ][\p{L}.\-]+){0,2})/u',
                TextUtils::cyrillicToLatin($message),
                $mm
            )) {
            foreach ($mm[1] as $kand) {
                $kand  = $this->trimImeRep(trim($kand)); // odseci repne stop-reči („… mogu")
                $normA = TextUtils::normalize($kand);
                $prvi  = TextUtils::tokenizuj($normA)[0] ?? '';

                if ($prvi === '' || in_array($prvi, IntentClassifier::STOP_RECI, true)) {
                    continue;
                }

                $ids = $this->katalog->nadjiAutoraStrogo($normA);

                if ($ids->isNotEmpty()) {
                    $prikaz = $this->punoImeAutora(['prikaz' => $kand, 'norm' => $normA], $ids);

                    return $this->fiksniOdgovor($this->strukturni->odgovorAutorLista($prikaz, $ids));
                }
            }
        }

        // 0e0) SUBJEKTIVNO BEZ PODATAKA (Potez 2, set13 p22/p23): obim (katalog
        //      nema broj strana) i „poznatost" (samo po zaduženjima) — pošten
        //      odgovor umesto generičke liste najčitanijih koju je model nudio.
        if ($namera->imenovanaKnjiga === null && $namera->autorFraza === null) {
            if (preg_match('/\bobimn\w*|\bdebel\w*|\bmalo\s+strana|\bbroj\s+strana|\bkoliko\s+strana'
                . '|nije\s+(?:previse\s+)?(?:duga|dugacka|obimna|debela)|\bkratk\w*\s+(?:knjig|roman|stiv)/u', $norm)) {
                return $this->fiksniOdgovor(TextUtils::NEMA_OBIM);
            }

            if (preg_match('/\bmanje\s+poznat\w*|\bmalo\s+poznat\w*|\bnepoznat\w*\s+(?:ali|knjig)'
                . '|\bnije\s+(?:previse\s+|toliko\s+|bas\s+|mnogo\s+)?poznat\w*/u', $norm)) {
                return $this->fiksniOdgovor(TextUtils::NEMA_POZNATOST);
            }
        }

        $generickaPreporuka = (bool) preg_match('/preporuc|predloz/u', $norm)
            && !$namera->zanrovskiUpit && !$namera->tematsko && !$namera->slicnoUpit
            && !$namera->josKnjiga
            && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->imenovanaKnjiga === null
            && $namera->izdavacFraza === null && $namera->godinaOpseg === null;

        // 0e1a) NAJČITANIJA KNJIGA DOMAĆEG/STRANOG AUTORA (Potez 2, set13 p13) —
        //       knjiga sa najviše zaduženja čiji pisac je tog porekla.
        if ($namera->najcitanije && $namera->imenovanaKnjiga === null
            && preg_match('/knjig/u', $norm)
            && ($klasaNaj = $this->classifier->podelaKlasa($norm)) !== null) {
            return $this->odgovorNajcitanijaKnjigaPodela($klasaNaj);
        }

        // 0e1b) PODŽANR VAN FONDA (Potez 2, set13 p10/p15): „psihološki triler" —
        //       uzak podžanr kog u fondu (po žanru) nema → pošteno „nemamo",
        //       umesto da model izmišlja ili nudi ceo žanr Triler.
        if ($this->classifier->jePodzanrVanFonda($norm) && $namera->imenovanaKnjiga === null) {
            return $this->fiksniOdgovor($this->strukturni->odgovorZanrVanFonda());
        }

        // 0e2) ŽANR DOSTUPNOST (Potez 2, set13 p20): „ima li slobodnih trilera
        //      za pozajmljivanje" — knjige žanra koje TRENUTNO imaju slobodan
        //      primerak; žanr van fonda → pošteno „nemamo". Bez imenovane knjige.
        if ($namera->dostupnostUpit && $namera->zanrovskiUpit
            && $namera->imenovanaKnjiga === null && !$namera->tematsko) {
            $zanroviDost = $this->strukturni->nadjiZanroveUTekstu($norm);

            if ($zanroviDost->isNotEmpty()) {
                return $this->fiksniOdgovor($this->strukturni->odgovorZanrDostupnost($zanroviDost->first()));
            }

            if ($this->classifier->imaZanrVanFonda($norm)) {
                return $this->fiksniOdgovor($this->strukturni->odgovorZanrVanFonda());
            }
        }

        // 0f) ŽANROVSKA PREPORUKA („preporuči mi neki roman za vikend", „šta
        //     da čitam, nešto smešno") — deterministički iz baze: najčitanije
        //     + nedavno dodate knjige TOG žanra. Model je za ovakva pitanja
        //     izmišljao naslove i lomio rečenice (eval za_kod.md, #22 p11,
        //     #19 p9 i p15). Sinonim bez imenovanog žanra („smešno") svodi
        //     se na žanr Komedija. Dva pomenuta žanra rešava presek (b0).
        //     Okidači „dobar/voli" (kandidati_ucenje #33 p13 i p17): „imaš
        //     neki dobar triler?", „nešto za nekoga ko voli istoriju?" su
        //     ista žanrovska preporuka — bez njih su išli modelu, koji je
        //     odgovarao predugačko i sa pravopisnim greškama.
        if (!$namera->tematsko && !$namera->slicnoUpit
            && !$namera->najnovije && !$namera->najcitanije
            && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->imenovanaKnjiga === null && $namera->godinaOpseg === null
            && preg_match('/preporuc|predloz|citam|citanje|procita|\bdob(?:ar|ru|re|rih)\b|\bvoli(?:m|s|te)?\b'
                . '|\bzanima\b|\bzelim\b|\bhocu\b|\btrazim\b|\bvole[ol]a?\s+bih\b|\bbih\s+(?:da|neki|nesto)\b|\binteresuje\b/u', $norm)) {
            $zanroviUpita  = $this->strukturni->nadjiZanroveUTekstu($norm);
            $zanrPreporuke = $zanroviUpita->count() === 1 ? $zanroviUpita->first() : null;

            if ($zanrPreporuke === null && $zanroviUpita->isEmpty()
                && preg_match('/smesn|duhovit|zabavn/u', $norm)) {
                $zanrPreporuke = $this->strukturni->nadjiZanr('komedija');
            }

            if ($zanrPreporuke !== null) {
                // „dobar DOMAĆI/STRANI roman" → suzi preporuku na poreklo pisca
                // (Potez 2, set13 p16); bez kvalifikatora → obična preporuka.
                $klasa = $this->classifier->podelaKlasa($norm);

                return $klasa !== null
                    ? $this->odgovorZanrPodelaPreporuka($zanrPreporuke, $klasa)
                    : $this->fiksniOdgovor($this->strukturni->odgovorZanrPreporuka($zanrPreporuke));
            }
        }

        // 0g) DOMAĆI PISCI / SRPSKA KNJIŽEVNOST („preporuči mi nešto od
        //     domaćih pisaca", „treba mi nešto za seminarski iz srpske
        //     književnosti") — FIKSNA preporuka dela kanonskih domaćih pisaca
        //     koji POSTOJE u katalogu (eval za_kod.md, #19 p11 i #22 p16:
        //     model je odbijao pitanje ili nudio strane autore).
        // 0g0) PODELA PISCA (Faza 4): „da li je X domaći ili strani pisac" →
        //      klasifikacija iz kurirane podele; van podele → pošteno „nemam
        //      potvrđenu podelu". Pre autorskih ruta koje bi listale knjige.
        if ($namera->podelaPiscaIme !== null) {
            return $this->odgovorPodelaPisca($namera->podelaPiscaIme);
        }

        // 0g1) STRANI PISCI preporuka (Faza 4): „preporuči mi nešto od stranih
        //      autora" — dela stranih pisaca iz kurirane liste koja postoje u
        //      katalogu. Prazno → pitanje ide normalnim tokom.
        if ($namera->imenovanaKnjiga === null && $namera->straniPisciUpit
            && ($strani = $this->odgovorStraniPisci()) !== null) {
            return $this->fiksniOdgovor($strani);
        }

        if ($namera->imenovanaKnjiga === null
            && preg_match('/domac\w* (pisc|pisac|autor)|srpsk\w* knjizevnost/u', $norm)
            && ($domaci = $this->odgovorDomaciPisci($norm)) !== null) {
            return $this->fiksniOdgovor($domaci);
        }

        // 0g2) NAJNOVIJE / POSLEDNJE DODATE (deterministički): „koje su poslednje
        //      dodate knjige", „šta je nedavno stiglo" → fiksna lista iz sekcije
        //      „Najnovije" (bibliotekari je pune). „najnovija PO GODINI izdanja"
        //      je drugačiji upit (ekstrem), pa se isključuje pomen godine.
        if ($namera->najnovije && $namera->ekstremKnjiga === null
            && $namera->imenovanaKnjiga === null && $namera->autorFraza === null
            && $namera->autorKandidat === null && !$namera->zanrovskiUpit
            && !$namera->najcitanije && $namera->godinaOpseg === null
            && $namera->mestoFraza === null && !preg_match('/godin/u', $norm)) {
            return $this->odgovorNajnovije();
        }

        // 0g3) „PREPORUČI NEKU DOSTUPNU KNJIGU" bez žanra/naslova/autora — dosta
        //      ih je da bi spisak bio koristan; umesto generičke liste tražimo
        //      preciziranje (set15 p25).
        if ($namera->dostupnostUpit && preg_match('/preporuc|predloz/u', $norm)
            && !$namera->zanrovskiUpit && !$namera->tematsko && !$namera->slicnoUpit
            && $namera->imenovanaKnjiga === null && $namera->autorFraza === null
            && $namera->autorKandidat === null && $namera->izdavacFraza === null
            && $namera->godinaOpseg === null && $namera->mestoFraza === null) {
            return $this->fiksniOdgovor(
                'U katalogu imamo dosta trenutno dostupnih knjiga, pa bih listu suzio prema '
                . 'vašem ukusu. Recite mi žanr, naslov ili autora koji vas zanima, pa da vam '
                . 'predložim nešto dostupno.'
            );
        }

        // 0h) GENERIČKA PREPORUKA — fiksni odgovor (posle žanrovske i domaće
        //     rute, da im ne preotme pitanje sa sinonimom žanra ili „domaćih
        //     pisaca"). Null (prazna baza) → pitanje ide modelu sa listama.
        if ($generickaPreporuka
            && ($fiksnaPreporuka = $this->odgovorGenerickaPreporuka()) !== null) {
            return $this->fiksniOdgovor($fiksnaPreporuka);
        }

        // 1) Retrieval (Python mikroservis /retrieve): pretprocesiranje
        //    (ćirilica→latinica, dijakritici, langdetect) + embedding
        //    OČIŠĆENOG upita + sqlite-vec KNN — sirovi top-K semantički
        //    pogoci (BEZ praga) stižu u istom odgovoru. Ako servis nije
        //    dostupan, leksička pretraga radi i bez njega — normalizaciju
        //    tada uradi PHP (ista pravila, TextUtils::normalize).
        try {
            $query = $this->ragClient->retrieve($message, $history);

            if (!($query['ok'] ?? false)) {
                if (($query['reason'] ?? '') === 'strani_jezik') {
                    // Langdetect ume da srpsko pitanje sa neobičnim redom reči
                    // proglasi stranim („romane dal imate neke da procitam" —
                    // eval za_kod.md, #19 p9): kada pitanje ima STVARAN pogodak
                    // u katalogu (naslov/autor/žanr) ili FAQ pogodak, nastavlja
                    // se leksičkim tokom umesto odbijanja. Stop reči nisu dokaz
                    // („do", „u" postoje i u engleskom).
                    if (!$faqIzMd
                        && $this->katalog->pretrazi($norm, $namera->tematsko)->isEmpty()) {
                        return $this->fiksniOdgovor(TextUtils::NOT_UNDERSTOOD);
                    }
                }
                // npr. prazan upit — nastavi samo sa leksičkim slojem
            } else {
                $norm   = $query['text'] ?? $norm;
                $sirovi = array_map(
                    fn (array $h) => ['id' => (int) $h['id'], 'score' => (float) $h['score']],
                    $query['hits'] ?? []
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[AiChatService] RAG servis nedostupan — samo leksička pretraga', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2) Leksički sloj — deterministički pogoci po nazivu/autoru/žanru.
        $leksicki = $this->katalog->pretrazi($norm, $namera->tematsko);

        // 2a) NASTAVAK RAZGOVORA bez sopstvenih pogodaka („a taj autor?",
        //     „njegove pesme?") — pretraga se ponavlja nad prethodnom
        //     razmenom + tekućim pitanjem, pa referenca na ranije pomenutog
        //     autora/knjigu ne gubi kontekst. NE važi kada je korisnik
        //     IMENOVAO novu knjigu („a da li ima knjiga isl…"): knjiga iz
        //     prethodne razmene tada nije odgovor, a lažni pogodak bi
        //     preskočio anti-halucinacionu proveru imenovane knjige (3.5).
        if ($leksicki->isEmpty() && $namera->nastavak && $namera->kontekstNorm !== null
            && $namera->imenovanaKnjiga === null) {
            $leksicki = $this->katalog->pretrazi(
                $namera->kontekstNorm . ' ' . $norm,
                $namera->tematsko
            );
        }

        // 2a2) NASTAVAK — ZAMENICA NA AUTORA („koje još NJENE knjige imate",
        //      „ima li još njegovih dela"): autora određujemo iz ID-jeva knjiga
        //      koje je bot naveo u PRETHODNOM odgovoru (jedna poruka unazad).
        //      Superlativ („koja je najčitanija") prepušta se ruti 2.05.
        if ($namera->nastavak && $namera->imenovanaKnjiga === null
            && ($namera->josKnjiga || preg_match('/\bnjen\w*|\bnjegov\w*|\bnjihov\w*/u', $norm))
            && preg_match('/\bknjig\w*|\bnaslov\w*|\bdel[oa]\b|\bdela\b/u', $norm)
            && !preg_match('/najbolj|najleps|najpopularnij|najcitanij|najtrazenij/u', $norm)) {
            $prevIds = $this->idsIzPrethodnogOdgovora($history);

            if (!empty($prevIds)) {
                $autori = collect($prevIds)
                    ->map(fn (int $id) => $this->data->nadjiKnjigu($id))
                    ->filter()
                    ->map(fn ($k) => trim((string) $k->autor))
                    ->filter()
                    ->unique()
                    ->values();

                if ($autori->count() === 1) {
                    $kanonski = (string) $autori->first();
                    $sviIds   = $this->katalog->nadjiAutoraStrogo(TextUtils::normalize($kanonski));

                    if ($sviIds->isNotEmpty()) {
                        return $this->fiksniOdgovor($this->strukturni->odgovorJosKnjigaAutora(
                            $this->genitivKanonskogImena($kanonski),
                            $sviIds,
                            $prevIds
                        ));
                    }
                }
            }
        }

        // 2a3) ELIDIRAN DETALJ NAD PRETHODNOM KNJIGOM („koje je godine izdata",
        //      „ko je autor", „koji je žanr") — bez izričite reference („te
        //      knjige") ni markera nastavka. Okida SAMO kada pitanje SAMO NE
        //      nalazi svoju knjigu (elidiran objekat) i kada je prethodni odgovor
        //      bio o TAČNO JEDNOJ knjizi — tada je detalj nedvosmisleno o njoj.
        //      Više knjiga ili sopstveni pogodak → prepušta se dalje.
        if ($namera->imenovanaKnjiga === null && $namera->autorFraza === null
            && $namera->autorKandidat === null && !$namera->zanrovskiUpit
            && ($detaljK = $this->detaljUpit($norm)) !== null
            && !$leksicki->contains(fn (array $h) => $h['razlog'] === 'naziv')) {
            $prevIds = $this->idsIzPrethodnogOdgovora($history);

            if (count($prevIds) === 1) {
                return $this->fiksniOdgovor($this->strukturni->odgovorDetaljKnjige($prevIds[0], $detaljK));
            }
        }

        // 2.05) „KOJA OD TIH TI JE NAJBOLJA?" — nastavak nad prethodnim
        //       spiskom (autorska/žanrovska lista): „najbolja" se meri BROJEM
        //       ZADUŽENJA iz arhive, deterministički; bez ijednog zaduženja
        //       pošten odgovor da procena nije moguća. Model je ovakav
        //       nastavak odbijao kao van teme (kandidati_ucenje #33 p24b).
        if ($namera->nastavak && $leksicki->isNotEmpty()
            && preg_match('/najbolj|najleps|najpopularnij|najcitanij|najtrazenij/u', $norm)) {
            return $this->fiksniOdgovor(
                $this->strukturni->odgovorNajboljaOdListe($leksicki->pluck('id')->all())
            );
        }

        // 2.1) STRUKTURNI UPITI (deterministički, SQL/ORM — model se ne
        //      poziva, pa broj ne može biti pogrešan niti lista skraćena):
        //
        //      a) statistika učestalosti („koliko često se zadužuju knjige")
        //         — SAMO kada nema leksičkih pogodaka: pitanje o konkretnoj
        //         knjizi ide normalnim tokom (broj njenih zaduženja je u promptu);
        if ($namera->statistika && $leksicki->isEmpty()) {
            return $this->fiksniOdgovor($this->strukturni->odgovorStatistikaZaduzenja());
        }

        //      a2) AGREGACIONA ANALITIKA (COUNT/SUM/AVG/MIN/MAX nad bazom):
        //          knjige koje NIKADA nisu zadužene, poređenje popularnosti
        //          žanrova, najveći odnos rezervacija/primeraka, prosečan
        //          broj zaduženja po naslovu — brojevi se računaju SQL-om,
        //          model se ne poziva.
        if ($namera->nikadZaduzene) {
            return $this->fiksniOdgovor($this->strukturni->odgovorNikadZaduzene());
        }

        if ($namera->poredjenjeZanrova) {
            $zanrovi = $this->strukturni->nadjiZanroveUTekstu($norm);

            if ($zanrovi->count() >= 2) {
                return $this->fiksniOdgovor(
                    $this->strukturni->odgovorPoredjenjeZanrova($zanrovi[0], $zanrovi[1])
                );
            }

            // Izričito poređenje, a u fondu postoji samo jedan od pomenutih
            // žanrova → pošten delimičan odgovor umesto pogađanja.
            if ($zanrovi->count() === 1 && preg_match('/uporedi|poredi|poredjenj/u', $norm)) {
                return $this->fiksniOdgovor(
                    $this->strukturni->odgovorPoredjenjeJedanZanr($zanrovi->first())
                );
            }
        }

        if ($namera->odnosRezervacija) {
            return $this->fiksniOdgovor($this->strukturni->odgovorNajveciOdnosRezervacija());
        }

        if ($namera->prosekZaduzenja) {
            return $this->fiksniOdgovor($this->strukturni->odgovorProsekZaduzenja());
        }

        //      a25) STATISTIKA O IZDAVAČIMA (2026-07-10): „od kog izdavača su
        //           najviše knjige", „najpopularniji izdavač", „koje izdavače
        //           imate" — brojevi iz baze (COUNT po katalogu / SUM po
        //           arhivi), model se ne poziva. PRE najčitanijih (a3), jer
        //           „najpopularniji izdavač" pali i taj signal.
        if ($namera->izdavacStatistika !== null) {
            return $this->fiksniOdgovor(
                $this->strukturni->odgovorIzdavacStatistika($namera->izdavacStatistika)
            );
        }

        //      a3) NAJČITANIJE/NAJTRAŽENIJE (statistika tražnje): kada nema
        //          leksičkih pogodaka, odgovor se sastavlja iz baze (COUNT po
        //          knjizi/autoru/žanru) — ista formulacija za sve sinonime
        //          („najviše se traži" = „najčešće se izdaje"), lista bez
        //          prećutnog skraćivanja, „dostupne odmah" filtrira slobodne.
        //          Pitanje o konkretnoj knjizi ili o najnovijim ide starim tokom.
        if ($namera->najcitanije && !$namera->najnovije && $leksicki->isEmpty()) {
            return $this->fiksniOdgovor($this->strukturni->odgovorNajcitanije($norm));
        }

        //      b00) ŽANR VAN FONDA („imate li horor", „nešto od naučne
        //           fantastike", „avanturistički roman"): pitanje imenuje
        //           žanr(ovsku reč) koja se ne poklapa ni sa jednim POSTOJEĆIM
        //           žanrom → POŠTEN fiksni odgovor sa spiskom žanrova iz
        //           ponude, BEZ preporuka (kaskada „srodnog žanra" je za horor
        //           nudila romane; kraći oblici bez reči „knjiga/žanr" su čak
        //           dobijali odbijanje van teme — ručno testiranje 2026-07-10).
        //           Pogodak po naslovu/autoru ima prednost (pitanje je tada o
        //           konkretnoj knjizi), tematska pitanja idu semantikom, a
        //           kombinaciju sa imenovanim autorom rešava autorski tok (3.4).
        if (!$namera->tematsko
            && $namera->zanrovskiUpit
            && $namera->countRec === null
            && $namera->pocinjeSlovo === null
            && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->imenovanaKnjiga === null
            && !$leksicki->contains(fn (array $h) => $h['razlog'] !== 'zanr')
            && $this->classifier->imaZanrVanFonda($norm)) {
            return $this->fiksniOdgovor($this->strukturni->odgovorZanrVanFonda());
        }

        //      b05) IMENOVAN IZDAVAČ („da li imate knjige izdavača Laguna",
        //           + eventualni žanr i/ili godina → PRESEK) — fiksna lista
        //           iz baze po polju izdavac, pravilo kratke liste;
        //           nepostojeći izdavač → pošten odgovor sa spiskom izdavača.
        //           Pitanje o KONKRETNOJ knjizi („izdavač knjige X") ima
        //           prednost — imenovana/pogođena knjiga ide svojim tokom,
        //           a žanr van fonda je već obrađen iznad (b00).
        if ($namera->izdavacFraza !== null
            && $namera->imenovanaKnjiga === null
            && !$leksicki->contains(fn (array $h) => $h['razlog'] === 'naziv')) {
            $zanrIzdavaca = $namera->zanrovskiUpit
                ? $this->strukturni->nadjiZanroveUTekstu($norm)->first()
                : null;

            return $this->fiksniOdgovor($this->strukturni->odgovorIzdavacLista(
                $namera->izdavacFraza['norm'],
                $namera->izdavacFraza['prikaz'],
                $zanrIzdavaca,
                $namera->godinaOpseg
            ));
        }

        //      b0) DVA ŽANRA u pitanju („imate li nešto iz istorije i
        //          umetnosti", „čita mi se neki krimi roman") → knjige koje
        //          pripadaju OBA žanra (presek), fiksno; prazan presek →
        //          pošten odgovor sa stanjem oba žanra. Poređenje
        //          popularnosti je već obrađeno iznad (a2), autorski presek
        //          rešava autorski tok (3.4).
        if (!$namera->tematsko && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->zanrovskiUpit) {
            $dvaZanra = $this->strukturni->nadjiZanroveUTekstu($norm);

            if ($dvaZanra->count() >= 2) {
                return $this->fiksniOdgovor(
                    $this->strukturni->odgovorDvaZanra($dvaZanra[0], $dvaZanra[1])
                );
            }
        }

        //      b) lista ŽANRA („izlistaj knjige žanra priče", „a od
        //         pripovetki šta imate") — knjige kojima je taj žanr upisan,
        //         po polju žanr (ne po temi!), uz pravilo kratke liste
        //         (do 5 sve; preko 5 ukupan broj + prvih 5 + ostatak na
        //         zahtev); nepostojeći žanr ide normalnim tokom (preporuka
        //         srodnog žanra). Kada je uz žanr IMENOVAN i autor („od
        //         autora X iz žanra Y"), umesto cele liste žanra važi
        //         PRESEK — rešava ga autorski tok ispod (3.4). Kada je uz žanr
        //         navedena i GODINA („romani iz 2023"), presek žanr+godina
        //         rešava godišnji tok (c3) — zato ovde godinaOpseg mora biti null.
        if (!$namera->tematsko
            && $namera->autorFraza === null
            && $namera->autorKandidat === null
            && $namera->godinaOpseg === null
            && $namera->zanrListaFraza !== null
            && ($zanr = $this->strukturni->nadjiZanr($namera->zanrListaFraza)) !== null) {
            return $this->fiksniOdgovor($this->strukturni->odgovorZanrLista($zanr));
        }

        //      c) „koliko knjiga sadrži reč X u naslovu" — tačan COUNT i
        //         kompletno nabrajanje iz kataloga;
        if ($namera->countRec !== null) {
            return $this->fiksniOdgovor($this->strukturni->odgovorBrojKnjigaSaRecju(
                $this->katalog->brojKnjigaSaRecju($namera->countRec)
            ));
        }

        //      c2) „koliko knjiga počinje na slovo X" — tačan broj i
        //          kompletna lista iz kataloga (model je za ovo pitanje
        //          izmišljao i broj i naslove — ručno testiranje);
        if ($namera->pocinjeSlovo !== null) {
            return $this->fiksniOdgovor($this->strukturni->odgovorPocinjeNaSlovo(
                $namera->pocinjeSlovo,
                $this->katalog->naslovNaSlovo($namera->pocinjeSlovo)
            ));
        }

        //      c3) GODINA IZDANJA („koje knjige imate objavljene posle
        //          2010", + eventualni žanr) — spisak iz baze po koloni
        //          godina_izdavanja; kombinaciju sa IMENOVANIM autorom
        //          (izričitim ili heurističkim) rešava autorski tok (3.4)
        //          presekom. „Preporuči/predloži mi roman posle 2020" → isti
        //          podaci, ali u obliku PREPORUKE (nekoliko naslova, bez
        //          isticanja ukupnog broja — nalaz #3). Pitanje o KONKRETNOJ
        //          knjizi uz godinu („da li imate Prokletu avliju iz 1954")
        //          NIJE spisak po godini — imenovana/pogođena knjiga ima
        //          prednost i ide svojim tokom (3.5/3.6/model).
        if ($namera->godinaOpseg !== null
            && $namera->autorFraza === null && $namera->autorKandidat === null
            && $namera->imenovanaKnjiga === null
            // MESTO + godina („objavljene u Beogradu tokom 2020") → presek radi
            // ruta c4 (odgovorMestoLista prima godinaOpseg); ovde godina-samo
            // ruta preskače da ne vrati SVE knjige godine (kandidati_ucenje_6 p19).
            && $namera->mestoFraza === null
            && !$leksicki->contains(fn (array $h) => $h['razlog'] === 'naziv')
            // AUTOR malim slovima + godina („koje knjige fredrika bekmana su
            // izdate 2020") — leksika ga pogodi po autoru; presek autor∩godina
            // rešava ruta 3.45 ispod, ne spisak SVIH knjiga godine
            // (kandidati_ucenje_2 #34 p16).
            && !$leksicki->contains(fn (array $h) => $h['razlog'] === 'autor')) {
            $zanrGodina = $namera->zanrovskiUpit
                ? $this->strukturni->nadjiZanroveUTekstu($norm)->first()
                : null;

            return $this->fiksniOdgovor(
                $this->strukturni->odgovorGodinaOpseg(
                    $namera->godinaOpseg,
                    $zanrGodina,
                    kaoPreporuka: (bool) preg_match('/preporuc|predloz/u', $norm)
                )
            );
        }

        //      c4) MESTO IZDAVANJA („koje knjige su izdate u Beogradu", +
        //          eventualna godina) — spisak po koloni mesto_izdavanja.
        //          Kombinacija sa AUTOROM („koje knjige fredrika bekmana su
        //          objavljene u Beogradu") ide presekom kroz autorski tok
        //          (3.45) — zato ovde leksika ne sme imati autorske pogotke
        //          (kandidati_ucenje_3 #34 p12/p13/p19, #36 p12/p13).
        if ($namera->mestoFraza !== null
            && !$leksicki->contains(fn (array $h) => $h['razlog'] === 'naziv')
            && !$leksicki->contains(fn (array $h) => $h['razlog'] === 'autor')) {
            return $this->fiksniOdgovor($this->strukturni->odgovorMestoLista(
                $namera->mestoFraza['norm'],
                $namera->mestoFraza['prikaz'],
                $namera->godinaOpseg
            ));
        }

        //      c4b) „MOGU LI DA PRETRAŽUJEM PO DELU NASLOVA / JEDNOJ REČI"
        //           (set17 p25) — meta-pitanje o MOGUĆNOSTI pretrage, a ne spisak
        //           knjiga sa N reči u naslovu; ide PRE strukturnih naslov-ruta
        //           da ga „broj reči u naslovu" ne otme.
        if (preg_match('/\bmogu\s+li\b.{0,40}(pretraz|trazi|nadje|pronadje|pronac)/u', $norm)
            && preg_match('/\bnaslov\w*|\bnaziv\w*/u', $norm)
            && preg_match('/\brec\w*|\bde[oal]\w*/u', $norm)) {
            return $this->fiksniOdgovor(
                'Da, možete da pretražujete i po delu naslova — dovoljna je jedna reč koje se '
                . 'sećate. Recite mi tu reč, pa da probam da pronađem knjigu.'
            );
        }

        //      c5) REČ U NASLOVU („knjiga koja se završava rečju ljudi") —
        //          pretraga po prvoj/poslednjoj reči naslova (kandidati_ucenje_3
        //          #39 p18). Model je za ovo vraćao pogrešnu knjigu.
        if ($namera->recNaslova !== null) {
            return $this->fiksniOdgovor($this->strukturni->odgovorNaslovPoReci(
                $namera->recNaslova['rec'],
                $namera->recNaslova['pozicija'],
                $this->katalog->naslovPoReci($namera->recNaslova['rec'], $namera->recNaslova['pozicija'])
            ));
        }

        //      c6) BROJ U NASLOVU / BROJ REČI / PREZIME NA SLOVO — strukturne
        //          pretrage nad naslovom i imenom autora (set17 p18–p20). Model
        //          je za ovo davao pogrešne liste; brojevi i reči se računaju
        //          deterministički nad katalogom.
        if ($namera->brojUNaslovu) {
            return $this->fiksniOdgovor(
                $this->strukturni->odgovorBrojUNaslovu($this->katalog->naslovSaBrojem())
            );
        }

        if ($namera->reciUNaslovu !== null) {
            return $this->fiksniOdgovor($this->strukturni->odgovorReciUNaslovu(
                $namera->reciUNaslovu,
                $this->katalog->naslovPoBrojuReci($namera->reciUNaslovu)
            ));
        }

        if ($namera->prezimeSlovo !== null) {
            return $this->fiksniOdgovor($this->strukturni->odgovorPrezimeNaSlovo(
                $namera->prezimeSlovo,
                $this->data->autoriPrezimeNaSlovo($namera->prezimeSlovo)
            ));
        }

        //      d) „ko je zadužio knjigu X" — poslednje zaduženje iz arhive
        //         (nepoverljiv podatak) za leksički pogođen naslov.
        if ($namera->koJeZaduzio
            && ($nazivHit = $leksicki->first(fn (array $h) => $h['razlog'] === 'naziv')) !== null) {
            return $this->fiksniOdgovor($this->strukturni->odgovorKoJeZaduzio($nazivHit['id']));
        }

        // 2.15) STRUČNA LITERATURA (bib-izmene): fond trenutno nema stručnu
        //       građu (matematika, fizika, programiranje, baze podataka,
        //       elektronika, elektrotehnika…) — kada leksika ni za jedan
        //       pojam iz pitanja ne nađe knjigu, POŠTEN fiksni odgovor
        //       umesto preporuka beletristike ili „teme". Nestaje sam od
        //       sebe: prva uneta stručna knjiga postaje leksički pogodak i
        //       pitanje ide normalnim tokom.
        if ($namera->strucnaLiteratura && $leksicki->isEmpty()) {
            return $this->fiksniOdgovor(TextUtils::STRUCNA_LITERATURA);
        }

        // 2.2) TEMATSKO PITANJE BEZ IJEDNOG OPISA U FONDU (Nalaz 1 + ručno
        //      testiranje): tema se čita ISKLJUČIVO iz OPISA knjige; dok u
        //      fondu nema nijednog opisa, fiksni pošten odgovor važi i kada
        //      leksika ima pogodak — knjiga NASLOVA „Priče" nije dokaz teme
        //      priče, a žanr nije tema. Čim se unese prvi opis, tematska
        //      pitanja idu semantičkim tokom (uz filter na knjige sa opisom).
        if ($namera->tematsko && $this->data->brojKnjigaSaOpisom() === 0) {
            return $this->fiksniOdgovor(TextUtils::TEMA_NEMA_PODATAKA);
        }

        // 3) Semantički sloj — sirovi top-K su stigli iz mikroservisa
        //    (korak 1); prag relevantnosti se primenjuje OVDE (leksički
        //    pogoci ne prolaze kroz prag).
        $minScore   = (float) config('ai.rag_min_score', 0.55);
        $semanticki = array_values(array_filter($sirovi, fn (array $r) => $r['score'] >= $minScore));

        // Filter RAVNOG šuma: pravi tematski pogodak se ODVAJA od ostatka
        // fonda (izmereno: „depresiji" → 0.66 pa skok na 0.57), dok pitanje
        // van teme daje ravne score-ove (top1−top5 ≈ 0.005). Semantika ostaje
        // samo ako je vrh visok ili se jasno odvaja od dna liste.
        // max/min umesto prvog/poslednjeg: hits iz mikroservisa su poređani
        // po RRF-u (hibrid), ne po cosine-u — vrh/dno se zato računaju.
        $ravnaSemantika = false;
        if (!empty($sirovi)) {
            $koscine = array_column($sirovi, 'score');
            $vrh = max($koscine);
            $dno = min($koscine);
            $ravnaSemantika = $vrh < self::SEM_VISOK_SCORE && ($vrh - $dno) < self::SEM_MIN_RASPON;
        }
        if ($ravnaSemantika) {
            $semanticki = [];
        }

        // Na pitanja o najnovijim/najčitanijim odgovaraju odgovarajuće liste,
        // a na agregatna („koliko knjiga/žanrova imate") agregati u promptu —
        // semantički pogoci za takva pitanja su šum (osim kada je leksika
        // našla konkretno traženu knjigu).
        if (($namera->najnovije || $namera->najcitanije || $namera->agregatno)
            && $leksicki->isEmpty()) {
            $semanticki = [];
        }

        // ČISTO FAQ PITANJE (pravila i usluge biblioteke, bez kataloških
        // reči): odgovor daju PODACI O BIBLIOTECI i FAQ primeri u promptu —
        // semantički „pogoci" su šum (pitanje o pravilima ume da povuče
        // knjigu sa sličnom rečju u naslovu kao da je odgovor). Leksički pogodak po
        // NASLOVU ostaje („mogu li da rezervišem Malog princa").
        if (($namera->faq || $faqIzMd) && !$namera->katalosko && !$namera->tematsko) {
            $semanticki = [];
        }

        // TEMATSKO PITANJE (kada opisi postoje): kandidati BEZ opisa se
        //  izbacuju — pogodak po naslovu sličnom temi (knjiga „Priče" za
        //  temu priče) nije tematski dokaz; tema se potvrđuje samo opisom.
        if ($namera->tematsko) {
            $saOpisom = $this->data->idsSaOpisom(array_values(array_unique(array_merge(
                $leksicki->pluck('id')->all(),
                array_column($semanticki, 'id'),
            ))));

            $leksicki   = $leksicki
                ->filter(fn (array $h) => in_array($h['id'], $saOpisom, true))
                ->values();
            $semanticki = array_values(array_filter(
                $semanticki,
                fn (array $h) => in_array($h['id'], $saOpisom, true)
            ));
        }

        // IZRIČIT ZAHTEV ZA PREPORUKOM O TEMI („preporuči mi nešto za
        // početnike iz programiranja") bez leksičkog pogotka: semantička
        // sličnost NIJE dokaz da knjige o traženoj temi postoje — model je
        // takve pogotke predstavljao kao knjige o traženoj temi, pa čak i
        // izmišljao naslove (nalaz A1.8). Pogoci se zato ne šalju modelu kao
        // „pronađene knjige", već ispod postaju POŠTENA preporuka („za taj
        // upit nisam pronašao… mogu da preporučim…", fiksni odgovor 6.1).
        if ($leksicki->isEmpty()
            && preg_match('/preporuc|predloz/u', $norm)
            && !empty($this->katalog->kljucneReci($norm))) {
            $semanticki = [];
        }

        // 3.4) IMENOVAN AUTOR: izričito („od autora Danila Kiša…") ili
        //      heuristički bez reči „autora" („knjige Stevana Sremca", „nešto
        //      od Crnjanskog"). Postojanje se proverava STROGO — sve reči imena
        //      moraju da pogode ISTOG autora (ime i prezime, ne samo prezime),
        //      pa „Slobodana Selenića" ne pogađa druge „Slobodane". Izričit
        //      autor kog nema → fiksni odgovor sa preporukama; heuristički
        //      kandidat kog nema NIJE autor → normalan tok (npr. „knjige o
        //      renesansi"). Autor postoji, a pomenut je žanr/godina → PRESEK
        //      (nalaz B2.10 — svi navedeni parametri moraju da se poklope);
        //      prazan presek → pošten fiksni odgovor sa onim što od njega imamo.
        $autor         = $namera->autorFraza ?? $namera->autorKandidat;
        $autorObavezan = $namera->autorFraza !== null;

        if ($autor !== null) {
            $knjigeAutora = $this->katalog->nadjiAutoraStrogo($autor['norm']);

            // „AUTOR" POD NAVODNICIMA je zapravo NASLOV KNJIGE: „Da li je X
            // od autora „Čoveka po imenu Uve"?" — poredi se autorstvo sa
            // knjigom koje u katalogu NEMA, pa se autori ne mogu uporediti.
            // Pošten fiksni odgovor umesto „nemamo knjige autora …"
            // (kandidati_ucenje #31 p4: navodnici imenuju knjigu, ne osobu).
            if ($knjigeAutora->isEmpty()
                && $autorObavezan
                && preg_match('/[„“”"»«]/u', $autor['prikaz'])) {
                $naslovFraza = trim((string) preg_replace('/[„“”"»«\']/u', '', $autor['prikaz']));

                if ($naslovFraza !== ''
                    && $this->katalog->nadjiPoNaslovu(TextUtils::normalize($naslovFraza))->isEmpty()) {
                    return $this->fiksniOdgovor(
                        "Ne mogu pouzdano da odgovorim na to pitanje, jer se knjiga "
                        . "„{$naslovFraza}” ne nalazi u bibliotečkom katalogu kojim raspolažem. "
                        . 'Mogu da uporedim autore samo za knjige koje se nalaze u katalogu.'
                    );
                }
            }

            // I heuristički kandidat koji IZGLEDA kao ime osobe („Harukija
            // Murakamija") dobija pošteno „nemamo" — ranije je takvo pitanje
            // sklizavalo u odbijanje van teme (eval za_kod.md, #22 p22).
            if ($knjigeAutora->isEmpty()
                && ($autorObavezan || $this->izgledaKaoImeOsobe($autor['prikaz']))) {
                return $this->odgovorNemamo(
                    "Trenutno nemamo knjige autora {$autor['prikaz']} u katalogu."
                );
            }

            if ($knjigeAutora->isNotEmpty()) {
                // Puno ime umesto samog prezimena: korisnik koji kuca „od
                // Nušića" treba da dobije „Od autora Branislava Nušića imamo…"
                // (kandidati_ucenje #33 p24a). Fraza sa isto reči kao kanonsko
                // ime ostaje kako ju je korisnik napisao (padež korisnika).
                $autor['prikaz'] = $this->punoImeAutora($autor, $knjigeAutora);
                $zanrUpita = $namera->zanrovskiUpit
                    ? $this->strukturni->nadjiZanroveUTekstu($norm)->first()
                    : null;

                if ($zanrUpita !== null) {
                    $uZanru = $this->data->knjigeZanra($zanrUpita)->pluck('id');
                    $presek = $knjigeAutora->intersect($uZanru)->values();

                    if ($presek->isEmpty()) {
                        return $this->fiksniOdgovor(
                            "Od autora {$autor['prikaz']} trenutno nemamo knjige iz žanra "
                            . "{$zanrUpita->naziv}. Od tog autora imamo: "
                            . $this->listaLinkova($this->data->hidriraj(
                                $knjigeAutora->take(5)->mapWithKeys(fn (int $id) => [$id => 1.0])->all()
                            )) . '.'
                        );
                    }

                    $knjigeAutora = $presek;
                }

                // GODINA IZDANJA uz autora („od autora X posle 2010") — presek
                // sa knjigama u opsegu godine; prazan presek rešava pošten
                // odgovor u odgovorAutorLista (sa opisom žanra i godine).
                if ($namera->godinaOpseg !== null) {
                    $uGodini = collect($this->data->idsUOpseguGodina(
                        $namera->godinaOpseg['od'] ?? null,
                        $namera->godinaOpseg['do'] ?? null
                    )['ids']);
                    $knjigeAutora = $knjigeAutora->intersect($uGodini)->values();

                    return $this->fiksniOdgovor($this->strukturni->odgovorAutorLista(
                        $autor['prikaz'],
                        $knjigeAutora,
                        $zanrUpita,
                        godinaOpseg: $namera->godinaOpseg
                    ));
                }

                // ČIST ZAHTEV ZA SPISKOM autora (+ eventualni žanr) → FIKSNA
                // lista sa pravilom kratke liste (bib-izmene): do 5 knjiga sve,
                // preko 5 ukupan broj + prvih 5 + ostatak na zahtev. Model se ne
                // poziva, pa ni broj ni gramatika ne mogu biti pogrešni. Pitanja
                // koja uz autora traže JOŠ nešto (dostupnost, najčitanije,
                // preporuku) idu starim tokom kroz model, sa pogocima u promptu.
                if (!$namera->najcitanije && !$namera->najnovije
                    && !preg_match('/dostupn|slobodn|na\s+stanju|preporuc|predloz/u', $norm)) {
                    return $this->fiksniOdgovor($this->strukturni->odgovorAutorLista(
                        $autor['prikaz'],
                        $knjigeAutora,
                        $zanrUpita
                    ));
                }

                // Autorske knjige (uz eventualni žanrovski presek) POTISKUJU
                // pogotke po samom žanru od drugih autora; pogodak po naslovu
                // ostaje ispred.
                $autorski = $knjigeAutora->map(fn (int $id) => ['id' => $id, 'score' => 1.0, 'razlog' => 'autor']);
                $leksicki = $leksicki
                    ->filter(fn (array $h) => $h['razlog'] === 'naziv')
                    ->concat($autorski)
                    ->unique('id')
                    ->take((int) config('ai.rag_top_k', 5))
                    ->values();
            }
        }

        // 3.45) AUTOR PREPOZNAT SAMO LEKSIKOM: ime malim slovima ili obrnutim
        //       redom reči („imate li od andrica knjigu neku", „kolko knjiga
        //       bekman fredrik ima kod vas") ne daje autorFrazu/kandidata
        //       (heuristike traže velika slova), ali leksika pogodi autora.
        //       Kada su SVI pogoci autorski i pripadaju ISTOM autoru, a
        //       pitanje traži spisak/broj/ponudu, vraća se FIKSNA autorska
        //       lista sa kanonskim imenom iz baze — model je za ovakva
        //       pitanja izmišljao broj knjiga ili vraćao samo jednu
        //       (kandidati_ucenje: #28 p6 i p10).
        if ($autor === null
            && $leksicki->isNotEmpty()
            && !$leksicki->contains(fn (array $h) => $h['razlog'] !== 'autor')
            && $namera->imenovanaKnjiga === null
            && !$namera->tematsko && !$namera->najnovije && !$namera->najcitanije
            && !$namera->dostupnostUpit && !$namera->akcioniZahtev && !$namera->slicnoUpit
            && preg_match('/\b(imate|imas|imamo|ima|nudite|posedujete|drzite|koli?ko|koje|kojih|spisak|listu|izlistaj|nabroj|daj)\b/u', $norm)
            && !preg_match('/preporuc|predloz/u', $norm)) {
            $autoriPogodaka = $this->data->hidriraj($leksicki->pluck('score', 'id')->all())
                ->map(fn (array $h) => trim((string) $h['knjiga']->autor))
                ->filter()
                ->unique()
                ->values();

            if ($autoriPogodaka->count() === 1) {
                $kanonski     = (string) $autoriPogodaka->first();
                $knjigeAutora = $this->katalog->nadjiAutoraStrogo(TextUtils::normalize($kanonski));

                // AUTOR + GODINA presek („koje knjige fredrika bekmana su
                // izdate 2020") — leksika je našla autora, godina sužava
                // (kandidati_ucenje_2 #34 p16).
                if ($namera->godinaOpseg !== null) {
                    $uGodini = collect($this->data->idsUOpseguGodina(
                        $namera->godinaOpseg['od'] ?? null,
                        $namera->godinaOpseg['do'] ?? null
                    )['ids']);
                    $knjigeAutora = $knjigeAutora->intersect($uGodini)->values();
                }

                // AUTOR + MESTO presek („koje knjige fredrika bekmana su
                // objavljene u Beogradu") — kandidati_ucenje_3 #36 p12.
                $mestoPrikaz = null;
                if ($namera->mestoFraza !== null
                    && ($mesto = $this->strukturni->nadjiMesto($namera->mestoFraza['norm'])) !== null) {
                    $knjigeAutora = $knjigeAutora->intersect($mesto['ids'])->values();
                    $mestoPrikaz  = $mesto['prikaz'];
                }

                if ($knjigeAutora->isNotEmpty()
                    || $namera->godinaOpseg !== null || $mestoPrikaz !== null) {
                    return $this->fiksniOdgovor($this->strukturni->odgovorAutorLista(
                        $this->genitivKanonskogImena($kanonski),
                        $knjigeAutora,
                        godinaOpseg: $namera->godinaOpseg,
                        mestoPrikaz: $mestoPrikaz
                    ));
                }
            }
        }

        // 3.46) „JOŠ NEKU KNJIGU [autora]" — autor imenovan DIREKTNO (malim
        //       slovima), leksika ga pogodi po autoru; vraćaju se OSTALE knjige
        //       tog autora, bez već pomenutih (u tekućem pitanju i prethodnoj
        //       razmeni). „Novija od X" dodatno filtrira po godini reference
        //       (kandidati_ucenje_2 #34 p20/p21, #35 p11/p14/p15).
        if ($namera->josKnjiga && $autor === null && $leksicki->isNotEmpty()) {
            $autorHits = $leksicki->filter(fn (array $h) => $h['razlog'] === 'autor')->values();

            if ($autorHits->isNotEmpty()) {
                $autori = $this->data->hidriraj($autorHits->pluck('score', 'id')->all())
                    ->map(fn (array $h) => trim((string) $h['knjiga']->autor))
                    ->filter()
                    ->unique()
                    ->values();

                if ($autori->count() === 1) {
                    $kanonski = (string) $autori->first();
                    $sviIds   = $this->katalog->nadjiAutoraStrogo(TextUtils::normalize($kanonski));
                    $izuzmi   = $this->imenovaniIdsIzKonteksta($namera, $history);

                    // „novija od X" — zadrži samo knjige mlađe od reference.
                    if (preg_match('/novij\w*\s+od/u', $norm)) {
                        $ref = $this->prvaImenovanaKnjiga($namera);
                        if ($ref !== null && $ref->godina_izdavanja) {
                            $mladje = collect($this->data->idsUOpseguGodina((int) $ref->godina_izdavanja + 1, null)['ids']);
                            $sviIds = $sviIds->intersect($mladje)->values();
                        }
                    }

                    return $this->fiksniOdgovor(
                        $this->strukturni->odgovorJosKnjigaAutora(
                            $this->genitivKanonskogImena($kanonski),
                            $sviIds,
                            $izuzmi
                        )
                    );
                }
            }
        }

        // 3.47) „JOŠ KNJIGA [autora]" uz IMENOVANU knjigu koje NEMA u katalogu
        //       i BEZ prepoznatog autora u fondu → ne mogu da odredim autora i
        //       NE izmišljam druge njegove knjige (kandidati_ucenje_3 #36 p27:
        //       „1984" i Orvel nisu u katalogu). Kada je knjiga u fondu, njome
        //       se bave rute iznad (relacioni / 3.46).
        if ($namera->josKnjiga
            && $namera->imenovanaKnjiga !== null
            && $leksicki->isEmpty()
            && $this->resolveImenovanuKnjigu($namera->imenovanaKnjiga) === null) {
            return $this->fiksniOdgovor(
                "Knjigu „{$namera->imenovanaKnjiga['prikaz']}” ne nalazim u katalogu, pa ne mogu "
                . 'da odredim njenog autora niti da vam preporučim druge njegove knjige. '
                . 'Mogu da vam pomognem sa pretragom po naslovu, autoru ili žanru.'
            );
        }

        // 3.5) IMENOVANA KNJIGA (anti-halucinacija, deterministički): korisnik
        //     je pod navodnicima ili posle „knjigu…" imenovao knjigu. Postojanje
        //     se proverava U BAZI: naslov → jak pogodak; autor → njegove knjige;
        //     žanrovska fraza → normalan tok; ništa od toga → FIKSNI odgovor
        //     „Trenutno nemamo…" sa preporukama — model se uopšte ne pita, pa
        //     ne može da izmisli da knjiga postoji ili da je „dostupna".
        if (!$namera->najnovije && !$namera->najcitanije
            && !$leksicki->contains(fn (array $h) => $h['razlog'] === 'naziv')
            && $namera->imenovanaKnjiga !== null) {

            $imenovana = $namera->imenovanaKnjiga;
            $poNaslovu = $this->katalog->nadjiPoNaslovu($imenovana['norm']);

            // DELIMIČNO imenovanje („knjiga isl…", „moja baka…"): korisnik
            // zna samo početak naslova — prefiks pretraga pre nego što se
            // zaključi da knjige nema.
            if ($poNaslovu->isEmpty()) {
                $poNaslovu = $this->katalog->nadjiPoPocetkuNaslova($imenovana['norm']);
            }

            if ($poNaslovu->isNotEmpty()) {
                $leksicki = $poNaslovu
                    ->map(fn (int $id) => ['id' => $id, 'score' => 1.0, 'razlog' => 'naziv'])
                    ->concat($leksicki)
                    ->unique('id')
                    ->take((int) config('ai.rag_top_k', 5))
                    ->values();
            } elseif (!$this->katalog->jeZanrovskaFraza($imenovana['norm'])) {
                $poAutoru = $this->katalog->nadjiPoAutoru($imenovana['norm']);

                if ($poAutoru->isNotEmpty()) {
                    $leksicki = $poAutoru
                        ->map(fn (int $id) => ['id' => $id, 'score' => 1.0, 'razlog' => 'autor'])
                        ->concat($leksicki)
                        ->unique('id')
                        ->take((int) config('ai.rag_top_k', 5))
                        ->values();
                } else {
                    return $this->odgovorNemamoKnjigu(
                        $imenovana['prikaz'],
                        $namera->slicnoUpit,
                        preporuka: (bool) preg_match('/preporuc|predloz/u', $norm)
                    );
                }
            }
        }

        // 3.55) „NEŠTO SLIČNO KAO…" bez pogođene referentne knjige: korisnik
        //       traži knjigu sličnu naslovu koji leksika NIJE našla u katalogu
        //       (nepoznata knjiga, naslov malim slovima…) → pošten fiksni
        //       odgovor sa molbom za žanr/temu. Sličnost sa knjigom koju ne
        //       poznajemo se NE procenjuje — semantika nad nepoznatim naslovom
        //       daje nasumične preporuke (ručno testiranje 2026-07-10).
        if ($namera->slicnoUpit && !$namera->tematsko && $leksicki->isEmpty()) {
            return $this->fiksniOdgovor(TextUtils::SLICNO_NEPOZNATO);
        }

        // 3.6) DOSTUPNOST / LISTA ČEKANJA / ZAHTEV ZA REZERVACIJU konkretne
        //      (leksički pogođene) knjige → FIKSNI odgovor iz SVEŽEG stanja
        //      baze. Model je za zauzetu knjigu umeo da kaže da može da se
        //      zaduži i da izmišlja stanje liste čekanja (ručno testiranje) —
        //      dostupnost i redosled su uvek deterministički. Za NEDOSTUPNU
        //      knjigu odgovor dobija i rep sa preporukama sličnih naslova
        //      (kaskada: semantika → najčitanije), da korisnik ne ode praznih
        //      ruku — rep sastavlja router, jer StrukturniUpiti ne vidi
        //      semantičke pogotke.
        $nazivHit = $leksicki->first(fn (array $h) => $h['razlog'] === 'naziv');

        // 3.57) „SLIČNO KNJIZI X" i kada je X pogođena u fondu: katalog nema
        //       opise, pa se sličnost NIKADA ne procenjuje — uvek ISTI pošten
        //       fiksni odgovor (eval za_kod.md, #19 p1–p5 i #20 p23: sve
        //       varijante „slično" dobijaju istu poruku, bez preporuka).
        if ($namera->slicnoUpit && !$namera->tematsko && $nazivHit !== null) {
            return $this->fiksniOdgovor(TextUtils::SLICNO_NEPOZNATO);
        }

        // 3.58) DETALJ IMENOVANE KNJIGE (deterministički): autor / izdavač /
        //       godina / opis-radnja — podatak se čita iz baze; za prazno
        //       polje pošten odgovor. Model je izmišljao radnju knjiga bez
        //       opisa i "gubio" izdavača/godinu (eval za_kod.md, set3).
        if ($nazivHit !== null && ($detalj = $this->detaljUpit($norm)) !== null) {
            $odgovorDetalj = $this->strukturni->odgovorDetaljKnjige($nazivHit['id'], $detalj);

            // KOMBINOVANO PITANJE „ko je napisao X i da li je dostupna?" —
            // uz detalj se dodaje i kratka dostupnost iz svežeg stanja baze
            // (kandidati_ucenje #33 p23: falila je povratna informacija o
            // dostupnosti primerka).
            if ($namera->dostupnostUpit && $detalj !== 'primerci') {
                $odgovorDetalj .= ' ' . $this->strukturni->dostupnostRecenica($nazivHit['id']);
            }

            return $this->fiksniOdgovor(trim($odgovorDetalj));
        }

        if ($nazivHit !== null && !$namera->najnovije && !$namera->najcitanije) {
            if ($namera->listaCekanja) {
                return $this->fiksniOdgovor($this->strukturni->odgovorListaCekanja($nazivHit['id']));
            }

            if ($namera->akcioniZahtev || $namera->dostupnostUpit) {
                // VIŠE pogođenih naslova („koja od tih je dostupna?" posle
                // spiska — naslovi iz prethodne razmene) → zbirna dostupnost.
                $naslovni = $leksicki->filter(fn (array $h) => $h['razlog'] === 'naziv')->values();

                if ($namera->dostupnostUpit && !$namera->akcioniZahtev && $naslovni->count() > 1) {
                    return $this->fiksniOdgovor($this->odgovorDostupnostVise($naslovni));
                }

                return $this->fiksniOdgovor($this->strukturni->odgovorDostupnost(
                    $nazivHit['id'],
                    $this->preporukeZaZauzetu($sirovi, $nazivHit['id'])
                ));
            }
        }

        // 3.62) POSTOJANJE imenovane knjige kada VIŠE naslova deli ISTO ime
        //       („da li imate knjigu Pesme" → dve „Pesme" iz različitih žanrova):
        //       nabrajaju se SVE istoimene, sa žanrom radi razlikovanja. Model je
        //       od istoimenih knjiga prikazivao samo jednu (ručno testiranje
        //       2026-07-21). Za JEDAN pogodak ostaje postojeći tok (knjiga ide
        //       modelu kao kontekst / „da li ste mislili" za tipfeler) — rute
        //       detalj/dostupnost/lista/slično su iznad i već su vratile odgovor.
        // Napomena: nazivHit postoji samo kada je knjiga IMENOVANA (ruta 3.5),
        // pa čist žanrovski upit („da li imate pesme iz poezije", bez „knjigu")
        // nikada ne stiže ovamo — zanrovskiUpit se svesno NE isključuje, jer je
        // za naslov-koji-je-i-žanrska-reč („Pesme") uvek uzgredno postavljen.
        if ($nazivHit !== null && !$namera->najnovije && !$namera->najcitanije
            && !$namera->slicnoUpit && !$namera->tematsko && !$namera->josKnjiga
            && !$namera->sadrzajnoPitanje
            && !$namera->autorKnjigeUpit && !$namera->nastavakUpit
            && $namera->relacioniKriterijum === null && $namera->poredjenjeKriterijum === null
            && preg_match('/\b(?:da li (?:imate|posedujete|postoji)|imate li|posedujete li|postoji li|da li u (?:fondu|biblioteci|katalogu))\b/u', $norm)) {
            $naslovni = $leksicki->filter(fn (array $h) => $h['razlog'] === 'naziv')->pluck('id');

            if ($naslovni->count() > 1) {
                return $this->fiksniOdgovor($this->strukturni->odgovorPostojanjeKnjige($naslovni));
            }
        }

        // 3.65) DOSTUPNOST VIŠE KNJIGA („koja od tih je dostupna?" posle
        //       spiska autora/žanra, „koje knjige od Bekmana su dostupne") —
        //       stanje se čita iz baze za SVE pogođene knjige, fiksno.
        //       Model je ovakav nastavak odbijao kao van teme (eval
        //       za_kod.md, #19 p22b).
        if ($namera->dostupnostUpit && $nazivHit === null && $leksicki->isNotEmpty()
            && !$namera->faq && !$faqIzMd
            && !$namera->najnovije && !$namera->najcitanije) {
            return $this->fiksniOdgovor($this->odgovorDostupnostVise($leksicki));
        }

        // 4) Kontra-upit (tačka 3): nijedan leksički pogodak, nikakav semantički
        //    trag i nijedna poznata reč → nameru nije moguće prepoznati.
        //    max umesto prvog: hits su poređani po RRF-u, ne po cosine-u.
        $najboljiSirovi = empty($sirovi) ? 0.0 : max(array_column($sirovi, 'score'));
        if ($leksicki->isEmpty()
            && $najboljiSirovi < self::MIN_TRAG_SCORE
            && !$faqIzMd
            && !$this->katalog->imaPoznatihReci($norm)) {
            return $this->fiksniOdgovor(TextUtils::NOT_RECOGNIZED);
        }

        // 4.1) „KO JE X" (deo 4): postojanje osobe se proverava U BAZI, i to
        //      STROGO po punom imenu — SVE reči imena moraju da pogode ISTOG
        //      autora (ime i prezime, ne samo prezime). Ranija labava provera
        //      (uz preskakanje kada leksika ima pogotke) je izmišljenu osobu
        //      proglašavala autorom čim joj je PREZIME slično nekom autoru iz
        //      fonda — zato guard sada važi i kada leksika ima pogotke.
        //      Osoba nije autor → fiksno odbijanje van teme; jeste autor →
        //      pogoci su upravo njene knjige (pitanje nije FAQ o bibliotekarki).
        if ($namera->koJe !== null && !$namera->faq && !$faqIzMd && $nazivHit === null) {
            // $nazivHit === null: „Ko je napisao/izdao <NASLOV>" nije pitanje
            // o osobi — kada je leksika pogodila naslov, detalj-handler (3.58)
            // ili kontekst knjige daju odgovor; VAN_TEME bi bio pogrešan
            // (eval za_kod.md, set3 #17 i #19).
            $knjigeOsobe = $this->katalog->nadjiAutoraStrogo(TextUtils::normalize($namera->koJe));

            if ($knjigeOsobe->isEmpty()) {
                return $this->fiksniOdgovor(SystemPromptService::VAN_TEME);
            }

            $leksicki = $knjigeOsobe
                ->map(fn (int $id) => ['id' => $id, 'score' => 1.0, 'razlog' => 'autor'])
                ->concat($leksicki)
                ->unique('id')
                ->take((int) config('ai.rag_top_k', 5))
                ->values();
        }

        // 4.2) VAN TEME (deo 4, deterministički): pitanje bez ijedne veze sa
        //      bibliotekom — nema pogodaka, semantika je šum, nijedna
        //      kataloška/FAQ intencija. Čisto konverzacione poruke (pozdrav,
        //      zahvalnica) NE dobijaju odbijanje — na njih odgovara model.
        //      NASTAVAK razgovora se nikada ne odbija kao van teme — za njega
        //      važi kontra-upit ispod (nejasna referenca → pojašnjenje).
        if (!$namera->nastavak
            && $leksicki->isEmpty()
            && ($ravnaSemantika || $najboljiSirovi < self::MIN_TRAG_SCORE)
            && !$namera->katalosko && !$namera->faq && !$faqIzMd && !$namera->tematsko
            && !$namera->zanrovskiUpit
            && !$namera->najnovije && !$namera->najcitanije && !$namera->agregatno
            && !$namera->konverzacioni) {
            return $this->fiksniOdgovor(SystemPromptService::VAN_TEME);
        }

        // 4.3) NASTAVAK SA NEJASNOM REFERENCOM: ni kombinovana pretraga ni
        //      semantika nisu razrešile na šta se korisnik nadovezuje →
        //      CILJANO potpitanje (šta da navede: naslov ili autora) umesto
        //      opšteg kontra-upita i umesto pogađanja (zahtev 6).
        if ($namera->nastavak
            && $leksicki->isEmpty()
            && $najboljiSirovi < self::MIN_TRAG_SCORE
            && !$namera->konverzacioni && !$namera->faq && !$faqIzMd
            && !$namera->najnovije && !$namera->najcitanije && !$namera->agregatno) {
            return $this->fiksniOdgovor(TextUtils::NEJASNA_REFERENCA);
        }

        // 5) Spajanje pogodaka: leksički (score 1.0) ispred semantičkih.
        $spojeno = [];
        foreach ($leksicki as $hit) {
            $spojeno[$hit['id']] = $hit['score'];
        }
        foreach ($semanticki as $hit) {
            $spojeno[$hit['id']] ??= $hit['score'];
        }
        $spojeno = array_slice($spojeno, 0, (int) config('ai.rag_top_k', 5), preserve_keys: true);

        $knjige = $this->data->hidriraj($spojeno);

        // 6) Preporuke (bib-pravila 6.1): korisnik traži nešto
        //    iz kataloga, a pogodaka nema. Preporuke se NE nude kada na pitanje
        //    odgovara drugi deo konteksta: pravila biblioteke (FAQ), agregati,
        //    liste najnovijih/najčitanijih ili broj knjiga sa rečju.
        //    Uslov „katalosko ILI jak semantički trag": pitanje van teme („liga
        //    šampiona") ima ključne reči ali ni pomen knjiga ni semantički trag
        //    — za njega se preporuke NE nude (model odgovara porukom van teme).
        //
        //    Dve vrste (razlikuje ih poruka u promptu):
        //    - ŽANROVSKI upit („imate li horor") → najsrodniji POSTOJEĆI žanr
        //      preko embedding sličnosti (glasanje žanrova najbližih knjiga),
        //      pa knjige iz tog žanra;
        //    - upit za KNJIGU/temu → najsličniji naslovi po embedding
        //      sličnosti, uz ponudu „ukoliko želite…".
        $preporuke    = collect();
        $preporukeTip = null;
        $srodniZanr   = null;

        if ($knjige->isEmpty()
            && !$namera->faq && !$faqIzMd
            && !$namera->agregatno
            && !$namera->najnovije
            && !$namera->najcitanije
            && !$namera->konverzacioni
            && ($namera->katalosko
                || (!empty($this->katalog->kljucneReci($norm))
                    && $najboljiSirovi >= self::MIN_TRAG_SCORE
                    && !$ravnaSemantika))) {

            $preporukeTip = 'knjiga';

            // Tematsko pitanje se ne tretira kao žanrovsko — preporuke idu
            // po sličnosti naslova/opisa, ne po srodnom žanru.
            if (!$namera->tematsko && $namera->zanrovskiUpit) {
                $zanr = $this->data->srodniZanr($sirovi);
                if ($zanr) {
                    $preporukeTip = 'zanr';
                    $srodniZanr   = $zanr->naziv;
                    $preporuke    = $this->data->knjigeIzZanra($zanr, 3);
                }
            }

            if ($preporuke->isEmpty()) {
                $preporuke = $this->data->hidriraj(
                    collect($sirovi)->take(3)->pluck('score', 'id')->all()
                );
            }

            if ($preporuke->isEmpty()) {
                $ids = $this->data->listaNajcitanijih()->pluck('id')->take(3)->all();
                $preporuke = $this->data->hidriraj(array_fill_keys($ids, 0.0));
            }
        }

        // 6.1) TRAŽENO NIJE PRONAĐENO, a preporuke postoje → FIKSNI odgovor
        //      (bez modela): model je za ovakva pitanja umeo da preporuku
        //      predstavi kao knjigu O TRAŽENOJ TEMI ili da izmisli naslov
        //      koga u katalogu nema (nalaz A1.8 — „knjiga za početnike iz
        //      programiranja"). Fiksna poruka pošteno kaže da traženog nema
        //      i nudi isključivo knjige iz kaskade preporuka (srodan žanr →
        //      semantika → najčitanije), po šablonima iz bib-izmena 5 i 6.
        // Generička preporuka: kaskadne preporuke se ne šalju kao „utešni"
        // fiksni odgovor — model odgovara iz najčitanijih/najnovijih listi.
        if ($generickaPreporuka) {
            $preporuke = collect();
        }

        if ($knjige->isEmpty() && $preporuke->isNotEmpty()) {
            $uvod = match (true) {
                $preporukeTip === 'zanr' => 'Trenutno nemamo knjige iz tog žanra, ali vam mogu '
                    . 'preporučiti srodne naslove'
                    . ($srodniZanr !== null ? " iz žanra {$srodniZanr}" : '') . ': ',
                $namera->tematsko => 'Za tu temu trenutno nisam pronašao odgovarajuće knjige u '
                    . 'katalogu. Ukoliko želite, mogu da vam preporučim ove naslove iz naše ponude: ',
                default => 'Za taj upit trenutno nisam pronašao odgovarajuće knjige u katalogu. '
                    . 'Ukoliko želite, mogu da vam preporučim ove naslove iz naše ponude: ',
            };

            return $this->fiksniOdgovor($uvod . $this->listaLinkova($preporuke) . '.');
        }

        return [
            'understood'    => true,
            'poruka'        => null,
            'knjige'        => $knjige,
            'preporuke'     => $preporuke,
            'preporuke_tip' => $preporuke->isEmpty() ? null : $preporukeTip,
            'srodni_zanr'   => $srodniZanr,
            'agregati'      => $this->data->agregatiKataloga(),
            'najnovije'     => ($namera->najnovije || $generickaPreporuka) ? $this->data->listaNajnovijih() : null,
            'najcitanije'   => ($namera->najcitanije || $generickaPreporuka) ? $this->data->listaNajcitanijih() : null,
            'faq_primeri'   => ($namera->faq || $faqIzMd) ? $this->faqZnanje->relevantni($norm) : [],
            // Semantički naučeni parovi (Faza B) — samo iza flaga i samo na
            // model-putu (fiksni odgovori izlaze ranije), pa je embed-poziv
            // ograničen na pitanja koja stvarno idu modelu.
            'nauceni'       => config('ai.nauceni_primeri') ? $this->nauceni->retrieve($message) : [],
            'clan'          => $this->resolveGuestMode() ? null : $this->data->clanKontekst((int) auth()->id()),
        ];
    }

    /**
     * Da li tekuće pitanje traži KONKRETAN DETALJ imenovane knjige —
     * opis/radnju, godinu izdanja, izdavača ili autora. Koristi se SAMO
     * kada je leksika pogodila naslov (3.58): odgovor tada daje baza,
     * deterministički, umesto modela (eval za_kod.md — izmišljena radnja
     * knjiga bez opisa, "izgubljeni" izdavač i godina).
     */
    private function detaljUpit(string $norm): ?string
    {
        return match (true) {
            (bool) preg_match('/ima (li )?opis|postoji (li )?opis/u', $norm) => 'ima_opis',
            (bool) preg_match('/koli?ko\s+(\w+\s+)?primer|broj\s+primeraka/u', $norm) => 'primerci',
            (bool) preg_match(
                '/o cemu|radnj|sadrzaj knjige|govori (knjiga|roman)|glavn\w* (lik|junak)'
                . '|likov|junak|koliko godina ima|reci(te)? mi (sve|nesto)( vise)? o '
                // Pitanja o OSOBINAMA lika („da li je baka u knjizi X stroga i
                // ozbiljna?", „kakav je glavni lik?") — odgovor bi morao iz
                // opisa; bez opisa sledi ista poštena poruka
                // (kandidati_ucenje #31 p5).
                . '|kakv[aio] je|da li je .{0,40}\bu (knjizi|romanu|prici)/u',
                $norm
            ) => 'opis',
            (bool) preg_match('/koje\s+(?:je\s+)?godine|koja\s+(?:je\s+)?godina|godin[aeiu] izdanja|kad\w*\s+(?:je\s+)?(?:izdat|objavljen|izasl)|godine\s+(?:je\s+)?(?:izdat|objavljen|izasl)/u', $norm) => 'godina',
            (bool) preg_match('/izdavac|ko (je )?izda[oj]e?|ko izdaje/u', $norm) => 'izdavac',
            (bool) preg_match('/ko je (napisao|autor|pisac)|ko je pisao|cij[ai] je knjig/u', $norm) => 'autor',
            (bool) preg_match('/koj(i|em) (je )?zanr|zanr (je )?knjig|kom zanru pripada/u', $norm) => 'zanr',
            default => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // METAPODACI — statistika, poređenje i relacije (kandidati_ucenje_2.md)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * FIKSNI odgovori na strukturna pitanja o metapodacima kataloga (autor sa
     * najviše naslova, godina sa najviše knjiga, najstarija/najnovija, mesto
     * izdavanja, poređenje dve imenovane knjige, „iste osobine kao X") —
     * deterministički iz baze, model se ne poziva. Null kada pitanje nije
     * metapodatak-upit (ide dalje kroz pipeline).
     */
    private function metapodatakOdgovor(Namera $n): ?array
    {
        // AUTOR × ŽANR (korpa A) — PRE autorStatistike, koja bi ukršteno pitanje
        // shvatila kao „autor sa najviše naslova UKUPNO" (kandidati_ucenje_5,
        // set11 p13, set12 p10).
        if ($n->autorViseZanrova) {
            return $this->fiksniOdgovor($this->strukturni->odgovorAutorViseZanrova());
        }

        // „Koji autor ima najviše knjiga iz žanra Z" — samo kada je žanr izričito
        // u pitanju. Žanr iz fonda → rangiranje autora u tom žanru; poznat žanr
        // van fonda („psihologija") → pošten odgovor bez izmišljanja.
        if ($n->autorStatistika === 'najvise' && $n->zanrovskiUpit && $n->izvornaNorm !== null) {
            $zanroviUpita = $this->strukturni->nadjiZanroveUTekstu($n->izvornaNorm);

            if ($zanroviUpita->isNotEmpty()) {
                return $this->fiksniOdgovor(
                    $this->strukturni->odgovorAutorNajviseUZanru($zanroviUpita->first())
                );
            }

            if ($this->classifier->imaZanrVanFonda($n->izvornaNorm)) {
                return $this->fiksniOdgovor($this->strukturni->odgovorZanrVanFonda());
            }
        }

        // ZADUŽENJA NAD KNJIGOM (korpa B) — istorija imenovane knjige, najmanje
        // pozajmljivana, preporuka nezaduživanih. Pre autorStatistike i pre
        // generičke preporuke (0e) / nikadZaduzene rute.
        if ($n->ikadZaduzenaUpit && count($n->imenovaneKnjige) >= 1) {
            $knjiga = $this->resolveImenovanuKnjigu($n->imenovaneKnjige[0]);

            return $this->fiksniOdgovor(
                $knjiga !== null
                    ? $this->strukturni->odgovorIkadZaduzena($knjiga)
                    : "Knjigu „{$n->imenovaneKnjige[0]['prikaz']}” nemam u katalogu, "
                        . 'pa ne mogu da proverim njena zaduženja.'
            );
        }

        if ($n->nepozajmljenaPreporuka) {
            return $this->fiksniOdgovor($this->strukturni->odgovorPreporukaNepozajmljene());
        }

        if ($n->najmanjePozajmljivana) {
            return $this->fiksniOdgovor($this->strukturni->odgovorNajmanjePozajmljivana());
        }

        // AGREGACIJA ZADUŽENJA PO AUTORU / ŽANRU (korpe C+D) — PRE autorStatistike
        // (koja bi „najviše pozajmljenih" shvatila kao najviše NASLOVA) i pre
        // prosek/nikad ruta (koje računaju nad CELIM katalogom, ne po grupi).
        if (($gz = $this->classifier->grupaZaduzenja($n->izvornaNorm ?? '')) !== null) {
            return $this->fiksniOdgovor(
                $this->strukturni->odgovorGrupaZaduzenja($gz['grupa'], $gz['metrika'])
            );
        }

        // „Koji DOMAĆI/STRANI pisac ima najviše knjiga" (Potez 2) — PRE opšte
        // autorStatistike, koja bi vratila apsolutno najzastupljenijeg autora
        // (npr. stranog za pitanje o domaćem — set13 p11).
        if ($n->autorStatPodela !== null) {
            return $this->odgovorAutorNajvisePodela($n->autorStatPodela);
        }

        if ($n->autorStatistika !== null) {
            // „koji DOMAĆI/STRANI autori imaju više od jedne / najviše knjiga"
            // (set17 p6/p7) — ista statistika, ali filtrirana po poreklu pisca.
            $klasa = $n->izvornaNorm !== null ? $this->classifier->podelaKlasa($n->izvornaNorm) : null;
            if ($klasa !== null) {
                return $this->odgovorAutorStatistikaPodela($n->autorStatistika, $klasa);
            }

            return $this->fiksniOdgovor($this->strukturni->odgovorAutorStatistika($n->autorStatistika));
        }

        if ($n->godinaNajvise) {
            return $this->fiksniOdgovor($this->strukturni->odgovorGodinaNajvise());
        }

        if ($n->ekstremKnjiga !== null) {
            // „najnovija/najstarija knjiga DOMAĆEG/STRANOG autora" (set16 p24,
            // set17 p16/p24) — ekstrem po godini izdanja, filtriran po poreklu.
            $klasa = $n->izvornaNorm !== null ? $this->classifier->podelaKlasa($n->izvornaNorm) : null;
            if ($klasa !== null) {
                return $this->odgovorEkstremPodela($n->ekstremKnjiga === 'najstarija', $klasa);
            }
        }

        if ($n->ekstremKnjiga === 'najstarija') {
            return $this->fiksniOdgovor($this->strukturni->odgovorEkstremPoGodini(true));
        }

        if ($n->ekstremKnjiga === 'najnovija') {
            return $this->fiksniOdgovor($this->odgovorNajnovijaKnjiga());
        }

        // POREĐENJE dve imenovane knjige (godina / izdavač / mesto).
        if ($n->poredjenjeKriterijum !== null && count($n->imenovaneKnjige) >= 2) {
            return $this->odgovorPoredjenjeKnjiga($n);
        }

        // „KO JE AUTOR knjige X" (kandidati_ucenje_6) — ime autora imenovane
        // knjige, PRE relacionog „još knjiga istog autora" (koji hvata istu
        // frazu „autora knjige"). Kombinovano pitanje „ko je napisao X I da li
        // je dostupna" ide svojim (kasnijim) tokom koji vraća oba podatka.
        if ($n->autorKnjigeUpit && !$n->dostupnostUpit && count($n->imenovaneKnjige) >= 1) {
            return $this->odgovorAutorKnjige($n);
        }

        // RELACIONI upit — ostale knjige koje dele osobinu sa imenovanom.
        if ($n->relacioniKriterijum !== null && count($n->imenovaneKnjige) >= 1) {
            return $this->odgovorRelacioni($n);
        }

        // NASTAVAK imenovane knjige — druga dela istog autora/serijala.
        if ($n->nastavakUpit && count($n->imenovaneKnjige) >= 1) {
            return $this->odgovorNastavak($n->imenovaneKnjige[0]);
        }

        // „Postoje li dve knjige istog autora iz iste godine" — analitika.
        if ($n->dveIsteGodine) {
            return $this->fiksniOdgovor($this->strukturni->odgovorDveIsteGodine());
        }

        // MESTO izdavanja se rešava POSLE leksike (autor∩mesto presek), pa
        // ovde ne staje.
        return null;
    }

    /**
     * „Koja je najnovija knjiga koju imate" — dvosmisleno: po godini izdanja
     * ILI nedavno dodato u katalog (sekcija Najnovije, kurirana od bibliotekara).
     * Nudi oba tumačenja (kandidati_ucenje_2 #34 p9).
     */
    private function odgovorNajnovijaKnjiga(): string
    {
        $poGodini = $this->data->ekstremPoGodini(false);

        $deo = $poGodini !== null
            ? "Ako mislite na knjigu sa najvećom godinom izdanja, to je "
                . "[{$poGodini->naziv_knjige}](/knjige/{$poGodini->id})"
                . ($poGodini->autor ? " ({$poGodini->autor})" : '')
                . " iz {$poGodini->godina_izdavanja}. godine."
            : 'Za knjige u katalogu godina izdanja nije uvek upisana, pa najnoviju po godini ne mogu pouzdano da odredim.';

        return "{$deo} Ako ipak mislite na knjige koje su nedavno dodate u katalog, njih možete "
            . 'videti u sekciji „Najnovije" na početnoj strani — recite mi na šta tačno mislite.';
    }

    /**
     * Poređenje DVE imenovane knjige po traženom kriterijumu. Knjiga koja nije
     * u katalogu → pošten odgovor sa njenim nazivom.
     */
    private function odgovorPoredjenjeKnjiga(Namera $n): array
    {
        $a = $this->resolveImenovanuKnjigu($n->imenovaneKnjige[0]);
        $b = $this->resolveImenovanuKnjigu($n->imenovaneKnjige[1]);

        if ($a === null || $b === null) {
            $koja = $a === null ? $n->imenovaneKnjige[0]['prikaz'] : $n->imenovaneKnjige[1]['prikaz'];
            return $this->fiksniOdgovor(
                "Ne mogu da uporedim te dve knjige jer se „{$koja}” ne nalazi u katalogu kojim raspolažem."
            );
        }

        return $this->fiksniOdgovor(match ($n->poredjenjeKriterijum) {
            'popularnost' => $this->strukturni->odgovorPoredjenjePopularnost($a, $b),
            'starija'   => $this->strukturni->odgovorPoredjenjeGodina($a, $b, true),
            'novija'    => $this->strukturni->odgovorPoredjenjeGodina($a, $b, false),
            'izdavac'   => $this->strukturni->odgovorPoredjenjeIzdavac($a, $b),
            'mesto'     => $this->odgovorPoredjenjeMesto($a, $b, $n, van: false),
            'van_mesta' => $this->odgovorPoredjenjeMesto($a, $b, $n, van: true),
            default     => $this->strukturni->odgovorPoredjenjeGodina($a, $b, false),
        });
    }

    /**
     * Poređenje mesta izdanja dve knjige — mesto se razrešava iz upita
     * („u Beogradu" ili „van Beograda"). $van = true traži knjigu VAN mesta.
     */
    private function odgovorPoredjenjeMesto(Knjiga $a, Knjiga $b, Namera $n, bool $van): string
    {
        $frazaMesta = $this->mestoZaPoredjenje($n, $van);

        if ($frazaMesta === null) {
            return $this->strukturni->odgovorPoredjenjeIzdavac($a, $b);
        }

        $mesto = $this->strukturni->nadjiMesto($frazaMesta['norm']);

        if ($mesto === null) {
            // Mesto ne postoji u katalogu: „van njega" znači obe su van.
            return $van
                ? "Obe knjige su izdate van mesta {$frazaMesta['prikaz']} — to mesto ne postoji u katalogu."
                : "Nijedna od te dve knjige nije izdata u mestu {$frazaMesta['prikaz']} — to mesto ne postoji u katalogu.";
        }

        return $van
            ? $this->strukturni->odgovorPoredjenjeVanMesta($a, $b, $mesto['prikaz'], $mesto['ids'])
            : $this->strukturni->odgovorPoredjenjeMesto($a, $b, $mesto['prikaz'], $mesto['ids']);
    }

    /**
     * Mesto iz pitanja za poređenje: „objavljena u X" (mestoFraza) ili
     * „van X" (za negaciju). Vraća ['prikaz','norm'] ili null.
     *
     * @return array{prikaz: string, norm: string}|null
     */
    private function mestoZaPoredjenje(Namera $n, bool $van): ?array
    {
        if ($van && $n->izvornaNorm !== null
            && preg_match('/\bvan\s+([a-zšđžčć]{3,})/u', $n->izvornaNorm, $m)) {
            return ['prikaz' => ucfirst($m[1]), 'norm' => $m[1]];
        }

        if ($n->mestoFraza !== null) {
            return $n->mestoFraza;
        }

        if ($n->izvornaNorm !== null && preg_match('/\bu\s+([a-zšđžčć]{3,})/u', $n->izvornaNorm, $m)) {
            return ['prikaz' => ucfirst($m[1]), 'norm' => $m[1]];
        }

        return null;
    }

    /**
     * „Ko je autor knjige X" — ime autora IMENOVANE knjige iz baze
     * (kandidati_ucenje_6 set3 p15/p18). Knjiga van kataloga ili bez upisanog
     * autora → pošten odgovor.
     */
    private function odgovorAutorKnjige(Namera $n): array
    {
        $ref = $this->resolveImenovanuKnjigu($n->imenovaneKnjige[0]);

        if ($ref === null) {
            return $this->fiksniOdgovor(
                "Knjigu „{$n->imenovaneKnjige[0]['prikaz']}” nemam u katalogu, pa ne mogu da kažem ko je autor."
            );
        }

        $link = "[{$ref->naziv_knjige}](/knjige/{$ref->id})";

        if (trim((string) $ref->autor) === '') {
            return $this->fiksniOdgovor("Za knjigu {$link} autor nije upisan u katalog.");
        }

        $rec = TextUtils::jeZenskoIme((string) $ref->autor) ? 'Autorka' : 'Autor';

        return $this->fiksniOdgovor("{$rec} knjige {$link} je {$ref->autor}.");
    }

    /**
     * RELACIONI upit „iste osobine kao knjiga X" — ostale knjige istog
     * izdavača / godine / autora kao imenovana referenca. Referenca van
     * kataloga → pošten odgovor (kandidati_ucenje_2 #34 p25).
     */
    private function odgovorRelacioni(Namera $n): array
    {
        $ref = $this->resolveImenovanuKnjigu($n->imenovaneKnjige[0]);

        if ($ref === null) {
            $prikaz = $n->imenovaneKnjige[0]['prikaz'];

            // Za pitanje o AUTORU reference: knjiga nije u katalogu → NE mogu
            // da odredim autora i ne smem da izmišljam druge njegove knjige
            // (kandidati_ucenje_3 #36 p21/p26, #39 p23 — bez Milorada Pavića).
            if ($n->relacioniKriterijum === 'autor') {
                return $this->fiksniOdgovor(
                    "Knjigu „{$prikaz}” ne nalazim u katalogu, pa ne mogu da odredim njenog autora "
                    . 'niti da vam preporučim druge knjige istog autora. '
                    . 'Mogu da vam pomognem sa pretragom po naslovu, autoru ili žanru.'
                );
            }

            return $this->fiksniOdgovor(
                "Ne mogu pouzdano da odgovorim na to pitanje, jer se knjiga "
                . "„{$prikaz}” ne nalazi u bibliotečkom katalogu kojim raspolažem. "
                . 'Mogu da uporedim samo knjige koje se nalaze u katalogu.'
            );
        }

        return $this->fiksniOdgovor(match ($n->relacioniKriterijum) {
            'izdavac' => $this->strukturni->odgovorIstiIzdavacKao($ref),
            'godina'  => $this->strukturni->odgovorIstaGodinaKao($ref),
            'autor'   => $this->odgovorIstiAutorKao($ref),
            default   => $this->strukturni->odgovorIstiIzdavacKao($ref),
        });
    }

    /**
     * „Postoji li nastavak knjige X" — druga dela istog autora, sa prednošću
     * naslova koji dele početak sa referencom (isti serijal, npr. „Solunska
     * 28. …"). Referenca van kataloga → pošteno kaže (kandidati_ucenje_3
     * #39 p3, #36 p29).
     *
     * @param  array{prikaz: string, norm: string}  $im
     */
    private function odgovorNastavak(array $im): array
    {
        $ref = $this->resolveImenovanuKnjigu($im);

        if ($ref === null) {
            return $this->fiksniOdgovor(
                "Knjigu „{$im['prikaz']}” ne nalazim u katalogu, pa ne raspolažem informacijama "
                . 'o njoj ni o njenom eventualnom nastavku.'
            );
        }

        $refLink = "[{$ref->naziv_knjige}](/knjige/{$ref->id})";
        $autor   = trim((string) $ref->autor);

        if ($autor === '') {
            return $this->fiksniOdgovor(
                "Za knjigu {$refLink} autor nije upisan u katalog, pa ne mogu da pronađem njen nastavak."
            );
        }

        $ostali = $this->katalog->nadjiAutoraStrogo(TextUtils::normalize($autor))
            ->reject(fn (int $id) => $id === $ref->id)
            ->values();

        if ($ostali->isEmpty()) {
            return $this->fiksniOdgovor(
                "Za knjigu {$refLink} trenutno nemamo nastavak ni drugo delo istog autora u katalogu."
            );
        }

        // Serijal: naslovi koji dele prve dve reči sa referencom imaju prednost.
        $prefiks = array_slice(TextUtils::tokenizuj(TextUtils::normalize((string) $ref->naziv_knjige)), 0, 2);
        $knjige  = $this->data->knjigeAzbucno($ostali->all());

        $serijal = $prefiks === []
            ? collect()
            : $knjige->filter(function (Knjiga $k) use ($prefiks) {
                $t = TextUtils::tokenizuj(TextUtils::normalize((string) $k->naziv_knjige));
                return array_slice($t, 0, count($prefiks)) === $prefiks;
            })->values();

        if ($serijal->isNotEmpty()) {
            $lista = $serijal->map(fn (Knjiga $k) => $this->linkKnjige($k))->implode(', ');
            return $this->fiksniOdgovor(
                "Da, u katalogu postoji nastavak knjige {$refLink}: {$lista}."
            );
        }

        $lista = $knjige->map(fn (Knjiga $k) => $this->linkKnjige($k))->implode(', ');
        return $this->fiksniOdgovor(
            "Poseban nastavak knjige {$refLink} nemam u katalogu, ali od istog autora "
            . "({$autor}) imamo: {$lista}."
        );
    }

    /** Ostale knjige AUTORA imenovane knjige („još knjiga autora knjige X"). */
    private function odgovorIstiAutorKao(Knjiga $ref): string
    {
        $kanonski = trim((string) $ref->autor);

        if ($kanonski === '') {
            return "Za knjigu [{$ref->naziv_knjige}](/knjige/{$ref->id}) autor nije upisan u katalog, "
                . 'pa ne mogu da pronađem druge knjige istog autora.';
        }

        $sviIds = $this->katalog->nadjiAutoraStrogo(TextUtils::normalize($kanonski));

        return $this->strukturni->odgovorJosKnjigaAutora(
            $this->genitivKanonskogImena($kanonski),
            $sviIds,
            [$ref->id]
        );
    }

    /** Razrešava imenovanu knjigu (navodnici) u model iz kataloga, ili null. */
    private function resolveImenovanuKnjigu(array $im): ?Knjiga
    {
        $ids = $this->katalog->nadjiPoNaslovu($im['norm']);

        if ($ids->isEmpty()) {
            $ids = $this->katalog->nadjiPoPocetkuNaslova($im['norm']);
        }

        return $ids->isEmpty() ? null : $this->data->nadjiKnjigu($ids->first());
    }

    /** Prva imenovana knjiga razrešena u model (za „novija od X" filter). */
    private function prvaImenovanaKnjiga(Namera $n): ?Knjiga
    {
        return empty($n->imenovaneKnjige) ? null : $this->resolveImenovanuKnjigu($n->imenovaneKnjige[0]);
    }

    /**
     * ID-jevi knjiga koje su već POMENUTE (i treba ih izostaviti iz „još
     * knjiga autora"): imenovane u tekućem pitanju + navedene pod navodnicima
     * u poslednjoj korisničkoj poruci prethodne razmene.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, int>
     */
    /**
     * ID-jevi knjiga koje je bot naveo u POSLEDNJEM svom odgovoru (linkovi
     * „/knjige/ID") — pouzdan trag „o čemu je bila prethodna razmena" za
     * razrešavanje zamenica („njene knjige") i elidiranih detalja („koje je
     * godine izdata"). Gleda SAMO poslednju asistentsku poruku (jedna unazad).
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, int>
     */
    private function idsIzPrethodnogOdgovora(array $history): array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') !== 'assistant') {
                continue;
            }

            if (preg_match_all('#/knjige/(\d+)#', (string) ($history[$i]['content'] ?? ''), $m)) {
                return array_values(array_unique(array_map('intval', $m[1])));
            }

            return [];
        }

        return [];
    }

    private function imenovaniIdsIzKonteksta(Namera $n, array $history): array
    {
        $ids = [];

        foreach ($n->imenovaneKnjige as $im) {
            $k = $this->resolveImenovanuKnjigu($im);
            if ($k !== null) {
                $ids[] = $k->id;
            }
        }

        // Poslednja korisnička poruka iz istorije — naslovi pod navodnicima.
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') !== 'user') {
                continue;
            }
            foreach ($this->classifier->izvuciSveImenovaneKnjige((string) $history[$i]['content']) as $im) {
                $k = $this->resolveImenovanuKnjigu($im);
                if ($k !== null) {
                    $ids[] = $k->id;
                }
            }
            break;
        }

        return array_values(array_unique($ids));
    }

    /**
     * NASTAVAK — ostatak skraćenog spiska: ponovo izvršava nameru PRETHODNOG
     * pitanja sa pomerajem (handler preskače već ispisane stavke). Null kada
     * prethodna namera nije spisak koji ume da se nastavi — tada poruka ide
     * normalnim tokom.
     */
    private function ostatakSpiska(Namera $prethodna): ?string
    {
        // Autorska lista (+ eventualni žanrovski presek) — pre match-a po
        // tipu, jer autorska fraza nema sopstveni TipNamere (kombinovano
        // pitanje ume da ponese tip ZanrLista, a važi presek autora).
        if ($prethodna->autorFraza !== null) {
            $knjige = $this->katalog->nadjiAutoraStrogo($prethodna->autorFraza['norm']);

            if ($knjige->isEmpty()) {
                return null;
            }

            $zanr = null;
            if ($prethodna->zanrovskiUpit && $prethodna->izvornaNorm !== null) {
                $zanr = $this->strukturni->nadjiZanroveUTekstu($prethodna->izvornaNorm)->first();
                if ($zanr !== null) {
                    $knjige = $knjige->intersect($this->data->knjigeZanra($zanr)->pluck('id'))->values();
                }
            }

            if ($prethodna->godinaOpseg !== null) {
                $uGodini = collect($this->data->idsUOpseguGodina(
                    $prethodna->godinaOpseg['od'] ?? null,
                    $prethodna->godinaOpseg['do'] ?? null
                )['ids']);
                $knjige = $knjige->intersect($uGodini)->values();
            }

            return $this->strukturni->odgovorAutorLista(
                $prethodna->autorFraza['prikaz'],
                $knjige,
                $zanr,
                ostatak: true,
                godinaOpseg: $prethodna->godinaOpseg
            );
        }

        // Lista imenovanog izdavača (+ eventualni žanr/godina) — isti presek
        // kao u glavnoj ruti, sa pomerajem.
        if ($prethodna->izdavacFraza !== null) {
            $zanr = $prethodna->zanrovskiUpit && $prethodna->izvornaNorm !== null
                ? $this->strukturni->nadjiZanroveUTekstu($prethodna->izvornaNorm)->first()
                : null;

            return $this->strukturni->odgovorIzdavacLista(
                $prethodna->izdavacFraza['norm'],
                $prethodna->izdavacFraza['prikaz'],
                $zanr,
                $prethodna->godinaOpseg,
                ostatak: true
            );
        }

        return match ($prethodna->tip) {
            TipNamere::DatumskiOpseg => ($prethodna->datumskiOpseg['preko_limita'] ?? false)
                ? null
                : $this->strukturni->odgovorZaduzeneUOpsegu($prethodna->datumskiOpseg['dana'], ostatak: true),

            TipNamere::ZanrLista => ($zanr = $this->strukturni->nadjiZanr((string) $prethodna->zanrListaFraza)) !== null
                ? $this->strukturni->odgovorZanrLista($zanr, ostatak: true)
                : null,

            TipNamere::BrojanjeReci => $this->strukturni->odgovorBrojKnjigaSaRecju(
                $this->katalog->brojKnjigaSaRecju((string) $prethodna->countRec),
                ostatak: true
            ),

            TipNamere::PocinjeNaSlovo => $prethodna->pocinjeSlovo !== null
                ? $this->strukturni->odgovorPocinjeNaSlovo(
                    $prethodna->pocinjeSlovo,
                    $this->katalog->naslovNaSlovo($prethodna->pocinjeSlovo),
                    ostatak: true
                )
                : null,

            TipNamere::GodinaIzdanja => $prethodna->godinaOpseg !== null
                ? $this->strukturni->odgovorGodinaOpseg(
                    $prethodna->godinaOpseg,
                    $prethodna->zanrovskiUpit && $prethodna->izvornaNorm !== null
                        ? $this->strukturni->nadjiZanroveUTekstu($prethodna->izvornaNorm)->first()
                        : null,
                    ostatak: true
                )
                : null,

            TipNamere::NikadZaduzene => $this->strukturni->odgovorNikadZaduzene(ostatak: true),

            TipNamere::IzdavacStatistika => $prethodna->izdavacStatistika !== null
                ? $this->strukturni->odgovorIzdavacStatistika($prethodna->izdavacStatistika, ostatak: true)
                : null,

            default => null,
        };
    }

    /**
     * FIKSNI odgovor za imenovanu knjigu koje NEMA u bazi (provereno po
     * naslovu i autoru) — model se ne poziva, pa ne može da potvrdi
     * postojanje nepostojeće knjige („Na Drini ćuprija" slučaj).
     *
     * „DA LI STE MISLILI…": pre generičke poruke se traži najbliži POSTOJEĆI
     * naslov (prefiks / tipfeler / pokrivenost reči) — kada ga ima, nudi se
     * ISPRAVKA naziva (jedno pitanje, zlatno pravilo kratkoće).
     *
     * $slicno (namera->slicnoUpit): korisnik traži knjigu SLIČNU imenovanoj —
     * sličnost se ne procenjuje (nema opisa) → ista fiksna poruka kao za
     * sva „slično" pitanja (eval za_kod.md, #19 p1–p5).
     */
    private function odgovorNemamoKnjigu(string $prikaz, bool $slicno = false, bool $preporuka = false): array
    {
        $predlogId = $this->katalog->predloziNaslov(TextUtils::normalize($prikaz));

        if ($predlogId !== null
            && ($predlog = $this->data->hidriraj([$predlogId => 1.0])->first()) !== null) {
            return $this->fiksniOdgovor(
                "Trenutno nemamo knjigu „{$prikaz}” u katalogu. Da li ste možda mislili na "
                . $this->listaLinkova(collect([$predlog])) . '?'
            );
        }

        if ($slicno) {
            return $this->fiksniOdgovor(TextUtils::SLICNO_NEPOZNATO);
        }

        // „Ako ne nađeš X, šta bi mi preporučio" — preporuka je vezana za
        // knjigu koje NEMA, a katalog nema opise, pa se preporuka po tematici
        // ne može dati (kandidati_ucenje_4, set7 p25). Pošteno to kažemo.
        if ($preporuka) {
            return $this->fiksniOdgovor(
                "Trenutno nemamo knjigu „{$prikaz}” u katalogu, pa vam na osnovu nje ne mogu "
                . 'preporučiti sličan naslov — katalog još ne sadrži opise knjiga, tako da se '
                . 'sličnost po tematici ne može proceniti. Mogu da vam pomognem pretragom po '
                . 'naslovu, autoru, izdavaču ili godini izdanja.'
            );
        }

        return $this->odgovorNemamo("Trenutno nemamo knjigu „{$prikaz}” u katalogu.");
    }

    /**
     * Rep sa preporukama sličnih naslova za fiksni odgovor o NEDOSTUPNOJ
     * knjizi — ista kaskada kao odgovorNemamo (semantika → najčitanije),
     * uz izuzimanje same tražene knjige. Prazan string kada preporuka nema
     * (odgovor tada ostaje bez repa).
     *
     * @param  array<int, array{id:int, score:float}>  $sirovi
     */
    private function preporukeZaZauzetu(array $sirovi, int $izuzmiId): string
    {
        $kandidati = collect($sirovi)
            ->filter(fn (array $h) => $h['id'] !== $izuzmiId)
            ->take(3)
            ->pluck('score', 'id')
            ->all();

        $preporuke = $this->data->hidriraj($kandidati);

        if ($preporuke->isEmpty()) {
            $ids = $this->data->listaNajcitanijih()
                ->pluck('id')
                ->reject(fn (int $id) => $id === $izuzmiId)
                ->take(3)
                ->all();
            $preporuke = $this->data->hidriraj(array_fill_keys($ids, 0.0));
        }

        return $preporuke->isEmpty()
            ? ''
            : 'U međuvremenu vam mogu preporučiti slične naslove: '
                . $this->listaLinkova($preporuke) . '.';
    }

    /**
     * Zajednički FIKSNI odgovor „traženog nema" (knjiga ili autor) — bez
     * nasumičnih preporuka: semantički „slični" naslovi za nepoznatu knjigu
     * su nasumični i zbunjuju korisnika (eval za_kod.md, #22 p19–p21) —
     * umesto njih pošten poziv na pretragu. Model se ne poziva.
     */
    private function odgovorNemamo(string $poruka): array
    {
        return $this->fiksniOdgovor(
            $poruka . ' Da li vas interesuje nešto drugo? Mogu da vam pomognem '
            . 'sa pretragom po naslovu, autoru ili žanru.'
        );
    }

    /**
     * Da li heuristički autor-kandidat IZGLEDA kao ime osobe: bar dve reči,
     * svaka velikim početnim slovom („Harukija Murakamija"). Takav kandidat
     * kog nema u bazi dobija pošteno „nemamo knjige tog autora" umesto
     * odbijanja van teme; male reči („knjige o renesansi") idu normalnim tokom.
     */
    private function izgledaKaoImeOsobe(string $prikaz): bool
    {
        $reci = preg_split('/\s+/u', trim($prikaz)) ?: [];

        if (count($reci) < 2) {
            return false;
        }

        foreach ($reci as $rec) {
            $prvo = mb_substr($rec, 0, 1);
            if ($prvo === '' || $prvo === mb_strtolower($prvo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Skida uvodno „Da." / „Ne," iz FAQ odgovora kada pitanje NIJE da/ne
     * oblika: na „Kako da otkažem rezervaciju?" odgovor ne sme da počne sa
     * „Da." (kandidati_ucenje #32 p16). Da/ne pitanja zadržavaju potvrdu.
     */
    private function bezDaNePrefiksa(string $odgovor, string $norm): string
    {
        if (preg_match(
            '/^\s*(da\s+li|dal\b|jel\b|je\s+l\b|mogu\s+li|moze(mo|te|s)?\s+li'
            . '|imate?\s+li|imas\s+li|ima\s+li|postoj[ie]\s+li|sme(m|s|mo|te)?\s+li)/u',
            $norm
        )) {
            return $odgovor;
        }

        $bez = preg_replace('/^(Da|Ne)[.,]\s+/u', '', $odgovor);

        if ($bez === null || $bez === $odgovor || $bez === '') {
            return $odgovor;
        }

        return mb_strtoupper(mb_substr($bez, 0, 1)) . mb_substr($bez, 1);
    }

    /**
     * PUNO ime autora za fiksnu autorsku listu: kada je korisnik imenovao
     * autora KRAĆE od kanonskog imena iz baze („od Nušića"), prikaz se
     * zamenjuje kanonskim imenom (uz bezbedan genitiv); fraza sa isto reči
     * kao kanonsko ime ostaje kako ju je korisnik napisao — on je već
     * izabrao padež („od autora Fredrika Bekmana").
     *
     * @param  array{prikaz: string, norm: string}  $autor
     * @param  Collection<int, int>  $knjigeAutora
     */
    private function punoImeAutora(array $autor, Collection $knjigeAutora): string
    {
        $prva     = $this->data->nadjiKnjigu((int) $knjigeAutora->first());
        $kanonski = trim((string) ($prva->autor ?? ''));

        if ($kanonski === '') {
            return $this->velikoImePrikaz($autor['prikaz']);
        }

        $reciKorisnika = count(preg_split('/\s+/u', trim($autor['prikaz'])) ?: []);
        $reciKanonske  = count(preg_split('/\s+/u', $kanonski) ?: []);

        // Kada je korisnik dao PUNO ime, zadržavamo njegov oblik (najčešće ispravan
        // genitiv, npr. „hermana hesea" za strano -e prezime koje pravilo teško
        // deklinira), samo velikim početnim slovom. Kada je dao samo deo (prezime),
        // genitiv računamo iz punog kanonskog imena.
        return $reciKorisnika < $reciKanonske
            ? $this->genitivKanonskogImena($kanonski)
            : $this->velikoImePrikaz($autor['prikaz']);
    }

    /** Ime iz upita → veliko početno slovo svake reči (student ga kuca malim). */
    private function velikoImePrikaz(string $ime): string
    {
        return mb_convert_case(trim($ime), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Odseca REPNE stop-reči iz izvučene fraze imena („dzona grisama mogu" →
     * „dzona grisama"): greedy hvatanje imena ume da povuče i glagol iza imena.
     */
    private function trimImeRep(string $fraza): string
    {
        $reci = preg_split('/\s+/u', trim($fraza)) ?: [];

        while (!empty($reci)
            && in_array(TextUtils::normalize((string) end($reci)), IntentClassifier::STOP_RECI, true)) {
            array_pop($reci);
        }

        return implode(' ', $reci);
    }

    /**
     * KURIRANI genitiv poznatih muških imena koja se ZAVRŠAVAJU na samoglasnik
     * — njihov genitiv je nepravilan pa se ne izvodi pravilom (normalizovan
     * ključ → tačan oblik). Bez ove mape ime ostaje u nominativu (bezbedno).
     * „Ivo" → „Ive" (ne „Iva"!), „Branko" → „Branka", „Nele" → „Neleta".
     */
    private const GENITIV_IMENA = [
        // muška imena na -o (→ -a), izuzev „Ivo" (→ „Ive")
        'ivo' => 'Ive', 'marko' => 'Marka', 'branko' => 'Branka', 'darko' => 'Darka',
        'slavko' => 'Slavka', 'janko' => 'Janka', 'mirko' => 'Mirka', 'zdravko' => 'Zdravka',
        'vlatko' => 'Vlatka', 'ratko' => 'Ratka', 'rajko' => 'Rajka', 'bosko' => 'Boška',
        'veljko' => 'Veljka', 'ranko' => 'Ranka', 'stanko' => 'Stanka', 'zvonko' => 'Zvonka',
        'zivko' => 'Živka', 'vasko' => 'Vaska', 'milenko' => 'Milenka', 'nenko' => 'Nenka',
        // muška imena na -e (nepravilno: -eta ili -a)
        'nele' => 'Neleta', 'rade' => 'Radeta', 'mile' => 'Mileta', 'vule' => 'Vuleta',
        'lale' => 'Laleta', 'djole' => 'Đoleta', 'pavle' => 'Pavla', 'djordje' => 'Đorđa',
        // nepostojano „a" (suglasnik pre kraja ispada u genitivu)
        'aleksandar' => 'Aleksandra', 'petar' => 'Petra', 'aleksandra' => 'Aleksandre',
    ];

    /**
     * BEZBEDAN genitiv ličnog imena za oblik „Od autora …": menja se ime čije
     * sve reči završavaju na suglasnik (muška imena — „Branislav Nušić" →
     * „Branislava Nušića", „Fredrik Bekman" → „Fredrika Bekmana"), uz
     * „nepostojano a" za prezimena na -ac („Sremac" → „Sremca"), kao i poznata
     * imena na samoglasnik iz kurirane mape („Nele Karajlić" → „Neleta
     * Karajlića", „Ivo Andrić" → „Ive Andrića"). Reč na samoglasnik koje NEMA u
     * mapi ostavlja CELO ime u nominativu — pogrešna deklinacija je gora od
     * nominativa (odluka: imena se ne dekliniraju napamet).
     */
    private function genitivKanonskogImena(string $ime): string
    {
        $reci = preg_split('/\s+/u', trim($ime)) ?: [];

        if (empty($reci)) {
            return $ime;
        }

        $genitiv = [];

        foreach ($reci as $rec) {
            $kljuc     = TextUtils::normalize($rec);
            $poslednje = mb_strtolower(mb_substr($rec, -1));

            if (isset(self::GENITIV_IMENA[$kljuc])) {
                $genitiv[] = self::GENITIV_IMENA[$kljuc];
                continue;
            }

            // Pridevsko prezime na -ski/-cki/-ški → genitiv -skog/-ckog/-škog
            // (Crnjanski→Crnjanskog, Petrovački→Petrovačkog).
            if (in_array(mb_strtolower(mb_substr($rec, -3)), ['ski', 'cki', 'ški'], true)) {
                $genitiv[] = mb_substr($rec, 0, -1) . 'og';
                continue;
            }

            // Prva deklinacija: ime na -a → genitiv -e (Agata→Agate, Nikola→
            // Nikole, Dima→Dime, Đura→Đure). Pouzdano pravilo za srpski.
            if ($poslednje === 'a') {
                $genitiv[] = mb_substr($rec, 0, -1) . 'e';
                continue;
            }

            // Ostali samoglasnici (strano/nepromenljivo ime — „Kristi", „Hese",
            // „Sagan"…): reč se NE deklinira, ali NE obara ostatak imena (prva
            // reč može da se deklinira: „Hermana Hese" umesto „Herman Hese").
            if (in_array($poslednje, ['e', 'i', 'o', 'u'], true)) {
                $genitiv[] = $rec;
                continue;
            }

            $genitiv[] = mb_strtolower(mb_substr($rec, -2)) === 'ac'
                ? mb_substr($rec, 0, -2) . 'ca'
                : $rec . 'a';
        }

        return implode(' ', $genitiv);
    }

    /**
     * GENERIČKA PREPORUKA („preporuči mi nešto baš dobro") — FIKSNI odgovor
     * iz baze: najčitanije + nedavno dodate knjige (eval za_kod.md, set1
     * #24). Null kada u bazi još nema nijedne liste — pitanje tada ide
     * modelu sa listama u promptu (stari tok).
     */
    /**
     * „Koje su poslednje dodate / najnovije knjige" — FIKSNA lista iz sekcije
     * „Najnovije" (bibliotekari je pune), model se ne poziva.
     */
    private function odgovorNajnovije(): array
    {
        $lista = $this->data->listaNajnovijih();

        if ($lista->isEmpty()) {
            return $this->fiksniOdgovor('Trenutno nema nedavno dodatih knjiga u katalogu.');
        }

        $stavke = $lista->map(function (array $k) {
            $autor = trim((string) ($k['autor'] ?? ''));

            return "[{$k['naziv']}](/knjige/{$k['id']})" . ($autor !== '' ? " ({$autor})" : '');
        })->implode(', ');

        return $this->fiksniOdgovor("Poslednje dodate knjige u katalog su: {$stavke}.");
    }

    private function odgovorGenerickaPreporuka(): ?string
    {
        $najcitanije = $this->data->listaNajcitanijih()->take(3)->values();
        $vecUzete    = $najcitanije->pluck('id')->all();
        $najnovije   = $this->data->listaNajnovijih()
            ->reject(fn (array $k) => in_array($k['id'], $vecUzete, true))
            ->take(3)
            ->values();

        if ($najcitanije->isEmpty() && $najnovije->isEmpty()) {
            return null;
        }

        $link = fn (array $k) => "[{$k['naziv']}](/knjige/{$k['id']})"
            . ($k['autor'] !== '' ? " ({$k['autor']})" : '');

        $delovi = [];

        if ($najcitanije->isNotEmpty()) {
            $delovi[] = 'Preporučujem vam naše najčitanije knjige: '
                . $najcitanije->map($link)->implode(', ') . '.';
        }

        if ($najnovije->isNotEmpty()) {
            $delovi[] = ($najcitanije->isEmpty()
                    ? 'Preporučujem vam knjige nedavno dodate u katalog: '
                    : 'Možda će vam se svideti i nešto od nedavno dodatih u katalog: ')
                . $najnovije->map($link)->implode(', ') . '.';
        }

        $delovi[] = 'Recite mi ukoliko vas interesuje neki određeni žanr, naslov ili autor.';

        return implode(' ', $delovi);
    }

    /**
     * Kurirana podela pisaca DOMAĆI (srpski) / STRANI — za klasifikaciju „da li
     * je X domaći ili strani" i preporuke. Imena u nominativu kao u katalogu.
     * Namerno SAMO nedvosmisleni slučajevi — regionalni/sporni pisci (hrvatski/
     * bosanski) nisu ovde, pa za njih bot pošteno kaže da nema potvrđenu podelu.
     */
    private const DOMACI_PISCI = [
        'Ivo Andrić', 'Branislav Nušić', 'Stevan Sremac', 'Dobrica Ćosić',
        'Jovan Sterija Popović', 'Miloš Crnjanski', 'Danilo Kiš', 'Meša Selimović',
        'Dušan Radović', 'Matija Bećković', 'Borisav Stanković', 'Bora Stanković',
        'Desanka Maksimović', 'Jovan Dučić', 'Milorad Pavić', 'Borislav Pekić',
        'Dragoslav Mihailović', 'Dragan Velikić', 'Isidora Sekulić', 'Milovan Vitezović',
        'Ivan Ivanji', 'Miodrag Bulatović', 'Milovan Danojlić', 'Milovan Đilas',
        'Mihajlo Lalić', 'Filip David', 'Aleksandar Gatalica', 'Svetislav Basara',
        'Novica Milić', 'Antonije Isaković', 'Mileta Prodanović', 'Igor Marojević',
        'Dejan Stojiljković', 'Ana Ristović', 'Miomir Petrović', 'Mirjana Novaković',
        'Branislav Petrović', 'Dragan Lakićević', 'Milosav Tešić', 'Nele Karajlić',
        'Dušan Baranin', 'Milan Micić', 'Mirjana Pavlović',
        // Proširenje (kandidati_ucenje_7): autori iz kataloga — ex-Yu / regionalni
        // južnoslovenski pisci se vode kao domaći (kao Meša Selimović, Đilas).
        'Slobodan Selenić', 'Boško Tomašević', 'Đura Jakšić', 'Miloš Petković',
        'Vladimir Vučković', 'Vladimir Tabašević', 'Vladan Desnica', 'Vesna Dedić Milojević',
        'Slobodan Stanišić', 'Rajko Lukač', 'Niko Jovićević', 'Muharem Bazdulj',
        'Jelica Greganović', 'Ivana Hadži Popović', 'Goran Tokić', 'Žarko Komanin',
        'Đorđe Milosavljević', 'Čedo Vuković', 'Ćamil Sijarić', 'Zoran Petrović',
        'Zlata Kocić', 'Vučina Šćekić', 'Vojislav Jovanović', 'Vladimir Pištalo',
        'Vidosav Stevanović', 'Vesna Kapor', 'Veljko Petrović', 'Velja Pavlović',
        'Vanja Bulić', 'Tatjana Bijelić', 'Tanasije Mladenović', 'Nikolaj Velimirović',
        'Staša Bajac', 'Srđan Srdić', 'Srba Ignjatović', 'Slobodan Stojanović',
        'Slobodan Rakitić', 'Sergej Trifunović', 'Saša Božović', 'Risto Ratković',
        'Ranko Risojević', 'Radoje Domanović', 'Radmila Lazić', 'Radmila Gikić Petrović',
        'Petar Sarić', 'Petar Ćetković', 'Pavle Zelić', 'Nikola Apostolović',
        'Nadežda Purić Jovanović', 'Mladen Đorđević', 'Mirko Marković', 'Miodrag Majić',
        'Miloš Latinović', 'Milica Vučković', 'Marija Knežević', 'Ljubica Mrkalj',
        'Ljiljana Marković', 'Ljiljana Maletin Vojvodić', 'Kornelije Kvas', 'Jovica Aćin',
        'Jovan Ristić', 'Ivana Dimić', 'Gojko Čelebić', 'Branimir Šćepanović',
        'Borislav Padović', 'Bojan Miladinović', 'Bojan Krivokapić', 'Aleksandar Ilić',
        'Miljenko Jergović', 'Dubravka Ugrešić', 'Drago Kekanović', 'Rumena Bužarivska',
        'Milan Belegišanin', 'Marija Lazarević', 'Lara Đorđević', 'Jadranka Milenković',
        'Ivana Mihić', 'Ivan Potić', 'Hristina Todorović', 'Dušan Đurović',
        'Dragiša Kalezić', 'Dragan Hamović', 'Budimir Dubak', 'Branko Miloradović',
        'Anđelko Anušić', 'Ana Marija Grbić', 'Amra Halilović',
    ];

    private const STRANI_PISCI = [
        'Lav Nikolajević Tolstoj', 'Anton Pavlovič Čehov', 'Franc Kafka', 'Albert Kami',
        'Aleksandar Dima', 'Gistav Flober', 'Onore de Balzak', 'Oskar Vajld', 'Molijer',
        'Herman Hese', 'Maksim Gorki', 'Ernest Hemingvej', 'Ernes Hemingvej', 'Džon Grišam',
        'Džozef Heler', 'Harper Li', 'Fredrik Bekman', 'Majkl Ondači', 'Haled Hoseini',
        'Margaret Atvud', 'Agata Kristi', 'Danijel Defo', 'Ju Nesbe', 'Andre Žid',
        'Antoni Trolop', 'Arčibald Kronin', 'Grejam Grin', 'Mihael Ende', 'Manuel Puig',
        'Margerit Jursenar', 'Mišel Uelbek', 'Karel Čapek', 'Luiza Mej Olkot', 'Mark Levi',
        'Metju Perl', 'Federiko Moća', 'Ernesto Sabato', 'Eduardo Galeano', 'Elizabet Straut',
        'Džefri Judžinidis', 'Lorens Darel', 'Helen Filding',
        // Proširenje (kandidati_ucenje_7): strani autori iz kataloga.
        'Fransoaz Sagan', 'Karl Uve Knausgor', 'Čarls Dikens', 'Vilhelm Šmid',
        'Tomas Man', 'Stendal', 'Filip Žakote', 'Džim Stoval', 'Anatolij Kim',
        'Šejmus Din', 'Đanriko Karofiljo', 'Zoe Valdes', 'Venjamin Kaverin',
        'Tošikazu Kavaguči', 'Tove Ditlevsen', 'Toni Morison', 'Stiven Presfild',
        'Selma Lagerlef', 'Rodrigo Munjoz Avia', 'Rejnaldo Arenas', 'Rabindrant Tagore',
        'Peter Handke', 'Pejami Safa', 'Panos Joanidis', 'Odri Hepbern',
        'Nadija Teranova', 'Mihal Viveg', 'Maja Lunde', 'Magraget Atvud',
        'Luis Bromfild', 'Laslo Krasnahorkai', 'Kventin Tarantino', 'Kristofer Kilham',
        'Jed Rubenfeld', 'Horhe Semprun', 'Ferdinand Osendovski', 'Etela Farkašova',
        'Erik Lomaks', 'Embrouz Birs', 'Edmundo Pas Soldan', 'Džon Golsvordi',
        'Džon Don', 'Džin Sason', 'Domeniko Startone', 'Bernard Grun',
        'Boris Piljnjak', 'Alisa Palombo', 'Alexander McCall Smith', 'DŽ. M. Kuci',
        'Stanjislav Ignaci Vitkjevič', 'Nadija Teranova', 'Manuel Puig', 'Tira Teodora Trunstad',
        'Džon Džulijus Norič',
    ];

    /**
     * Klasa imenovanog pisca: 'domaci' | 'strani' | null (nije u kuriranoj
     * podeli). Poređenje po prezimenu (poslednja reč) uz proveru imena kada ga
     * upit ima — „Andrić" i „Ivo Andrić" pogađaju „Ivo Andrić".
     */
    private function klasifikujPisca(string $autorNorm): ?string
    {
        foreach (self::DOMACI_PISCI as $p) {
            if ($this->istiPisac($autorNorm, TextUtils::normalize($p))) {
                return 'domaci';
            }
        }

        foreach (self::STRANI_PISCI as $p) {
            if ($this->istiPisac($autorNorm, TextUtils::normalize($p))) {
                return 'strani';
            }
        }

        return null;
    }

    /** Poklapanje imena pisca: pun pogodak ili isto prezime (+ ime kada oba puna). */
    private function istiPisac(string $a, string $p): bool
    {
        if ($a === $p) {
            return true;
        }

        $at = array_values(array_filter(explode(' ', trim($a)), fn ($t) => $t !== ''));
        $pt = array_values(array_filter(explode(' ', trim($p)), fn ($t) => $t !== ''));

        if (empty($at) || empty($pt) || end($at) !== end($pt)) {
            return false;
        }

        return (count($at) >= 2 && count($pt) >= 2) ? $at[0] === $pt[0] : true;
    }

    /**
     * Do tri dela pisaca iz date liste koji POSTOJE u katalogu (po jedna knjiga).
     *
     * @param  array<int, string>  $imena
     */
    private function preporukaIzListe(array $imena): \Illuminate\Support\Collection
    {
        $izbor = collect();

        foreach ($imena as $pisac) {
            $knjige = $this->katalog->nadjiAutoraStrogo(TextUtils::normalize($pisac));

            if ($knjige->isEmpty()) {
                continue;
            }

            $izbor = $izbor->concat($this->data->hidriraj([$knjige->first() => 1.0]));

            if ($izbor->count() >= 3) {
                break;
            }
        }

        return $izbor;
    }

    /**
     * FIKSNA preporuka dela domaćih pisaca („preporuči mi nešto od domaćih
     * pisaca", „seminarski iz srpske književnosti") — SAMO pisci koji postoje u
     * katalogu. Null kada u fondu nema nijednog — pitanje ide normalnim tokom.
     */
    private function odgovorDomaciPisci(string $norm): ?string
    {
        $izbor = $this->preporukaIzListe(self::DOMACI_PISCI);

        if ($izbor->isEmpty()) {
            return null;
        }

        $uvod = preg_match('/seminarsk/u', $norm)
            ? 'Za seminarski rad iz srpske književnosti preporučio bih vam dela naših domaćih pisaca: '
            : 'Preporučujem vam dela naših domaćih pisaca: ';

        return $uvod . $this->listaLinkova($izbor)
            . '. Recite mi ukoliko vas interesuje neki određeni žanr, naslov ili pisac.';
    }

    /**
     * FIKSNA preporuka dela stranih pisaca („preporuči mi nešto od stranih
     * autora") — SAMO pisci iz kurirane liste koji postoje u katalogu. Null
     * kada u fondu nema nijednog — pitanje ide normalnim tokom (Faza 4).
     */
    private function odgovorStraniPisci(): ?string
    {
        $izbor = $this->preporukaIzListe(self::STRANI_PISCI);

        if ($izbor->isEmpty()) {
            return null;
        }

        return 'Preporučujem vam dela stranih pisaca: ' . $this->listaLinkova($izbor)
            . '. Recite mi ukoliko vas interesuje neki određeni žanr, naslov ili pisac.';
    }

    /**
     * „Koji DOMAĆI/STRANI pisac ima najviše knjiga" (Potez 2) — autorska
     * statistika po broju naslova, filtrirana na kuriranu podelu (domaći/strani).
     * Generalizuje na ceo katalog: rangira SVE autore tog porekla, ne primere.
     */
    private function odgovorAutorNajvisePodela(string $klasa): array
    {
        $autori = $this->data->autoriPoBrojuNaslova()
            ->filter(fn ($a) => $this->klasifikujPisca(TextUtils::normalize($a->autor)) === $klasa)
            ->values();

        $rec = $klasa === 'domaci' ? 'domaćih' : 'stranih';

        if ($autori->isEmpty()) {
            return $this->fiksniOdgovor(
                "U katalogu trenutno nemam dovoljno podataka o {$rec} piscima da bih odredio ko ima najviše knjiga."
            );
        }

        $prvi   = $autori->first();
        $poruka = "Od {$rec} pisaca najviše knjiga u katalogu ima {$prvi->autor} — "
            . $this->brojKnjigaTekst((int) $prvi->broj) . '.';

        $sledeci = $autori->slice(1, 2)
            ->filter(fn ($a) => (int) $a->broj > 1)
            ->map(fn ($a) => "{$a->autor} ({$this->brojKnjigaTekst((int) $a->broj)})")
            ->implode(', ');

        return $this->fiksniOdgovor($sledeci === '' ? $poruka : "{$poruka} Slede: {$sledeci}.");
    }

    /**
     * „Koji DOMAĆI/STRANI autori imaju više od jedne / najviše / samo jednu
     * knjigu" (set17 p6/p7) — autorska statistika filtrirana po poreklu pisca.
     */
    private function odgovorAutorStatistikaPodela(string $vrsta, string $klasa): array
    {
        $autori = $this->data->autoriPoBrojuNaslova()
            ->filter(fn ($a) => $this->klasifikujPisca(TextUtils::normalize((string) $a->autor)) === $klasa)
            ->values();

        $rec = $klasa === 'domaci' ? 'domaćih' : 'stranih';

        if ($vrsta === 'najvise') {
            if ($autori->isEmpty()) {
                return $this->fiksniOdgovor("U katalogu trenutno nemam {$rec} pisaca sa upisanim naslovima.");
            }
            $prvi = $autori->first();

            return $this->fiksniOdgovor("Od {$rec} pisaca najviše knjiga ima {$prvi->autor} — "
                . $this->brojKnjigaTekst((int) $prvi->broj) . '.');
        }

        $filtrirani = $vrsta === 'samo_jedna'
            ? $autori->filter(fn ($a) => (int) $a->broj === 1)->values()
            : $autori->filter(fn ($a) => (int) $a->broj > 1)->values();

        if ($filtrirani->isEmpty()) {
            return $this->fiksniOdgovor($vrsta === 'samo_jedna'
                ? "Trenutno nemam {$rec} pisaca sa tačno jednom knjigom u katalogu."
                : "Trenutno nemam {$rec} pisaca sa više od jedne knjige u katalogu.");
        }

        $uvod = $vrsta === 'samo_jedna'
            ? "Tačno jednu knjigu, od {$rec} pisaca, imaju"
            : "Više od jedne knjige, od {$rec} pisaca, imaju";

        $lista = $filtrirani->take(30)
            ->map(fn ($a) => "{$a->autor} ({$this->brojKnjigaTekst((int) $a->broj)})")
            ->implode(', ');
        $rep = $filtrirani->count() > 30 ? ' — i drugi.' : '.';

        return $this->fiksniOdgovor("{$uvod}: {$lista}{$rep}");
    }

    /**
     * „Najnovija / najstarija knjiga DOMAĆEG/STRANOG autora" (set16 p24, set17
     * p16/p24) — ekstrem po godini izdanja filtriran po poreklu pisca.
     */
    private function odgovorEkstremPodela(bool $najstarija, string $klasa): array
    {
        $knjiga = $this->data->knjigeSaGodinom($najstarija)
            ->first(fn (Knjiga $k) => $this->klasifikujPisca(TextUtils::normalize((string) $k->autor)) === $klasa);

        $rec = $klasa === 'domaci' ? 'domaćeg' : 'stranog';

        if ($knjiga === null) {
            return $this->fiksniOdgovor(
                "Trenutno nemam knjigu {$rec} pisca sa upisanom godinom izdanja, pa ne mogu da odredim "
                . ($najstarija ? 'najstariju.' : 'najnoviju.')
            );
        }

        $koja = $najstarija ? 'najstarija' : 'najnovija';

        return $this->fiksniOdgovor("Po godini izdanja, {$koja} knjiga {$rec} pisca je "
            . $this->linkKnjige($knjiga) . " iz {$knjiga->godina_izdavanja}. godine.");
    }

    /**
     * „Najčitanija knjiga domaćeg/stranog autora" (Potez 2, set13 p13) — knjiga
     * sa najviše zaduženja čiji je pisac tog porekla (kurirana podela).
     */
    private function odgovorNajcitanijaKnjigaPodela(string $klasa): array
    {
        $knjiga = $this->data->knjigeSaZaduzenjima()
            ->first(fn (Knjiga $k) => $this->klasifikujPisca(TextUtils::normalize((string) $k->autor)) === $klasa);

        $rec = $klasa === 'domaci' ? 'domaćeg' : 'stranog';

        if ($knjiga === null) {
            return $this->fiksniOdgovor(
                "Trenutno nemam zabeleženo zaduživanje neke knjige {$rec} pisca, pa ne mogu da odredim najčitaniju."
            );
        }

        return $this->fiksniOdgovor("Najčitanija knjiga {$rec} pisca je " . $this->linkKnjige($knjiga) . '.');
    }

    /**
     * „Koje tri knjige najčešće preporučujete" (Potez 2, set13 p25) — prve tri
     * NAJČITANIJE iz baze, bez dodatka „nedavno dodate" (koji je bunio odgovor).
     */
    private function odgovorNajcescePreporucene(): array
    {
        $lista = $this->data->listaNajcitanijih()->take(3)->values();

        if ($lista->isEmpty()) {
            return $this->fiksniOdgovor(
                'Još uvek nemam dovoljno podataka o čitanosti da bih izdvojio knjige koje najčešće preporučujem.'
            );
        }

        $stavke = $lista->map(fn (array $k) => "[{$k['naziv']}](/knjige/{$k['id']})"
            . (trim((string) ($k['autor'] ?? '')) !== '' ? " ({$k['autor']})" : ''))->implode(', ');

        return $this->fiksniOdgovor("Najčešće preporučujem naše najčitanije knjige: {$stavke}.");
    }

    /**
     * „Koji je najčitaniji žanr posle romana / drugi najčitaniji žanr" (Potez 2,
     * set13 p3) — žanr na 2. mestu po ukupnim zaduženjima.
     */
    private function odgovorDrugiNajcitanijiZanr(): array
    {
        $rang = $this->data->zaduzenjaPoZanru()
            ->filter(fn ($g) => (int) $g->zaduzenja > 0)
            ->sortByDesc('zaduzenja')
            ->values();

        if ($rang->count() < 2) {
            return $this->fiksniOdgovor(
                'Nemam dovoljno podataka o čitanosti žanrova da bih odredio sledeći po redu.'
            );
        }

        return $this->fiksniOdgovor("Posle najčitanijeg, sledeći najčitaniji žanr je {$rang[1]->ime}.");
    }

    /**
     * „Preporuči mi neki dobar DOMAĆI/STRANI [žanr]" (Potez 2, set13 p16) —
     * knjige datog žanra od pisaca tog porekla (kurirana podela). Nema takvih →
     * pošteno kaže. Generalizuje na ceo katalog.
     */
    private function odgovorZanrPodelaPreporuka(\App\Models\Zanr $zanr, string $klasa): array
    {
        $knjige = $this->data->knjigeZanra($zanr)
            ->filter(fn (Knjiga $k) => $this->klasifikujPisca(TextUtils::normalize((string) $k->autor)) === $klasa)
            ->values();

        $rec = $klasa === 'domaci' ? 'domaćih' : 'stranih';

        if ($knjige->isEmpty()) {
            return $this->fiksniOdgovor(
                "U žanru {$zanr->naziv} trenutno nemam {$rec} pisaca u katalogu. "
                . 'Recite mi drugi žanr ili konkretnog autora.'
            );
        }

        $izbor = $knjige->take(3)->map(fn (Knjiga $k) => $this->linkKnjige($k))->implode(', ');

        return $this->fiksniOdgovor(
            "Iz žanra {$zanr->naziv}, od {$rec} pisaca, preporučujem vam: {$izbor}. "
            . 'Recite mi ukoliko vas interesuje neki određeni naslov ili autor.'
        );
    }

    /** „N knjiga/knjige/knjiga" — srpska množina za prikaz broja. */
    private function brojKnjigaTekst(int $n): string
    {
        $d = $n % 10;
        $dd = $n % 100;
        $rec = ($d === 1 && $dd !== 11) ? 'knjiga'
            : (($d >= 2 && $d <= 4 && !($dd >= 12 && $dd <= 14)) ? 'knjige' : 'knjiga');

        return "{$n} {$rec}";
    }

    /**
     * „Da li je X domaći ili strani pisac" (Faza 4) — klasifikacija iz kurirane
     * podele. Pisac van podele → pošteno „nemam potvrđenu podelu".
     */
    private function odgovorPodelaPisca(string $autorPrikaz): array
    {
        $klasa = $this->klasifikujPisca(TextUtils::normalize($autorPrikaz));

        return $this->fiksniOdgovor(match ($klasa) {
            'domaci' => "{$autorPrikaz} je naš, domaći (srpski) pisac.",
            'strani' => "{$autorPrikaz} je strani pisac.",
            default  => "Za pisca {$autorPrikaz} nemam potvrđenu podelu na domaćeg ili stranog. "
                . 'Mogu da vam pomognem oko njegovih knjiga u katalogu.',
        });
    }

    /**
     * FIKSNI odgovor o dostupnosti VIŠE pogođenih knjiga (autorski/žanrovski
     * spisak, nastavak „koja od tih je dostupna?") — sveže stanje primeraka
     * za svaku knjigu, iz baze.
     *
     * @param  Collection<int, array{id:int, score:float, razlog:string}>  $leksicki
     */
    private function odgovorDostupnostVise(Collection $leksicki): string
    {
        $ids = $leksicki->take((int) config('ai.rag_top_k', 5))->pluck('id')->all();

        if (count($ids) === 1) {
            return $this->strukturni->odgovorDostupnost($ids[0]);
        }

        $knjige = $this->data->hidriraj(array_fill_keys($ids, 1.0));

        [$dostupne, $zauzete] = $knjige->partition(
            fn (array $h) => (int) ($h['knjiga']->slobodni_primerci_count ?? 0) > 0
        );

        if ($zauzete->isEmpty()) {
            return 'Sve navedene knjige trenutno imaju slobodan primerak — možete da '
                . 'rezervišete jednu od njih ukoliko nemate drugu aktivnu rezervaciju.';
        }

        if ($dostupne->isEmpty()) {
            return 'Nijedna od navedenih knjiga trenutno nema slobodan primerak — '
                . 'rezervacija je moguća tek kada se neki primerak oslobodi.';
        }

        return 'Trenutno su dostupne: ' . $this->listaLinkova($dostupne->values())
            . '. Nisu dostupne: ' . $this->listaLinkova($zauzete->values())
            . ' — za njih je rezervacija moguća tek kada se primerak oslobodi.';
    }

    /**
     * Hidrirane pogotke svodi na spisak linkova za fiksne poruke:
     * „[Naziv](/knjige/ID) (Autor), …" — isti oblik kao u odgovorima modela.
     *
     * @param  Collection<int, array{knjiga: \App\Models\Knjiga, score: float}>  $hits
     */
    private function listaLinkova(Collection $hits): string
    {
        return $hits
            ->map(fn (array $h) => sprintf(
                '[%s](/knjige/%d) (%s)',
                $h['knjiga']->naziv_knjige,
                $h['knjiga']->id,
                $h['knjiga']->autor
            ))
            ->implode(', ');
    }

    /** Link jedne knjige za fiksne odgovore: „[Naziv](/knjige/ID) (Autor)". */
    private function linkKnjige(Knjiga $k): string
    {
        $link  = "[{$k->naziv_knjige}](/knjige/{$k->id})";
        $autor = trim((string) preg_replace('/\s+/u', ' ', (string) ($k->autor ?? '')));

        return $autor !== '' ? "{$link} ({$autor})" : $link;
    }

    /**
     * FIKSNI odgovor o SOPSTVENOM nalogu prijavljenog člana (bib-izmene tačka 1):
     * aktivna rezervacija (naslov + status + rok preuzimanja) i/ili aktivno
     * zaduženje (naslov + rok vraćanja). Podaci se čitaju iz baze preko
     * clanKontekst(), STROGO filtrirani po user_id iz serverske sesije
     * (auth()->id()) — nikada iz pitanja. Bez rezervacija i zaduženja vraća
     * jasnu poruku. Obraćanje je formalno („Vi"). Model se ne poziva.
     */
    private function odgovorMojNalog(): array
    {
        $ctx = $this->data->clanKontekst((int) auth()->id());
        $rez = $ctx['rezervacija'];
        $zad = $ctx['zaduzenje'];

        $delovi = [];

        if ($rez !== null) {
            // status label() je npr. „Na čekanju" / „Odobrena" — u rečenici malim.
            $status   = mb_strtolower((string) $rez['status'], 'UTF-8');
            $recenica = 'Vaša rezervacija za knjigu „' . $rez['knjiga']
                . '" trenutno je u statusu: ' . $status . '.';

            if (!empty($rez['rok_preuzimanja'])) {
                // rok_preuzimanja je formatiran kao „d.m.Y." (tačka je već tu).
                $recenica .= ' Rok za preuzimanje knjige je ' . $rez['rok_preuzimanja'];
            }

            $delovi[] = $recenica;
        }

        if ($zad !== null) {
            $recenica = 'Trenutno imate zaduženu knjigu „' . $zad['knjiga'] . '"';

            if (!empty($zad['rok_vracanja'])) {
                $recenica .= ', a rok za njeno vraćanje je ' . $zad['rok_vracanja'];
            } else {
                $recenica .= '.';
            }

            $delovi[] = $recenica;
        }

        if (empty($delovi)) {
            return $this->fiksniOdgovor('Trenutno nemate aktivnih rezervacija ni zaduženih knjiga.');
        }

        return $this->fiksniOdgovor(implode(' ', $delovi));
    }

    /**
     * Kontekst za fiksni odgovor bez poziva modelu (strani jezik / neprepoznata
     * namera / strukturni odgovor) — ista struktura kao pun kontekst, radi
     * jednostavnog pozivaoca.
     */
    private function fiksniOdgovor(string $poruka): array
    {
        return [
            'understood'    => false,
            'poruka'        => $poruka,
            'knjige'        => collect(),
            'preporuke'     => collect(),
            'preporuke_tip' => null,
            'srodni_zanr'   => null,
            'agregati'      => [],
            'najnovije'     => null,
            'najcitanije'   => null,
            'faq_primeri'   => [],
            'clan'          => null,
        ];
    }

    /**
     * Mapa knjiga koje odgovor SME da pominje kao linkove — [id => tačan
     * naslov iz baze] za sve knjige iz konteksta tekućeg pitanja (pogoci,
     * preporuke, liste, primeri). Naslov služi da se link u odgovoru
     * deterministički prepiše KANONSKIM naslovom (model ume da ga prepriča).
     *
     * @return array<int, string>
     */
    public function dozvoljeneKnjige(array $ctx): array
    {
        $mapa = [];

        foreach ([$ctx['knjige'] ?? collect(), $ctx['preporuke'] ?? collect()] as $hits) {
            foreach ($hits as $hit) {
                $mapa[(int) $hit['knjiga']->id] = (string) $hit['knjiga']->naziv_knjige;
            }
        }

        foreach (['najnovije', 'najcitanije'] as $lista) {
            if ($ctx[$lista] instanceof Collection) {
                foreach ($ctx[$lista] as $k) {
                    $mapa[(int) $k['id']] = (string) $k['naziv'];
                }
            }
        }

        return $mapa;
    }

    /**
     * Deterministička validacija gotovog odgovora (bez drugog LLM prolaza):
     * nevalidni linkovi (ka knjigama van konteksta) svode se na običan tekst,
     * validni dobijaju kanonski naslov iz baze, a ijekavski/hrvatski oblici
     * se svode na standardni srpski (ekavicu). Ostatak jezičke kontrole
     * (engleski, strani skriptovi) pokriva OllamaClient (retry).
     */
    public function validirajOdgovor(string $reply, array $ctx): string
    {
        $ocisceno = TextUtils::ocistiLinkove($reply, $this->dozvoljeneKnjige($ctx));

        // GROUNDING GATE (Potez 1): posle čišćenja linkova (nevalidni su svedeni
        // na goli tekst), autor-citat u zagradi bez grounded linka = knjiga koju
        // model IMENUJE a nije iz konteksta → IZMIŠLJENO. Ceo odgovor se odbacuje
        // i traži se konkretizacija; nikad ne serviramo izmišljene naslove/autore
        // (kandidati_ucenje_6 set13 p1/p10/p18).
        if (TextUtils::imaNegroundovanAutorCitat($ocisceno)) {
            Log::warning('[AiChatService] odgovor odbijen — negroundovana knjiga (Potez 1)', [
                'pre' => mb_substr($reply, 0, 200),
            ]);

            return TextUtils::NIJE_POUZDANO;
        }

        $ocisceno = TextUtils::ekavizirajTekst($ocisceno);

        // POST-GENERATION GUARD (Faza 2): strana reč koju ekavizacija nije
        // prevela (hrvatski/engleski) ILI nesuvisao/degenerisan izlaz → odgovor
        // se NE prikazuje, traži se ponavljanje pitanja. Bolje ćutati nego
        // servirati hrvatske reči ili halucinaciju.
        $strana = TextUtils::sadrziStranuRec($ocisceno);
        if ($strana !== null || TextUtils::jeNesuvisaoOdgovor($ocisceno)) {
            Log::warning('[AiChatService] odgovor blokiran guard-om (Faza 2)', [
                'razlog' => $strana !== null ? "strana rec: {$strana}" : 'nesuvisao',
                'pre'    => mb_substr($reply, 0, 200),
            ]);

            return TextUtils::PONOVITE_PITANJE;
        }

        // JEZIČKI GUARD (opciono, iza flaga ai.jezicki_guard): izlaz sa previše
        // ne-srpskih reči (hrvatski/engleski/gibberiš) po Hunspell rečniku iz
        // mikroservisa → ne prikazuj, traži ponavljanje. Proverava FORMU, ne
        // istinu. Fail-open: servis/rečnik nedostupan → propušta (jezikCist).
        if (config('ai.jezicki_guard') && !$this->jezikCist($ocisceno)) {
            Log::warning('[AiChatService] jezički guard odbio odgovor', [
                'pre' => mb_substr($reply, 0, 200),
            ]);

            return TextUtils::PONOVITE_PITANJE;
        }

        if ($ocisceno !== $reply) {
            Log::warning('[AiChatService] odgovor korigovan (linkovi/ekavica)', [
                'pre' => mb_substr($reply, 0, 200),
            ]);
        }

        return $ocisceno;
    }

    /**
     * Jezička provera odgovora preko mikroservisa (/proveri-tekst) — true ako je
     * čist srpski leksikon ili guard nije aktivan. FAIL-OPEN: servis/rečnik
     * nedostupan → true (guard nikad ne obara bota kad ne može da proveri).
     */
    private function jezikCist(string $tekst): bool
    {
        try {
            return (bool) ($this->ragClient->proveriTekst($tekst)['ok'] ?? true);
        } catch (\Throwable $e) {
            Log::info('[AiChatService] jezički guard preskočen (servis nedostupan)', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * IZLAZNI GUARD za STREAMING put — isti guardovi kao validirajOdgovor, ali
     * nad CELIM baferovanim odgovorom kome su linkovi/ekavica već sređeni u
     * streamReplyValidiran. Loš izlaz (izmišljena knjiga, hrvatski/strani,
     * nesuvisao, ne-srpski leksikon) → fiksni fallback umesto prikaza korisniku.
     * Time živi widget dobija istu zaštitu kao eval (ranije je streaming
     * emitovao tokene uživo BEZ ijedne provere).
     */
    public function validirajStreamOdgovor(string $reply): string
    {
        if (TextUtils::imaNegroundovanAutorCitat($reply)) {
            Log::warning('[AiChatService] stream odbijen — negroundovana knjiga', [
                'pre' => mb_substr($reply, 0, 200),
            ]);

            return TextUtils::NIJE_POUZDANO;
        }

        $strana = TextUtils::sadrziStranuRec($reply);
        if ($strana !== null || TextUtils::jeNesuvisaoOdgovor($reply)) {
            Log::warning('[AiChatService] stream odbijen — strana reč / nesuvisao', [
                'razlog' => $strana !== null ? "strana rec: {$strana}" : 'nesuvisao',
                'pre'    => mb_substr($reply, 0, 200),
            ]);

            return TextUtils::PONOVITE_PITANJE;
        }

        if (config('ai.jezicki_guard') && !$this->jezikCist($reply)) {
            Log::warning('[AiChatService] stream odbijen — jezički guard');

            return TextUtils::PONOVITE_PITANJE;
        }

        return $reply;
    }

    /**
     * Gradi niz poruka za Ollama /api/chat endpoint.
     *
     * Format: [system, ...history, user]. System prompt je JEDAN
     * (SystemPromptService::build) i dobija ceo kontekst iz retrieveContext()
     * (pogoci, preporuke, agregati, najnovije/najčitanije, stanje naloga).
     *
     * History filter blokira 'system' role kroz history (injection zaštita).
     *
     * Poruke se modelu šalju TRANSLITEROVANE u latinicu: deterministički sloj
     * (langdetect) je već potvrdio da je upit srpski, a ćirilica u user poruci
     * zbunjuje model (ume da je proglasi stranim jezikom uprkos kontekstu).
     *
     * @param  array<int, array{role:string, content:string}>  $history
     * @param  array  $ctx  rezultat retrieveContext()
     */
    public function buildMessages(string $userMessage, array $history, array $ctx): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->promptService->build($ctx)],
        ];

        foreach ($history as $msg) {
            if (!in_array($msg['role'], ['user', 'assistant'], true)) {
                continue;
            }

            $messages[] = [
                'role'    => $msg['role'],
                'content' => TextUtils::cyrillicToLatin($msg['content']),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => TextUtils::cyrillicToLatin($userMessage)];

        return $messages;
    }

    /**
     * Određuje da li je tekući request od gosta ili od ulogovanog člana
     * (samo za perzistenciju razgovora — prompt je isti za oba).
     * Uloga se čita iz baze preko auth guard-a, ne iz sesije.
     */
    public function resolveGuestMode(): bool
    {
        $user = auth()->user();

        return !$user || !$user->isClan();
    }

    /**
     * Da li tekući korisnik sme da vidi ime člana iz evidencije zaduženja.
     *
     * Kriterijum je namerno ISTI kao u BibliotekarMiddleware-u — asistent ne
     * proširuje ovlašćenja koja korisnik već nema u ostatku aplikacije.
     * Uloga se čita iz baze preko auth guard-a, ne iz sesije, pa oduzimanje
     * ovlašćenja važi od narednog zahteva.
     *
     * Napomena: resolveGuestMode() se ovde NE koristi, jer ono bibliotekara i
     * administratora svrstava uz goste (razlikuje samo članski režim zbog
     * perzistencije razgovora), pa bi kao provera ovlašćenja bilo pogrešno.
     */
    private function smeDaVidiEvidencijuZaduzenja(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdminOrBibliotekar();
    }

    /**
     * Vraća true ako poruka sadrži poznati prompt injection pattern.
     */
    public function containsInjection(string $message): bool
    {
        return TextUtils::containsInjection($message);
    }

    /**
     * Sinhroni odgovor od Ollame — kompletan tekst odjednom.
     *
     * @throws RuntimeException  Kada Ollama servis nije dostupan.
     */
    public function getReply(array $messages): string
    {
        return $this->ollama->chat($messages);
    }

    /**
     * Streaming odgovor od Ollame — generator koji yield-uje tokene.
     *
     * @return \Generator
     * @throws RuntimeException  Kada Ollama servis nije dostupan.
     */
    public function streamReply(array $messages): \Generator
    {
        return $this->ollama->streamChat($messages);
    }

    /**
     * Streaming sa determinističkom validacijom U LETU, pre slanja korisniku:
     *  1. TextUtils::filtrirajLinkoveStream — nevalidan link (knjiga van
     *     konteksta pitanja) → goli tekst; validan → kanonski naslov iz baze,
     *  2. TextUtils::ekavizirajStream — ijekavski/hrvatski oblici → ekavica
     *     (linkovi prolaze netaknuti).
     *
     * @param  array<int, string>  $dozvoljene  iz dozvoljeneKnjige($ctx)
     * @return \Generator
     * @throws RuntimeException
     */
    public function streamReplyValidiran(array $messages, array $dozvoljene): \Generator
    {
        return TextUtils::ekavizirajStream(
            TextUtils::filtrirajLinkoveStream($this->streamReply($messages), $dozvoljene)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PERZISTENCIJA RAZGOVORA — samo ulogovani članovi
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Učitava postojeću konverzaciju (ako pripada korisniku) ili kreira novu.
     * Klijent koji pošalje tuđi/nepostojeći conversation_id jednostavno
     * dobija novu konverzaciju — nema curenja podataka između korisnika.
     */
    public function resolveConversation(?int $conversationId, int $userId): AiConversation
    {
        if ($conversationId) {
            $conversation = AiConversation::where('id', $conversationId)
                ->where('user_id', $userId)
                ->first();

            if ($conversation) {
                return $conversation;
            }
        }

        return AiConversation::create(['user_id' => $userId]);
    }

    /**
     * Učitava poslednjih $limit poruka iz baze, hronološkim redom, u formatu
     * koji buildMessages() prihvata kao $history.
     */
    public function historyFromDb(AiConversation $conversation, int $limit): array
    {
        return $conversation->poruke()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn (AiMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    /**
     * Upisuje jednu poruku u konverzaciju. Naslov konverzacije se auto-generiše
     * iz prve user poruke (skraćeno na 50 karaktera) ako ga još nema.
     */
    public function recordMessage(AiConversation $conversation, string $role, string $content): void
    {
        $conversation->poruke()->create([
            'role'    => $role,
            'content' => $content,
        ]);

        if ($role === 'user' && $conversation->naslov === null) {
            $conversation->update(['naslov' => mb_substr($content, 0, 50)]);
        }
    }
}