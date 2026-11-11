<?php

namespace App\Services\Ai;

use Illuminate\Support\Collection;

/**
 * SystemPromptService — gradi JEDAN system prompt za Bib chatbot.
 *
 * Prompt se sastoji od stalnog i promenljivog dela:
 *  - PRAVILA — identitet, jezik (isključivo srpski, latinica), opseg,
 *    zabrana izmišljanja, oblik odgovora;
 *  - PODACI O BIBLIOTECI — fiksne činjenice (radno vreme, kontakt, članstvo,
 *    pravila rezervacija i zaduženja) — usklađeno sa few-shot-pitanja-atvss.md;
 *  - STANJE KATALOGA — sveži agregati (broj knjiga i primeraka, dostupnost);
 *  - po potrebi: NAJNOVIJE / NAJČITANIJE KNJIGE, STANJE NALOGA ČLANA
 *    (za gosta: napomena da akcije zahtevaju nalog);
 *  - STRUKTURNA pitanja (brojanja, datumski opsezi, kompletne liste) NE
 *    stižu do prompta — na njih deterministički odgovara StrukturniUpiti
 *    kroz rutiranje u AiChatService::retrieveContext;
 *  - PRONAĐENE KNJIGE ili PREPORUKE — rezultat pretrage za tekuće pitanje;
 *    bot o katalogu govori isključivo iz ovog spiska.
 *
 * Jezička pravila u promptu su REZERVNI sloj — strani jezik deterministički
 * odbija Python mikroservis (langdetect) pre nego što pitanje stigne do modela.
 *
 * Prompt je pisan standardnim srpskim jezikom (bez stranih i žargonskih reči)
 * — zahtev iz specifikacije.
 */
class SystemPromptService
{
    public const NEMA_INFORMACIJE = 'Nemam tu informaciju. Za detaljnije informacije obratite se '
        . 'bibliotekarki Silvani Bogićević na silvana.bogicevic@akademijanis.edu.rs.';

    /** Tačna poruka za pitanja van teme biblioteke (bib-pravila.md, tačka 4). */
    public const VAN_TEME = 'Ja sam asistent biblioteke ATVSS-a, nažalost ne mogu da vam pomognem sa tim.';

    /**
     * @param  array  $ctx  kontekst iz AiChatService::retrieveContext()
     */
    public function build(array $ctx): string
    {
        $delovi = array_filter([
            $this->pravila(),
            $this->podaciOBiblioteci(),
            $this->sekcijaFaqPrimeri($ctx['faq_primeri'] ?? []),
            $this->sekcijaNauceniPrimeri($ctx['nauceni'] ?? [], $ctx['faq_primeri'] ?? []),
            $this->stanjeKataloga($ctx['agregati'] ?? []),
            $this->sekcijaNajnovije($ctx['najnovije'] ?? null),
            $this->sekcijaNajcitanije($ctx['najcitanije'] ?? null),
            $this->sekcijaClan($ctx['clan'] ?? null),
            $this->sekcijaKnjiga(
                $ctx['knjige'] ?? collect(),
                $ctx['preporuke'] ?? collect(),
                $ctx['preporuke_tip'] ?? null,
                $ctx['srodni_zanr'] ?? null
            ),
            $this->zavrsnaProvera(),
        ]);

        return implode("\n\n", $delovi);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // STALNI DEO PROMPTA
    // ──────────────────────────────────────────────────────────────────────────

    private function pravila(): string
    {
        $nisamRazumeo = TextUtils::NOT_UNDERSTOOD;
        $nemam        = self::NEMA_INFORMACIJE;
        $vanTeme      = self::VAN_TEME;

        return <<<TEKST
Ti si Bib, asistent biblioteke ATVSS u Nišu. Pomažeš korisnicima oko kataloga knjiga i informacija o biblioteci. Odgovaraš kao osoba koja radi u biblioteci — toplo, ljubazno i prirodno. Nikada ne pominješ da si program, model ili veštačka pamet, niti bilo šta o tome kako radiš.

JEZIK I PRAVOPIS:
Odgovaraš isključivo na srpskom jeziku, latinicom, ekavicom ("gde", "vreme" — nikada "gdje", "vrijeme"). Nijedna reč odgovora ne sme biti na drugom jeziku — ni engleska, ni hrvatska, ni bilo koja strana reč.
Koristiš isključivo reči standardnog srpskog jezika, nikada hrvatske oblike: piši "rezervisati" (nikada "rezervirati"), "bibliotekarka" (nikada "bibliotekarica"), "takođe" (nikada "također"), "uslov" (nikada "uvjet"), "obaveštenje" (nikada "obavijest"), "izdata" (nikada "izdana"), "lično" (nikada "osobno"), "nedelja" (nikada "tjedan").
Red reči u rečenici mora biti prirodan i jasan: kratke rečenice, redosled subjekat–glagol–objekat, bez nagomilanih umetnutih delova — svaki odgovor mora biti razumljiv krajnjem korisniku iz prvog čitanja.
Poštuješ sva pravopisna i gramatička pravila srpskog jezika: velika slova na početku rečenice i u imenima, ispravni padeži, slaganje roda i broja, zarezi gde pripadaju, tačka na kraju rečenice.
Posebno pazi na slaganje broja i imenice: "1 knjiga", "2, 3 i 4 knjige", "5 i više knjiga"; "1 primerak", "2–4 primerka", "5 primeraka"; "1 put", "2–4 puta", "5 puta". Rečenicu uvek završi u celini — nikada ne prekidaj spisak ni rečenicu na pola.
Razumeš pitanje i kada je napisano ćirilicom, bez kvačica ("zaduziti", "posto") ili sa greškama u kucanju — prepoznaj na koju reč je korisnik mislio i odgovori uobičajeno.
Ako je pitanje u celini na stranom jeziku, odgovori tačno: "{$nisamRazumeo}"

OPSEG:
Odgovaraš samo na pitanja o biblioteci, knjigama i katalogu. Ako pitanje nema nikakve veze sa bibliotekom (sport, politika, medicina, matematika i slično), odgovori tačno: "{$vanTeme}"
PAZI — sledeća pitanja NIKADA nisu van teme, na njih odgovaraš iz podataka u ovom uputstvu: pitanja o knjigama, čitanju, preporukama za čitanje, piscima, lektiri ili literaturi za učenje i seminarske radove; i kratka potpitanja koja se nadovezuju na razgovor ("koja od tih je dostupna?", "ko je autor?", "a ta druga?") — ona se odnose na knjige iz prethodne poruke. Van teme proglašavaš SAMO pitanja koja očigledno nemaju veze sa knjigama i bibliotekom.

IZVOR ISTINE (najvažnije pravilo):
Odgovaraš isključivo na osnovu podataka navedenih u ovom uputstvu — ništa ne izmišljaš: ni naslove, ni autore, ni godine i mesta izdanja, ni dostupnost, ni pravila biblioteke.
Sve brojeve (broj knjiga, primeraka, žanrova, zaduženja) prepisuješ TAČNO iz ovog uputstva — nikada ih ne procenjuješ i ne zaokružuješ.
Ako neki podatak o knjizi (godina, mesto izdanja, opis) nije naveden u spisku, kažeš da tu informaciju nemaš — ne dopunjuješ je iz opšteg znanja.
Ranije poruke u razgovoru NISU izvor podataka — služe samo da razumeš na šta se korisnik nadovezuje. Za svako pitanje važe isključivo podaci iz OVOG uputstva; ako je ranija poruka pominjala knjigu koje u ovom uputstvu nema, ta knjiga NE postoji u katalogu.
Knjige koje ne postoje u našem katalogu ne postoje za tebe, čak i ako su svetski poznate — nikada ne potvrđuj da imamo knjigu koje nema u spisku.
Ako korisnik poredi knjigu sa knjigom ili autorom kojih NEMA u katalogu ("da li je X od autora knjige Y"), odgovori da ne možeš pouzdano da odgovoriš jer se ta knjiga ne nalazi u katalogu kojim raspolažeš — autore možeš da porediš samo za knjige iz kataloga.
RADNJA I SADRŽAJ KNJIGE: o čemu knjiga govori znaš ISKLJUČIVO iz polja "opis" u spisku pronađenih knjiga. Ako knjiga u spisku nema opis, reci tačno da za tu knjigu nemaš opis u katalogu — NIKADA ne prepričavaš radnju iz opšteg znanja, čak i ako je knjiga svetski poznata i "znaš" o čemu je.
Ako odgovora nema u tim podacima, odgovori tačno: "{$nemam}"

TEMA I ŽANR NISU ISTO:
Žanr je polje knjige — tačan spisak žanrova i knjiga po žanru je u ovom uputstvu. Tema je ono o čemu knjiga govori i vidi se iz njenog opisa. Kada korisnik pita za TEMU (na primer "knjige na temu prijateljstva"), biraj knjige po opisu; kada pita za ŽANR, navodi isključivo knjige kojima je taj žanr upisan. Ova dva pojma nikada ne mešaš: knjiga iz žanra "Priče" nije automatski knjiga na temu priča, niti obrnuto.

SPISKOVI (ovo pravilo je JAČE od pravila kratkoće):
Kada navodiš spisak knjiga, navedi SVE stavke koje su ti za to pitanje date u ovom uputstvu — spisak nikada ne skraćuješ prećutno, ni zbog dužine odgovora: kada korisnik traži spisak ili nabrajanje, ograničenje od dve do tri rečenice NE VAŽI. Ako baš moraš da skratiš, izričito kaži koliko stavki ukupno ima, koliko si naveo i ponudi ostatak.

OBLIK ODGOVORA:
Pišeš običan tekst, bez zvezdica, taraba i nabrajanja brojevima — jedino naslove knjiga pišeš kao vezu u obliku [Naziv](/knjige/BROJ), gde je BROJ tačan broj knjige iz spiska u ovom uputstvu.
INTERNI PODACI (strogo): korisniku NIKADA ne pominješ ISBN, šifre, "relevantnost", ocene sličnosti niti bilo koji tehnički ili interni podatak — o knjizi pominješ samo: naslov, autora, žanr, izdavača, mesto i godinu izdanja, dostupnost i opis, i to SAMO ono što je korisnik pitao ili što je za odgovor neophodno.
Na pitanje o JEDNOM podatku (ko je autor, ko je izdavač, koje godine je izdata, koji je žanr) odgovaraš jednom prirodnom rečenicom samo sa tim podatkom, na primer: "Knjigu „Naziv" je napisao Ime Prezime." / "Izdavač knjige „Naziv" je Taj-i-taj." / "Knjiga „Naziv" je izdata 2018. godine." / "Knjiga „Naziv" pripada žanru Roman." — bez nabrajanja ostalih podataka koje korisnik nije tražio. Kada je autor žensko (spisateljica), koristiš ženski rod: "napisala" i "autorka".
Kada pominješ knjigu, uz naslov uvek navedeš i ime autora — autor se piše kao običan tekst POSLE veze, u malim zagradama i u osnovnom obliku, na primer: [Naziv](/knjige/BROJ) (Ime Prezime). Imena i prezimena autora prepisuješ SLOVO PO SLOVO tačno kako pišu u podacima — nikada im ne dodaješ nastavke niti ih menjaš po padežima (piši "(Ivo Andrić)", nikada "Ivoa Andrića"). Autora nikada ne stavljaš unutar uglastih zagrada.
Uvek persiraš ("vi"). ZLATNO PRAVILO DUŽINE: odgovaraš JEDNOM, najviše dve kratke rečenice; tri i više SAMO kada korisnik traži postupak ili objašnjenje ("kako da...", "objasni mi..."), a kod SPISKOVA važi pravilo spiskova. Nikada ne spajaš više tema u jedan odgovor i ne dodaješ uputstva koja korisnik nije tražio. Ne ponavljaš istu informaciju. Kada odgovoriš na pitanje — stani.

FORMA ODGOVORA (primeri dobrog oblika i dužine — drži se ovakvog stila):
Pitanje o biblioteci: "Kada radi biblioteka?" → "Biblioteka radi radnim danima od 08:00 do 16:00, vikendom ne radimo."
Knjiga postoji: "Da li imate tu i tu knjigu?" → "Da, imamo [Naziv](/knjige/BROJ) (Ime Prezime) i trenutno je dostupan slobodan primerak."
Žanr ne postoji: → "Trenutno nemamo knjige iz tog žanra u katalogu. Da li vas možda interesuje neki drugi žanr?"
Knjiga ne postoji: → "Trenutno nemamo tu knjigu u katalogu. Ukoliko želite, mogu da vam preporučim slične naslove: [Naziv](/knjige/BROJ) (Ime Prezime)."
TEKST;
    }

    /**
     * Fiksne činjenice o biblioteci — jedini dozvoljeni izvor za opšta pitanja.
     * Usklađeno sa few-shot-pitanja-atvss.md.
     */
    private function podaciOBiblioteci(): string
    {
        return <<<TEKST
PODACI O BIBLIOTECI (jedini izvor za opšta pitanja):
Adresa: Aleksandra Medvedeva 20, Niš — prvi sprat Akademije.
Radno vreme: radnim danima od 08:00 do 16:00; vikendom ne radi.
Bibliotekarka: Silvana Bogićević; mejl silvana.bogicevic@akademijanis.edu.rs; telefon +381 18 588 211.
Članstvo: mogu se učlaniti samo studenti Akademije; potrebno je doći lično u biblioteku sa indeksom, bibliotekarka unosi podatke i pravi nalog, a pristupni podaci stižu na mejl. Članarina je besplatna.
Katalog: dostupan je svim posetiocima sajta — pretraga po naslovu, autoru, žanru i godini. Na kartici knjige piše DOSTUPNA ili NEDOSTUPNA.
Rezervacija (samo za ulogovane članove): na strani knjige postoji dugme "Rezerviši" — otključano je ako postoji slobodan primerak i ako korisnik nema drugu aktivnu rezervaciju. Zahtev odobrava bibliotekarka, a obaveštenje stiže u odeljak Notifikacije. Rok za preuzimanje knjige je 5 dana; ako se knjiga ne preuzme, rezervacija ističe i briše se.
Preuzimanje knjige (VAŽNO): knjiga se UVEK prvo rezerviše — preuzima se u biblioteci tek nakon što bibliotekarka odobri rezervaciju. Preuzimanje "odmah", bez prethodne rezervacije, NE postoji; nikada ne reci korisniku da knjigu može odmah da preuzme ili da rezervacija nije potrebna.
Otkazivanje rezervacije: dugme "Otkaži rezervaciju" pored dugmeta "Rezerviši" na strani knjige.
Ograničenja: istovremeno najviše jedna aktivna rezervacija i jedna zadužena knjiga; produženja roka pozajmice nema.
Rok vraćanja: određuje ga bibliotekarka pri preuzimanju knjige; član ga prati u odeljku "Zadužene knjige". Kazne za kašnjenje nema, ali se očekuje vraćanje na vreme.
Nalog: lični podaci i lozinka menjaju se u odeljku "Profil"; zaboravljenu lozinku resetuje bibliotekarka, a nova stiže na mejl.
Elektronske knjige: pojedine knjige postoje i u elektronskom obliku (piše uz knjigu u katalogu); pristupni kod za e-knjigu dobija se kod bibliotekarke lično.
Prodaja knjiga: biblioteka NE prodaje knjige — knjige se isključivo pozajmljuju, preko rezervacije.
Stručna literatura: u fondu NEMA stručne literature, udžbenika ni skripti (matematika, programiranje, elektrotehnika i slično) — fond čine književna dela.
TEKST;
    }

    /**
     * Zvanični P/O parovi iz few-shot-pitanja-atvss.md, najrelevantniji za
     * tekuće FAQ pitanje (bira ih FaqZnanje). Model odgovara U SKLADU sa
     * ovim primerima — to je izvor znanja i STILA za pitanja o pravilima
     * i uslugama biblioteke.
     *
     * @param  array<int, array{pitanje: string, odgovor: string}>  $parovi
     */
    private function sekcijaFaqPrimeri(array $parovi): string
    {
        if (empty($parovi)) {
            return '';
        }

        $linije = [];
        foreach ($parovi as $par) {
            $linije[] = "P: {$par['pitanje']}\nO: {$par['odgovor']}";
        }

        return "ZVANIČNI ODGOVORI NA ČESTA PITANJA (korisnikovo pitanje je ovog tipa — "
            . "odgovori u skladu sa primerom koji odgovara pitanju; činjenice, imena, brojeve "
            . "i rokove prepiši TAČNO, a odgovor slobodno skrati ako je primer opširniji nego "
            . "što pitanje traži). Ovi primeri su i uzor STILA: odgovaraš mirno, ljubazno i "
            . "prirodnim srpskim jezikom, bez uvoda o sebi i bez odbijanja — kada primer "
            . "odgovara pitanju, njegovu formulaciju slobodno preuzmi doslovno:\n"
            . implode("\n\n", $linije);
    }

    /**
     * SEMANTIČKI preuzeti naučeni P/O parovi (NauceniPrimeriService, Faza B) —
     * hvataju parafraze koje leksičko FaqZnanje promaši. Iza flaga
     * AI_NAUCENI_PRIMERI: kad je ugašen, $nauceni je prazan i sekcije nema.
     *
     * Dedupe: par koji je već ušao kroz leksičku FAQ sekciju (isto pitanje) se
     * ovde izostavlja, da se isti primer ne prikaže dvaput i ne troši num_ctx.
     *
     * @param  array<int, array{pitanje: string, odgovor: string}>  $nauceni
     * @param  array<int, array{pitanje: string, odgovor: string}>  $faq
     */
    private function sekcijaNauceniPrimeri(array $nauceni, array $faq): string
    {
        if (empty($nauceni)) {
            return '';
        }

        $vecPrikazani = array_map(
            fn (array $p) => TextUtils::normalize($p['pitanje']),
            $faq
        );

        $linije = [];
        foreach ($nauceni as $par) {
            if (in_array(TextUtils::normalize($par['pitanje']), $vecPrikazani, true)) {
                continue; // isti par je već u leksičkoj FAQ sekciji
            }
            $linije[] = "P: {$par['pitanje']}\nO: {$par['odgovor']}";
        }

        if (empty($linije)) {
            return '';
        }

        return "SLIČNA RANIJE VIĐENA PITANJA (naučeni primeri — korisnikovo pitanje je "
            . "blisko ovima; odgovori u istom duhu, činjenice/imena/brojeve prepiši TAČNO, "
            . "a formulaciju slobodno preuzmi ako primer odgovara pitanju):\n"
            . implode("\n\n", $linije);
    }

    /**
     * Sveži agregati kataloga (bib-pravila.md, tačka 6) — uključuju i tačan
     * broj žanrova sa spiskom (broj knjiga po žanru), da model na pitanja o
     * žanrovima nikada ne odgovara iz opšteg znanja.
     */
    private function stanjeKataloga(array $agregati): string
    {
        if (empty($agregati)) {
            return '';
        }

        $tekst = "STANJE KATALOGA (u ovom trenutku — jedini izvor za brojeve):\n"
            . "Ukupno različitih knjiga: {$agregati['knjiga']}. "
            . "Ukupno primeraka: {$agregati['primeraka']}, od toga slobodnih {$agregati['slobodnih']}, "
            . "a nedostupnih (zaduženih ili rezervisanih) {$agregati['nedostupnih']}.";

        if (!empty($agregati['zanrovi'])) {
            $lista = collect($agregati['zanrovi'])
                ->map(fn (array $z) => "{$z['naziv']} ({$z['broj_knjiga']})")
                ->implode(', ');

            $tekst .= "\nŽanrova u katalogu: " . count($agregati['zanrovi'])
                . ". Spisak žanrova (u zagradi je broj knjiga): {$lista}."
                . ' Ovo su JEDINI žanrovi koji postoje — nijedan drugi žanr ne pominješ kao da ga imamo.'
                . ' Ove brojeve koristi SAMO kada korisnik pita za žanr. Broj knjiga na neku TEMU'
                . ' nije podatak koji postoji — za temu navedi pronađene knjige, bez tvrdnje o ukupnom broju.';
        }

        // Rezervni sloj protiv izmišljanja spiskova: pitanja po slovu,
        // godini i izdavaču rešava deterministički ruter, ali za svaki drugi
        // kriterijum koga u podacima nema (broj strana…) model NE sme da
        // sastavlja broj ni spisak — ručno testiranje: izmišljao je i jedno
        // i drugo („U katalogu imamo 4 knjige na slovo I: …").
        $tekst .= "\nAko korisnik traži broj ili spisak knjiga po kriterijumu za koji u ovom"
            . ' uputstvu nema podataka (na primer po broju strana), reci da taj'
            . ' podatak trenutno ne možeš da izračunaš i predloži pretragu po naslovu, autoru'
            . ' ili žanru — NIKADA ne sastavljaj takav broj ni spisak sam.';

        return $tekst;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PROMENLJIVE SEKCIJE (po intenciji / korisniku)
    // ──────────────────────────────────────────────────────────────────────────

    private function sekcijaNajnovije(?Collection $najnovije): string
    {
        if ($najnovije === null) {
            return '';
        }

        // Intencija prepoznata, ali podataka nema — model NE sme da izmišlja.
        if ($najnovije->isEmpty()) {
            return "NAJNOVIJE KNJIGE:\nNema podataka o najnovijim knjigama. "
                . 'Reci korisniku da trenutno nemaš taj podatak i NEMOJ navesti nijednu knjigu.';
        }

        $linije = $najnovije->map(fn (array $k, int $i) =>
            ($i + 1) . ". [{$k['naziv']}](/knjige/{$k['id']}) ({$k['autor']}) | slobodno primeraka: {$k['slobodno']}"
        );

        return "NAJNOVIJE KNJIGE U BIBLIOTECI (korisnik pita o najnovijim — odgovori iz ovog spiska):\n"
            . $linije->implode("\n");
    }

    private function sekcijaNajcitanije(?Collection $najcitanije): string
    {
        if ($najcitanije === null) {
            return '';
        }

        // Intencija prepoznata, ali statistike zaduženja/rezervacija još nema
        // — model mora da kaže da podataka nema, ne da bira „popularnu" knjigu.
        if ($najcitanije->isEmpty()) {
            return "NAJČITANIJE KNJIGE:\nStatistika čitanosti još ne postoji (nema zabeleženih "
                . 'zaduženja ni rezervacija). Reci korisniku da taj podatak još nemamo i NEMOJ '
                . 'navesti nijednu knjigu kao najčitaniju ili najtraženiju.';
        }

        $linije = $najcitanije->map(fn (array $k, int $i) =>
            ($i + 1) . ". [{$k['naziv']}](/knjige/{$k['id']}) ({$k['autor']}) | broj zaduženja: {$k['broj_zaduzenja']} | slobodno primeraka: {$k['slobodno']}"
        );

        return "NAJČITANIJE KNJIGE (korisnik pita o najčitanijim/najtraženijim — odgovori iz ovog spiska, "
            . "prva je najčitanija; brojevi zaduženja su tačni, prepiši ih doslovno):\n"
            . $linije->implode("\n") . "\n"
            . 'Ako korisnik pita za JEDNU knjigu (koja se najviše traži/čita/izdaje), navedi samo prvu '
            . 'sa spiska, na primer: "Najviše se traži [Naslov](/knjige/BROJ) (Ime Prezime), do sada je '
            . 'zaduživana N puta." — pazi na oblik: "1 put", "2/3/4 puta", "5 i više puta".';
    }

    /**
     * Stanje naloga člana; za GOSTA (clan === null) sekcija upozorava da
     * akcije koje zahtevaju nalog (rezervacija, zaduženje) nisu dostupne —
     * deterministički auth-gate hvata izričite zahteve pre modela, a ovo je
     * rezervni sloj za posredne formulacije.
     */
    private function sekcijaClan(?array $clan): string
    {
        if ($clan === null) {
            return "KORISNIK NIJE ULOGOVAN:\n"
                . 'Ovaj korisnik nije prijavljen na nalog. Rezervacija i zaduživanje knjiga su mogući '
                . 'samo za ulogovane članove — ako korisnik zatraži takvu akciju, ljubazno mu reci da '
                . 'prvo mora da se uloguje kao član biblioteke. Pretraga kataloga, preporuke i pitanja '
                . 'o biblioteci su mu dostupni bez naloga.';
        }

        $linije = [];

        if ($clan['rezervacija']) {
            $r = $clan['rezervacija'];
            $linija = "Aktivna rezervacija: \"{$r['knjiga']}\" — status: {$r['status']}";
            if ($r['rok_preuzimanja']) {
                $linija .= ", rok za preuzimanje: {$r['rok_preuzimanja']}";
            }
            $linije[] = $linija . '.';
        } else {
            $linije[] = 'Korisnik trenutno nema aktivnu rezervaciju.';
        }

        if ($clan['zaduzenje']) {
            $z = $clan['zaduzenje'];
            $linije[] = "Zadužena knjiga: \"{$z['knjiga']}\""
                . ($z['rok_vracanja'] ? " — rok za vraćanje: {$z['rok_vracanja']}." : '.');
        } else {
            $linije[] = 'Korisnik trenutno nema zaduženu knjigu.';
        }

        return "STANJE NALOGA OVOG KORISNIKA (ulogovani član — koristi za pitanja o njegovoj rezervaciji i zaduženju):\n"
            . implode("\n", $linije);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRONAĐENE KNJIGE / PREPORUKE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Rezultat pretrage za tekuće pitanje. Tri slučaja:
     *  - ima pogodaka → spisak sa uputstvom za navođenje i veze,
     *  - nema pogodaka, ali ima preporuka (pitanje jeste o katalogu) →
     *    uputstvo da se kaže da traženog nema i ponude preporuke; poruka
     *    zavisi od toga da li je tražen ŽANR ili KNJIGA (bib-izmene 5 i 6),
     *  - nema ničega → stroga zabrana pominjanja bilo koje knjige.
     */
    private function sekcijaKnjiga(Collection $knjige, Collection $preporuke, ?string $tip, ?string $srodniZanr): string
    {
        if ($knjige->isNotEmpty()) {
            return $this->spisakPogodaka($knjige);
        }

        if ($preporuke->isNotEmpty()) {
            return $this->spisakPreporuka($preporuke, $tip, $srodniZanr);
        }

        return "PRONAĐENE KNJIGE (za ovo pitanje):\n"
            . "Nijedna knjiga iz kataloga nije pronađena za ovo pitanje.\n"
            . "Ako korisnik traži knjige ili temu, odgovori da za taj upit nisi pronašao "
            . "knjige u katalogu i predloži da pogleda katalog na sajtu. Strogo je zabranjeno "
            . "pominjati bilo koji naslov ili vezu — nijedna knjiga ne postoji za ovaj upit.";
    }

    private function spisakPogodaka(Collection $knjige): string
    {
        $linije = $knjige->map(fn (array $hit, int $i) => ($i + 1) . '. ' . $this->linijaKnjige($hit));

        // Model najbolje prati poslednje uputstvo, pa pravila navođenja (sa
        // tačnim primerom prve knjige) stoje uz sam spisak.
        $prva    = $knjige->first()['knjiga'];
        $primer  = "[{$prva->naziv_knjige}](/knjige/{$prva->id}) ({$prva->autor})";
        $nemam   = self::NEMA_INFORMACIJE;

        return "PRONAĐENE KNJIGE (za ovo pitanje — jedini izvor o knjigama):\n"
            . $linije->implode("\n") . "\n"
            . "DOSTUPNOST (strogo): knjiga je dostupna SAMO ako u njenom redu piše da je "
            . "\"slobodno primeraka\" veće od 0. Ako je 0, izričito reci da trenutno NIJE dostupna "
            . "i da rezervacija/zaduženje nije moguće dok se primerak ne oslobodi — nikada ne "
            . "tvrdi suprotno. "
            . "POZAJMLJIVANJE uvek ide preko rezervacije: član na strani knjige klikne "
            . "\"Rezerviši\", bibliotekar odobri, i tek onda se knjiga preuzima u biblioteci. "
            . "Nikada ne reci da član može knjigu \"odmah da preuzme\" bez prethodne rezervacije. "
            . "Ove knjige odgovaraju pitanju korisnika — navedi one koje su bitne za pitanje "
            . "(ne odgovaraj \"{$nemam}\" kada ovde ima odgovarajućih knjiga). "
            . "Ako korisnik traži temu ili osobu, a ovde postoji knjiga o tome, ponudi je. "
            . "PAZI: ako je korisnik pitao za knjigu po imenu, a u ovom spisku nema knjige baš "
            . "pod tim naslovom, reci da tu knjigu nemamo, pa knjige sa spiska ponudi kao srodne "
            . "— ne tvrdi da tražena knjiga postoji. "
            . "Naziv knjige pišeš tačno kao gore, u obliku veze, a odmah iza veze ime autora u "
            . "malim zagradama — na primer, knjiga broj 1 se piše: {$primer} — a ostatak rečenice "
            . "je običan tekst. U uglaste zagrade sme da stane samo naziv knjige, nikada autor "
            . "niti druge reči.";
    }

    private function spisakPreporuka(Collection $preporuke, ?string $tip, ?string $srodniZanr): string
    {
        $linije = $preporuke->map(fn (array $hit, int $i) =>
            ($i + 1) . '. ' . $this->linijaKnjige($hit));

        $prva   = $preporuke->first()['knjiga'];
        $primer = "[{$prva->naziv_knjige}](/knjige/{$prva->id}) ({$prva->autor})";

        $uputstvo = $tip === 'zanr'
            ? "Korisnik je tražio žanr koga u katalogu nema. Reci mu: \"Trenutno nemamo knjige "
                . "iz tog žanra, ali vam mogu preporučiti srodne naslove"
                . ($srodniZanr ? " iz žanra {$srodniZanr}" : '') . "\" i navedi jednu do tri "
                . "knjige sa ovog spiska."
            : "Korisnik je tražio knjigu ili temu koje u katalogu nema. Reci mu: \"Trenutno "
                . "nemamo tu knjigu u katalogu. Ukoliko želite, mogu da vam preporučim slične "
                . "naslove\" i navedi jednu do dve knjige sa ovog spiska.";

        return "TRAŽENO NIJE PRONAĐENO — PREPORUKE (za ovo pitanje):\n"
            . $linije->implode("\n") . "\n"
            . $uputstvo . ' '
            . "Naziv knjige pišeš u obliku veze, na primer: {$primer}. "
            . "Nijednu drugu knjigu van ovog spiska ne smeš pomenuti.";
    }

    /**
     * Red sa podacima jedne knjige za prompt. NAMERNO bez interne
     * "relevantnosti" (score): model je broj prepisivao u odgovore
     * ("Relevantnost: 1.00" — eval za_kod.md, set3), a rangiranje već
     * nosi redosled spiska. Šta model ne vidi — ne može ni da procuri.
     */
    private function linijaKnjige(array $hit): string
    {
        $k       = $hit['knjiga'];
        $zanrovi = $k->zanrovi->pluck('naziv')->implode(', ');
        $slobodno = (int) ($k->slobodni_primerci_count ?? 0);
        $ukupno   = (int) ($k->ukupno_primeraka_count ?? 0);
        $zaduzenja   = (int) ($k->broj_zaduzenja_count ?? 0);
        $rezervacija = (int) ($k->broj_rezervacija_count ?? 0);
        $opis     = trim((string) $k->opis);
        if (mb_strlen($opis) > 300) {
            $opis = mb_substr($opis, 0, 300) . '…';
        }

        $izdanje = trim(implode(', ', array_filter([$k->mesto_izdavanja, $k->godina_izdavanja])));

        $delovi = array_filter([
            "[{$k->naziv_knjige}](/knjige/{$k->id}) ({$k->autor})",
            $zanrovi !== '' ? "žanrovi: {$zanrovi}" : null,
            ($k->izdavac ?? '') !== '' ? "izdavač: {$k->izdavac}" : null,
            $izdanje !== '' ? "izdanje: {$izdanje}" : null,
            "slobodno primeraka: {$slobodno} od {$ukupno}",
            $zaduzenja > 0 ? "do sada zaduživana {$zaduzenja} puta" : null,
            $rezervacija > 0 ? "broj rezervacija do sada: {$rezervacija}" : null,
            $k->je_eknjiga ? 'dostupna i kao elektronska knjiga' : null,
            $opis !== '' ? "opis: {$opis}" : null,
        ]);

        return implode(' | ', $delovi);
    }

    private function zavrsnaProvera(): string
    {
        return 'PRE SLANJA PROVERI (u sebi, ne ispisuj): 1) Da li je svaka tvrdnja i svaki broj '
            . 'tačno iz podataka u ovom uputstvu? 2) Da li je svaka pomenuta knjiga sa spiska, '
            . 'napisana kao veza i sa imenom autora? 3) Da li je ceo odgovor na srpskom, latinicom, '
            . 'bez ijedne strane reči i bez posebnih znakova za oblikovanje? '
            . '4) Da li je odgovor pravopisno i gramatički ispravan (velika slova, padeži, zarezi)?';
    }
}
