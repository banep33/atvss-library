# Zadatak za Fable 5 — RAG chatbot biblioteke ATVSS („Bib")

Radiš na RAG chatbot-u za biblioteku ATVSS (Laravel backend). Pre bilo kakve
izmene, prvo detaljno ANALIZIRAJ postojeću arhitekturu i logiku, pa tek onda
predloži plan i implementiraj. Cilj: bot treba da tačno odgovori na SVAKU vrstu
pitanja, uz čistu i jasno podeljenu logiku — bez gomilanja servisnih klasa.

============================================================
FAZA 0 — ANALIZA POSTOJEĆE ARHITEKTURE (uradi prvo, ne diraj kod još)
============================================================
Prođi kroz sve fajlove i napravi izveštaj:
1. Mapiraj tok podataka: od ulaznog pitanja do odgovora (koji fajl/klasa šta radi).
2. Oceni ŠTA JE DOBRO i treba zadržati.
3. Nađi PROBLEME: nedoslednosti između fajlova, duplirana logika, klase koje se
   preklapaju, mesta gde se agregacija/brojanje radi nad retrievanim tekstom
   umesto nad bazom, mešanje odgovornosti (retrieval + biznis logika + DB pristup
   u istoj klasi).
4. Predloži ČISTU PODELU (vidi ciljnu arhitekturu dole) i mapiraj koji postojeći
   kod ide u koji sloj — reci šta se spaja, šta briše, šta ostaje.
Prvo mi vrati ovaj izveštaj i plan izmena (sa listom fajlova koje diraš).
Ne počinji implementaciju dok ne potvrdim plan.

Fajlovi za pregled: `app/Http/Controllers/AiChatController.php`,
`app/Services/Ai/{AiChatService,KatalogPretraga,StrukturniUpiti,RagService,
VectorStore,SystemPromptService,OllamaClient,RagClient,TextUtils}.php`,
modeli `Knjiga, Arhiva, Rezervacija, Zaduzivanje, Zanr`,
i specifikacije `bib-pravila.md`, `few-shot-pitanja-atvss.md`, `pitanja-za-katalog.md`.

============================================================
CILJNA ARHITEKTURA — čista, jasno podeljena, BEZ eksplozije klasa
============================================================
Ne pravi 10 servisnih klasa. Drži se JEDNOG pipeline-a sa ovim slojevima. VAŽNO:
ovi slojevi VEĆ POSTOJE u kodu pod drugim imenima — cilj je da ih PREUREDIŠ u
ovu podelu, a NE da napraviš paralelne nove klase. Ispod svakog sloja je mapa na
postojeći kod:

  1) IntentClassifier — određuje nameru + izvlači parametre (entitet, žanr/tema,
     datumski opseg, akcija). → JEDINA suštinski nova klasa. U nju PRESELI
     postojeće regexe koji su sad razbacani: `KatalogPretraga::detektujIntencije`,
     `jeTematskoPitanje`, `jeAkcioniZahtev`, `zanrListaFraza`, `detektujCountRec`,
     `izvuciKoJe` + `StrukturniUpiti::parsirajDatumskiOpseg`. Ne prepisuj logiku —
     premesti je i vrati jednu tipiziranu nameru (enum) + parametre.

  2) Router / Dispatcher — na osnovu namere poziva handler. → Ovo je danas
     ~250-linijski if-lanac u `AiChatService::retrieveContext`; svedi ga na tanak
     dispatch.

  3) Handleri po nameri (tanki, strategy) koji koriste zajedničke servise:
       - StructuredQueryHandler (agregacije, brojanja, statistika, datumski opseg)
         → već postoji kao `StrukturniUpiti` (zadrži, po potrebi preimenuj/tanko
           obmotaj).
       - SemanticSearchHandler (vector RAG za temu/sadržaj) → `RagService`.
       - AuthGateHandler (akcije koje traže nalog) → već fiksni odgovor u
         `retrieveContext` (`TextUtils::AUTH_REQUIRED`).
       - FallbackHandler (van-tematska pitanja / odbijanje) → već fiksni odgovori
         (`SystemPromptService::VAN_TEME`, `NOT_RECOGNIZED`).

  4) Samo DVA zajednička servisa ispod handlera:
       - LibraryDataService — SVI upiti nad bazom/arhivom (COUNT, GROUP BY,
         ORDER BY, filter po datumu). Jedino mesto za SQL/ORM. → Konsoliduj ovde
         DB upite koji su sad razbacani po `StrukturniUpiti`, `AiChatService`
         (agregatiKataloga, listaNajcitanijih, clanKontekst…) i modelima.
       - RetrievalService — SVA vektorska pretraga. → `RagService` + `VectorStore`.

Pravila čistoće: handler ne priča direktno sa bazom mimo LibraryDataService;
retrieval se ne meša sa biznis logikom; klasifikacija je odvojena od izvršenja.
Ako neka postojeća klasa radi više stvari, razdvoj je po ovim slojevima umesto
da dodaješ nove paralelne servise.

VAŽNO — NE RUŠI POSTOJEĆU LOGIKU:
Zadrži svu funkcionalnost koja radi ispravno. Implementiraj SAMO potrebne izmene,
kao inkrementalnu nadogradnju/reorganizaciju, ne refaktor od nule. Ako postojeća
putanja daje tačan odgovor, premesti je u odgovarajući sloj i zadrži ponašanje.

Napomena: primeri niže su ilustrativni. Rešenje mora da radi za SVA pitanja tog
tipa, ne samo za konkretan primer.

============================================================
KONKRETNI NALAZI IZ KODA — uzroci grešaka koje treba rešiti
============================================================
Ovo su stvarni uzroci grešaka uočenih pri testiranju (utvrđeno nad kodom i bazom):

NALAZ 1 (NAJVAŽNIJI) — Tema ne može da radi jer u bazi NEMA podataka o temi.
Svih 358 knjiga ima PRAZAN `opis`. A `RagService::buildIndexText` embeduje
`naziv + autor + žanr + mesto/godina + opis`. Pošto je opis prazan, vektor knjige
je efektivno naslov + autor + IME ŽANRA — zato „na temu priče" semantički vraća
knjige iz ŽANRA „Priče". Pravilo „tema ≠ žanr" je sa ovim podacima NEISPUNJIVO.
  → (a) Izbaci ime žanra iz teksta koji se embeduje za tematsku pretragu (gradi
        vektor iz `opis`, po potrebi + naziv), da žanr više ne curi u temu.
  → (b) Pošto je `opis` prazan svuda, predloži mi konkretno rešenje za popunjavanje
        (uvoz/ručno/batch) ili zaseban `tema`/`kljucne_reci` izvor — NE izmišljaj
        sadržaj sam — pa `php artisan ai:reindex`.
  → (c) Dok opisa nema: na čisto tematsko pitanje bot NE sme da servira žanr kao
        temu; neka pošteno kaže da za tu temu nema dovoljno podataka i ponudi
        pretragu po naslovu/autoru/žanru.

NALAZ 2 — Klasifikacija namere razbacana po regexima → glavni uzrok „procurivanja"
na LLM. Kad formulacija promaši regex, pitanje padne na model koji izmišlja žanr
ili SKRAĆUJE listu. Rešava ga IntentClassifier iz ciljne arhitekture (sloj 1).

NALAZ 3 — Brojanje reči je egzaktan substring (`str_contains(naziv,"price")`), pa
„priče" nađe 5 ali promaši padeže („Beskrajna prič**a**"). Uvedi poređenje po
normalizovanom korenu (padeži iste reči ulaze u broj), bez lažnih pogodaka
(„princip"), i dalje TAČAN COUNT + KOMPLETNO nabrajanje.

NALAZ 4 — Dupli kod: `fuzzyJednake()` i `tokenizuj()` su kopirani u
`KatalogPretraga` i `StrukturniUpiti`. Izdvoj ih u `TextUtils` (jedan izvor
fuzzy pravila). Ukloni mrtav `StrukturniUpiti::porukaPrekoLimita()` (ne koristi se).

NALAZ 5 — „najtraženije/najčešće izdaje" detekcija je krhka (traži „naj…" uz
glagol). Proširi da hvata „koja knjiga ima najviše zaduženja", „najizdavanija",
„najviše se pozajmljuje" itd., i da tada semantički žanrovski pogoci NE cure.

============================================================
FUNKCIONALNI ZAHTEVI (klasifikacija pitanja i tačni odgovori)
============================================================
--- 1. ROUTING / KLASIFIKACIJA NAMERE ---
A) STRUKTURNI UPIT NAD BAZOM (LibraryDataService, NE vector search):
   - agregacije/rangiranja: "koja knjiga se najviše traži / najčešće izdaje"
     -> broji zaduženja po knjizi, vrati onu sa najviše. ("najviše traži" i
        "najčešće izdaje" su ISTO pitanje -> isti upit.)   [vidi Nalaz 5]
   - brojanje + nabrajanje: "koliko knjiga ima naslov sa rečju X"
     -> vrati TAČAN broj I NABROJ SVE stavke. Nikad ne skraćuj (ako je 5, svih 5,
        ne 3). Ako baš mora skraćeno, reci ukupan broj i ponudi ostatak. [Nalaz 3]
   - statistika: "koliko često se zaduzuju knjige", "koliko se nedeljno zaduzi"
     -> izračunaj iz istorije/arhive (prosek po periodu) i objasni period.
B) DATUMSKI OPSEG NAD ARHIVOM:
   - "koje knjige su zaduzene pre nedelju dana / 2 dana / 3 dana / 2 nedelje..."
   - Parsiraj relativni datum u opseg i filtriraj arhivu/zaduženja.
   - "pre N dana" znači "u POSLEDNJIH N dana" (od danas−N do danas), NE tačno taj
     jedan dan. Ovo je trenutno ponašanje (`datum_akcije >= now()−N`) i TAKO OSTAJE.
   - MAKSIMUM 1 mesec unazad; za starije vrati poruku da pretraga ide do mesec dana.
C) SEMANTIČKA PRETRAGA (RetrievalService): pitanja o temi/sadržaju knjige. [Nalaz 1]

--- 2. TEMA vs ŽANR (kritična greška) ---   [koreni uzrok je Nalaz 1]
Različita polja, ne mešati:
   - "knjige na temu X" -> pretraga po TEMI/opisu, vrati knjige čija je TEMA X.
   - "knjige žanra X"    -> filter po polju ŽANR, vrati kompletnu listu
     (uz pravilo o nabrajanju iz 1A — nikad skraćeno).

--- 3. PRISTUP PODACIMA (poverljivost) ---
Bot SME da čita rezervacije/zaduženja/arhivu u delu koji NIJE lični podatak
korisnika (koja knjiga, koliko puta, kada, ko je zaduzio — nepoverljivo, statistika).
Ne izmišljaj podatke — sve brojke dolaze iz baze/arhive.

--- 4. VAN-TEMATSKA PITANJA ---
Za sve što nije vezano za biblioteku (sport, hemija, biologija, astronomija, opšte
znanje...) odgovori TAČNO:
   "Ja sam asistent biblioteke ATVSS-a, nažalost ne mogu da vam pomognem sa tim"
Za "ko je taj i taj": prvo proveri da li je autor neke knjige u bazi; ako nije ->
ista poruka odbijanja.

--- 5. GUEST vs ULOGOVAN KORISNIK ---
Ako GUEST (neulogovan) traži da rezerviše/zaduzi/pozajmi -> odgovori da za tu akciju
mora biti ulogovan član. Informativna pitanja (pretrage, statistike) dozvoljena i guestu.

--- 6. KONVERZACIJSKI KONTEKST (pamćenje razgovora) ---
Bot čita prethodne poruke istog razgovora i nadovezuje se (istorija već postoji:
`AiChatService::historyFromDb`):
   - Prosledi istoriju (poslednjih ~3-5 razmena) uz trenutno pitanje.
   - Razreši nastavke/reference: "a taj autor?", "izlistaj i ostale", "a za prošlu
     nedelju?", "koje još?" — u kontekstu prethodnog pitanja i odgovora.
   - Klasifikacija namere uzima u obzir i prethodnu poruku (nastavak može menjati nameru).
   - Ne gubi filtere iz prethodnog pitanja kad je novo očigledan nastavak
     (isti žanr/tema/datumski opseg).
   - Ako je referenca nejasna, kratko pitaj za pojašnjenje umesto da pogađaš.

============================================================
ŠTA URADITI (redosled)
============================================================
1. Isporuči FAZU 0 (analiza + plan + mapiranje na ciljnu arhitekturu). Sačekaj potvrdu.
2. Reši NALAZ 1 (tema/opis + izbacivanje žanra iz embeddinga) — predloži plan za opis.
3. Uvedi IntentClassifier + Router (sloj 1 i 2) kao reorganizaciju postojećeg toka,
   ne kao zamenu — preseli regexe iz KatalogPretraga/StrukturniUpiti.
4. Konsoliduj sve DB upite u LibraryDataService (COUNT/GROUP BY/ORDER BY/filter po
   datumu); agregacije se NE rade nad retrievanim tekstom.
5. Razdvoj polja tema i žanr u pretrazi i u promptu.
6. Osiguraj da se liste nikad ne skraćuju bez eksplicitne napomene (ojačaj pravilo
   SPISKOVA u SystemPromptService da nadjača pravilo kratkoće).
7. AuthGate i van-tematsko odbijanje kroz FallbackHandler (zadrži postojeće poruke).
8. Reši Nalaz 3 (morfologija brojanja), Nalaz 4 (dedupe fuzzy/tokenizuj + mrtav kod),
   Nalaz 5 (robusnija „najtraženije" detekcija).
9. Uvedi/aktiviraj razrešavanje referenci na prethodne poruke.
10. Testiraj svaki tip pitanja (primeri iz `few-shot-pitanja-atvss.md` i
    `pitanja-za-katalog.md`); dodaj testove u `tests/`.
Konsoliduj postojeće preklapajuće klase u definisane slojeve — ne dodavaj nove
paralelne servise. Cilj je manje, jasnije podeljenih komponenti.
Ne menjaj ponašanje koje već radi ispravno.
