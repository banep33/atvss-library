# ATVSS biblioteka

Veb aplikacija za vođenje biblioteke Akademije tehničko-vaspitačkih strukovnih
studija (ATVSS) — katalog, primerci, rezervacije, zaduženja i obaveštenja,
uz AI asistenta **Bib** koji odgovara na pitanja o fondu i pravilima biblioteke
na srpskom jeziku (latinica i ćirilica).

## Funkcionalnosti

**Za članove**
- pretraga kataloga po naslovu, autoru, žanru, godini i mestu izdanja
- rezervacija knjiga, lista čekanja i pregled sopstvenih zaduženja
- obaveštenja u aplikaciji i e-poštom (odobrena rezervacija, podsetnik za rok vraćanja)
- predlaganje novih naslova za nabavku
- prikaz celog sajta na latinici ili ćirilici (preslovljavanje na serveru)

**Za bibliotekare i administratore**
- upravljanje knjigama, primercima, žanrovima i članovima
- odobravanje rezervacija, zaduživanje i razduživanje primeraka
- inventarna knjiga i masovni uvoz fonda iz Excel tabele
- arhiva zaduženja i statistika najčitanijih naslova
- zakazani poslovi: istek rezervacija, podsetnici rokova, osvežavanje najčitanijih

**AI asistent „Bib"**
- hibridno rutiranje: deterministički odgovori iz baze gde god je moguće
  (dostupnost, statistika, autori, žanrovi, FAQ), a jezički model samo tamo
  gde je potreban
- RAG pretraga kataloga preko zasebnog Python mikroservisa
  (vektorska KNN pretraga + FTS5/BM25, spojeno Reciprocal Rank Fusion-om)
- normalizacija srpskog jezika: ćirilica → latinica, dijakritici, padeži,
  provera pravopisa (Hunspell)
- lokalni modeli preko Ollame — podaci ne napuštaju server
- evaluacioni skup (golden / held-out pitanja) za merenje tačnosti odgovora

## Arhitektura

```
 Pregledač ──► Laravel (Blade + vanilla JS)
                 │
                 ├── SQLite baza (katalog, članovi, rezervacije, zaduženja)
                 │
                 └── AI asistent
                       ├── rutiranje namere ──► fiksni odgovori iz baze
                       ├── RAG mikroservis (Python / FastAPI)
                       │     normalizacija → embedding → sqlite-vec KNN + FTS5 → RRF
                       └── Ollama (lokalni LLM i embedding model)
```

## Tehnologije

| Sloj | Tehnologije |
|------|-------------|
| Backend | PHP 8.2, Laravel 12, SQLite, PhpSpreadsheet |
| Frontend | Blade, vanilla JavaScript, CSS, Vite |
| AI / RAG | Python, FastAPI, sqlite-vec, SQLite FTS5, langdetect, Hunspell (spylls), Ollama (`llama3.2:3b`, `nomic-embed-text`) |
| Testovi | PHPUnit (feature i unit testovi, ~400 testova) |
| Isporuka | Docker, Docker Compose, CI pipeline |

## Struktura projekta

```
backend/
├── app/
│   ├── Http/Controllers/    # kontroleri (katalog, rezervacije, zaduženja, AI chat…)
│   ├── Models/              # Eloquent modeli
│   ├── Services/            # poslovna logika (rezervacije, zaduženja, uvoz, obaveštenja)
│   │   └── Ai/              # AI asistent: rutiranje, pretraga kataloga, RAG klijent
│   └── Console/Commands/    # zakazani poslovi i alati (reindeksiranje, merenje)
├── database/                # migracije i seederi
├── resources/
│   ├── views/               # Blade šabloni
│   └── normalization/       # pravila normalizacije jezika (zajednička za PHP i Python)
├── rag_service/             # Python RAG mikroservis
└── tests/                   # PHPUnit testovi
docker-compose.yml           # app + scheduler + rag servis
```

## Pokretanje lokalno

Preduslovi: PHP 8.2+ (sa `intl` i `pdo_sqlite`), Composer, Node.js, a za AI
asistenta još Python 3.12 i [Ollama](https://ollama.com).

```bash
git clone https://github.com/banep33/atvss-library.git
cd atvss-library/backend

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
php artisan migrate --seed

php artisan serve
```

Na Windows-u isto rade skripte `setup.bat` i `start-dev.bat` iz korena projekta.

Aplikacija je dostupna na http://localhost:8000. Test nalozi se kreiraju
seederom (`database/seeders/UserSeeder.php`), npr. član `jovana@example.com` / `clan123`.

### AI asistent (opciono)

```bash
ollama pull llama3.2:3b
ollama pull nomic-embed-text

cd backend/rag_service
setup.bat        # pravi Python venv i instalira zavisnosti
start.bat        # pokreće RAG servis na portu 8001

cd ..
php artisan ai:reindex
```

Detalji su u [`backend/rag_service/README.md`](backend/rag_service/README.md),
a pravila ponašanja asistenta u [`backend/bib-pravila.md`](backend/bib-pravila.md).

## Testovi

```bash
cd backend
php artisan test
```

## Isporuka

Aplikacija se isporučuje kao Docker image (`backend/Dockerfile`,
`backend/rag_service/Dockerfile`) i pokreće preko `docker-compose.yml`.
Uputstvo za ručnu instalaciju na server je u [`DEPLOYMENT.md`](DEPLOYMENT.md).

## Autor

Bane Pavlović — završni rad, Akademija tehničko-vaspitačkih strukovnih studija, Niš.
