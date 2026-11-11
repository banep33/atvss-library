<?php

namespace App\Services\Ai;

/**
 * Namera — tipizirani rezultat klasifikacije jednog korisničkog pitanja
 * (IntentClassifier::klasifikuj): primarna namera (TipNamere) + izvučeni
 * parametri + sekundarni signali koje koriste Router, handleri i prompt.
 *
 * Nepromenljiv objekat — klasifikacija je odvojena od izvršenja.
 */
final class Namera
{
    public function __construct(
        public readonly TipNamere $tip,

        // ── parametri (popunjeni u zavisnosti od tipa) ─────────────────────
        /** ['dana' => int] ili ['preko_limita' => true] za DatumskiOpseg. */
        public readonly ?array $datumskiOpseg = null,
        /** Reč čiji se broj u naslovima traži (BrojanjeReci). */
        public readonly ?string $countRec = null,
        /** Fraza kojom je imenovan žanr za kompletnu listu (ZanrLista). */
        public readonly ?string $zanrListaFraza = null,
        /** Slovo kojim traženi naslovi počinju (PocinjeNaSlovo). */
        public readonly ?string $pocinjeSlovo = null,
        /** Opseg GODINE IZDANJA: ['od' => ?int, 'do' => ?int] (GodinaIzdanja). */
        public readonly ?array $godinaOpseg = null,
        /** „Ko je X" — fraza X (KoJeOsoba). */
        public readonly ?string $koJe = null,
        /** Imenovana knjiga iz navodnika/„knjigu Xxx": ['prikaz','norm']. */
        public readonly ?array $imenovanaKnjiga = null,
        /** Izričito imenovan autor („od autora X"): ['prikaz','norm']. */
        public readonly ?array $autorFraza = null,
        /**
         * HEURISTIČKI kandidat za autora bez reči „autora" („knjige Stevana
         * Sremca", „nešto od Crnjanskog"): ['prikaz','norm']. Router ga STROGO
         * proverava u bazi — postoji → fiksna autorska lista; ne postoji →
         * normalan tok (za razliku od autorFraza, gde nepostojanje daje
         * „nemamo autora X").
         */
        public readonly ?array $autorKandidat = null,
        /** Izričito imenovan izdavač („od izdavača Laguna"): ['prikaz','norm']. */
        public readonly ?array $izdavacFraza = null,
        /** Statistika o izdavačima: 'najvise_knjiga' | 'najpopularniji' | 'vise_od_jedne' | 'lista'. */
        public readonly ?string $izdavacStatistika = null,
        /** Statistika o autorima: 'najvise' | 'vise_od_jedne' | 'samo_jedna'. */
        public readonly ?string $autorStatistika = null,
        /** „U kojoj godini imate najviše knjiga" — godina sa najviše naslova. */
        public readonly bool $godinaNajvise = false,
        /** Ekstrem po godini izdanja: 'najstarija' | 'najnovija'. */
        public readonly ?string $ekstremKnjiga = null,
        /** Imenovano MESTO izdavanja („izdate u Beogradu"): ['prikaz','norm']. */
        public readonly ?array $mestoFraza = null,
        /**
         * SVE knjige imenovane u pitanju (navodnici) — za poređenje dve knjige
         * i relacione upite. Prva je ujedno i $imenovanaKnjiga.
         *
         * @var array<int, array{prikaz: string, norm: string}>
         */
        public readonly array $imenovaneKnjige = [],
        /**
         * RELACIONI upit „iste osobine kao knjiga X": 'izdavac' | 'godina' |
         * 'autor' — kriterijum po kome se traže OSTALE knjige slične referenci.
         */
        public readonly ?string $relacioniKriterijum = null,
        /**
         * POREĐENJE dve imenovane knjige: 'starija' | 'novija' | 'izdavac' |
         * 'mesto' — šta se poredi („koja je starija, X ili Y").
         */
        public readonly ?string $poredjenjeKriterijum = null,
        /**
         * „Još neku knjigu [autora]" — traži OSTALE knjige istog autora
         * (isključujući već pomenute), autor imenovan direktno ili preko
         * „autora knjige X".
         */
        public readonly bool $josKnjiga = false,
        /**
         * SADRŽAJNO / SUBJEKTIVNO pitanje o knjizi („koja knjiga govori o
         * ratu", „koja je najtužnija", „glavni junak lekar") — odgovor traži
         * OPIS knjige; dok fond nema opise → pošten fiksni odgovor.
         */
        public readonly bool $sadrzajnoPitanje = false,
        /** „Postoji li nastavak knjige X" — druga dela istog autora/serijala. */
        public readonly bool $nastavakUpit = false,
        /** „Reč X u naslovu" — ['rec' => X, 'pozicija' => 'pocetak'|'kraj'|'sadrzi']. */
        public readonly ?array $recNaslova = null,
        /** „Koje knjige imaju BROJ u naslovu" — naslovi sa cifrom. */
        public readonly bool $brojUNaslovu = false,
        /** „Knjige koje imaju TAČNO N reči u naslovu" — broj reči naslova. */
        public readonly ?int $reciUNaslovu = null,
        /** „Autori čije PREZIME počinje slovom X" — slovo (malo, latinica). */
        public readonly ?string $prezimeSlovo = null,
        /** „Postoje li dve knjige istog autora iz iste godine" — analiza kataloga. */
        public readonly bool $dveIsteGodine = false,

        // ── sekundarni signali (intencije) ─────────────────────────────────
        public readonly bool $akcioniZahtev = false,
        /**
         * Pitanje PRIJAVLJENOG člana o SOPSTVENIM rezervacijama/zaduženjima
         * („šta sam rezervisao", „da li mi je odobrena rezervacija", „kad mi
         * ističe rok"). Router vraća fiksni odgovor iz baze filtriran po
         * user_id iz serverske sesije; gost dobija AUTH_REQUIRED. Model se ne
         * poziva — podaci o rezervacijama se ne nagađaju.
         */
        public readonly bool $mojeRezervacijeUpit = false,
        /** „Koji sam po redu na listi čekanja" — redosled rezervacija iz baze. */
        public readonly bool $listaCekanja = false,
        /** Pitanje o dostupnosti („da li je X dostupna/zauzeta/slobodna"). */
        public readonly bool $dostupnostUpit = false,
        public readonly bool $koJeZaduzio = false,
        public readonly bool $statistika = false,
        /** Knjige koje NIKADA nisu zadužene (COUNT = 0, strukturni upit). */
        public readonly bool $nikadZaduzene = false,
        /** Poređenje popularnosti žanrova (važi tek uz ≥ 2 nađena žanra). */
        public readonly bool $poredjenjeZanrova = false,
        /** Najveći odnos rezervacija / broj primeraka (strukturni upit). */
        public readonly bool $odnosRezervacija = false,
        /** Prosečan broj zaduženja po naslovu — AVG (strukturni upit). */
        public readonly bool $prosekZaduzenja = false,
        public readonly bool $tematsko = false,
        /** Pitanje o stručnoj literaturi (matematika, programiranje…) koje fond nema. */
        public readonly bool $strucnaLiteratura = false,
        public readonly bool $najnovije = false,
        public readonly bool $najcitanije = false,
        public readonly bool $agregatno = false,
        public readonly bool $katalosko = false,
        public readonly bool $faq = false,
        public readonly bool $konverzacioni = false,
        public readonly bool $zanrovskiUpit = false,
        /** Traži se knjiga SLIČNA nekoj drugoj („nešto slično knjizi X"). */
        public readonly bool $slicnoUpit = false,
        /**
         * Pitanje o IDENTITETU bota („ko si ti", „da li si AI") — router vraća
         * fiksni Bib identitet, model se ne poziva (Faza 2).
         */
        public readonly bool $identitetUpit = false,
        /**
         * Pitanje o KONTAKTU biblioteke — ime bibliotekarke, mejl, telefon,
         * „kome da se obratim / sa kim da razgovaram". Router vraća fiksni
         * kontakt (ime + mejl + telefon), model se ne poziva: nepromenljivi
         * podaci se ne prepričavaju kroz slab model.
         */
        public readonly bool $kontaktUpit = false,
        /**
         * FIKSNA FAQ TEMA (Faza 4 — FAQ determinizam): ključ teme sa
         * nepromenljivom činjenicom (lozinka | otkaz_rezervacije | clanarina |
         * ne_prodaje | uclanjenje) za koju router vraća fiksni odgovor iz
         * TextUtils::FAQ_TEME, bez modela. Null kada nije takva tema.
         */
        public readonly ?string $faqFiksnaTema = null,
        /**
         * „Da li je X domaći ili strani pisac" (Faza 4) — ime imenovanog pisca
         * za klasifikaciju iz kurirane podele, ili null.
         */
        public readonly ?string $podelaPiscaIme = null,
        /** „Preporuči mi stranog pisca / nešto od stranih autora" (Faza 4). */
        public readonly bool $straniPisciUpit = false,
        /**
         * „Ko je autor knjige X" — ime autora imenovane knjige (ne relacioni
         * „još knjiga tog autora"); router razrešava knjigu i vraća autora.
         */
        public readonly bool $autorKnjigeUpit = false,
        /**
         * Pitanje o podacima VAN KATALOGA — globalne/spoljne procene („najbolja
         * knjiga svih vremena", „najčitanija na svetu", „najpopularniji žanr u
         * Srbiji"). Router vraća pošten scope-odgovor (kandidati_ucenje_5, F).
         */
        public readonly bool $vanKataloga = false,
        /**
         * DUBLJA ANALITIKA po sekundarnim osama (godina / mesto / izdavač) i
         * njihovim ukrštanjima — svesno se ne računa, pošteno odbijanje umesto
         * pogrešnog jednoosnog odgovora (kandidati_ucenje_5, korpa E).
         */
        public readonly bool $dubljaAnalitika = false,
        /**
         * „Postoji li autor koji piše iz više različitih žanrova" (korpa A) —
         * analitika raznovrsnosti autora; router vraća fiksni odgovor.
         */
        public readonly bool $autorViseZanrova = false,
        /**
         * „Koji domaći/strani pisac ima najviše knjiga" (Potez 2) — autorska
         * statistika filtrirana po kuriranoj podeli: 'domaci' | 'strani' | null.
         */
        public readonly ?string $autorStatPodela = null,
        /**
         * „Da li je knjiga X ikad pozajmljena / zaduživana" (korpa B) — pitanje
         * o istoriji zaduživanja imenovane knjige; router vraća broj iz arhive.
         */
        public readonly bool $ikadZaduzenaUpit = false,
        /** „Koja je knjiga najmanje pozajmljivana" (korpa B) — najmanje zaduženja. */
        public readonly bool $najmanjePozajmljivana = false,
        /** „Preporuči knjigu koja još nije pozajmljena" (korpa B) — nezaduživani naslovi. */
        public readonly bool $nepozajmljenaPreporuka = false,

        // ── konverzacijski kontekst (nastavak razgovora) ───────────────────
        /** Poruka je očigledan NASTAVAK prethodnog pitanja („a taj autor?"). */
        public readonly bool $nastavak = false,
        /**
         * Normalizovan tekst prethodne razmene (pitanje + odgovor) — za
         * kombinovanu leksičku pretragu kada je nastavak bez sopstvenih
         * pogodaka („a taj autor?" nalazi autora iz prethodnog odgovora).
         */
        public readonly ?string $kontekstNorm = null,
        /**
         * Korisnik traži OSTATAK skraćenog spiska iz prethodnog odgovora —
         * namera PRETHODNOG pitanja, koju router ponovo izvršava sa
         * pomerajem (preskače već ispisane stavke).
         */
        public readonly ?Namera $ostatakOd = null,
        /**
         * Normalizovan tekst pitanja iz koga je namera klasifikovana — za
         * ponovno izvršavanje PRETHODNE namere („recite mi ostatak") kojoj
         * trebaju detalji iz teksta (žanr uz autora i sl.).
         */
        public readonly ?string $izvornaNorm = null,
    ) {}
}
