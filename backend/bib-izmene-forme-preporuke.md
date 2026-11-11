# Zadatak za Claude Code — forme odgovora i sistem preporuke

Analiziraj kompletan AI sistem: system prompt, RAG pipeline, servis za preporuke i logiku generisanja odgovora. Na osnovu zahteva ispod izvrši izmene u kodu i system promptu. **Nemoj samo predlagati — implementiraj tamo gde je potrebno.**

Sve izmene moraju biti u skladu sa `bib-pravila.md` (latinica, humanizacija, naslovi kao klikabilni linkovi koji otvaraju show modal) i ne smeju narušiti postojeće funkcionalnosti.

Radi ovim redosledom: **(A) analiza → (B) kratak plan → sačekaj potvrdu → (C) implementacija** Ne preskači korake.

---

## 1. Stil odgovora

Chatbot odgovara prirodno, ljubazno i profesionalno, kao bibliotekar. Odgovor ostavlja utisak da ga piše osoba, ne AI. Svaki odgovor mora biti: gramatički i pravopisno ispravan, smislen, logički povezan, na standardnom srpskom u latinici, bez robotskih formulacija i bez nepotrebnog ponavljanja.

**Kontrola kvaliteta — bez drugog LLM prolaza.** Ne uvoditi dodatni poziv modelu za „preformulisanje", jer to udvostručava latenciju na lokalnom qwen2.5:7b i sukobljava se sa zahtevom za brzinom (tačka 8). Umesto toga:

- kvalitet obezbediti kroz jasan system prompt (few-shot primeri dobrih odgovora),
- deterministička validacija pre slanja: da je izlaz latinica i srpski, da nema stranih reči, da su svi linkovi validni i da dužina odgovara zahtevu.

## 2. Dužina odgovora

Što kraće i konkretnije. Bez dugih objašnjenja osim ako korisnik izričito zatraži. Prioritet: kratko, jasno, precizno, prirodno.

## 3. Prikaz knjiga (direktna pretraga)

Kada korisnik traži knjige određenog žanra, autora ili teme:

- prikazati **maksimalno 5** knjiga, nikad ceo katalog,
- rangirati tih 5 **po RAG relevantnosti (score)** — NE po najnovije/najčitanije (to važi samo za preporuke, tačka 4),
- rezultati moraju biti **jedinstveni** (bez duplikata),
- svaki naslov je klikabilan link ka show modalu knjige.

## 4. Sistem preporuke

Redizajnirati da koristi postojeće servise/scope za najnovije i najčitanije knjige (ne nasumičan izbor).

Redosled prioriteta kada se **daje preporuka** (žanr ili knjiga ne postoji — tačke 5 i 6):

1. Najnovije knjige
2. Najčitanije knjige

Pravila:

- maksimalno **5** rezultata, jedinstvenih (knjiga koja je i najnovija i najčitanija prikazuje se jednom),
- za knjige istog prioriteta zadržati **redosled učitavanja iz Excel fajla**. Za ovo je potreban deterministički ključ sortiranja (npr. `id ASC` ili posebna `import_order` kolona). Proveri da li takav ključ postoji; ako ne, uvedi ga i popuni pri importu.

## 5. Ako žanr ne postoji

Ne završavati odgovor samo informacijom da žanr ne postoji:

- pronaći **najsrodniji postojeći žanr koristeći postojeće embeddinge** (kosinusna sličnost), ne hardkodovanu mapu žanrova,
- ponuditi nekoliko knjiga iz tog srodnog žanra (po pravilima iz tačke 4).

Primer: „Trenutno nemamo knjige iz tog žanra, ali vam mogu preporučiti sledeće srodne naslove..."

## 6. Ako knjiga ne postoji

- odgovoriti da knjiga nije pronađena,
- pitati korisnika da li želi preporuku slične knjige,
- ponuditi nekoliko najsličnijih naslova iz istog ili srodnog žanra (sličnost preko postojećih embeddinga, redosled po tački 4).

Primer: „Trenutno nemamo tu knjigu u katalogu. Ukoliko želite, mogu da vam preporučim slične naslove."

## 7. Čišćenje postojeće implementacije

Proveriti da li kod već implementira nešto od navedenog. Ukloniti nepotrebne klase, dupliranu logiku, zastarelu implementaciju, neiskorišćene metode i višak koda — ostaviti najčistije moguće rešenje. Ne uklanjati kod koji se koristi; ako nisi siguran, prvo proveri sve reference kroz projekat.

## 8. Cilj

Chatbot ostavlja utisak profesionalnog bibliotekara: prirodno, brzo, kratko i tačno, uz inteligentan sistem preporuke i pregledan prikaz rezultata. Sve u skladu sa postojećom arhitekturom, bez narušavanja postojećih funkcionalnosti.

## 9. Verifikacija (obavezno na kraju)

Testiraj izmene protiv test pitanja iz `test-pitanja.md` (ili priloženog test seta) i prikaži stvarne odgovore za: pretragu po žanru (rangiranje i limit od 5), nepostojeći žanr (srodna preporuka), nepostojeću knjigu (ponuda sličnih), i jedno FAQ pitanje. Priloži izveštaj: šta je izmenjeno/uklonjeno/dodato uz obrazloženje.
