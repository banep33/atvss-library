# Bib RAG mikroservis

Retrieval sloj chatbota biblioteke ATVSS: pretprocesiranje jezika +
embedovanje + hibridna pretraga (sqlite-vec KNN + FTS5/BM25, spojeno RRF-om).
Laravel bazu čita **isključivo read-only** — svi upisi ostaju u Laravelu.

Uloga u pipeline-u (semantička pretraga — koristi se kad pitanje ne može da se odgovori fiksnom rutom iz baze):

```
Korisnik → Laravel → [ovaj servis /retrieve]
                        1. ćirilica → latinica + normalizacija dijakritika
                           (pravila: ../resources/normalization/normalization-map.json,
                            ISTI izvor koristi i PHP TextUtils)
                        2. detekcija jezika (langdetect) — nije srpski → ok:false
                        3. embedding upita (Ollama, nomic-embed-text)
                        4. hibridna pretraga: vec0 KNN + FTS5 (koren-prefiks
                           upiti za padeže) → RRF spajanje
         Laravel ← top-K {id, score(cosine), source} — prag i domenska
                   leksika (score 1.0) ostaju u Laravelu
         Laravel → Ollama (llama3.2:3b) sa pronađenim knjigama u system promptu
```

Indeksi (vec0 + FTS5) žive u in-memory bazi ovog servisa i grade se iz
Laravel tabela `knjige_embeddings` ⋈ `knjige`. Svežina: fingerprint tabela
se proverava pre svake pretrage (automatski rebuild), a `ai:reindex` posle
upisa zove i izričit `/sync`.

## Pokretanje (Windows)

1. `ollama pull nomic-embed-text` — jednom (embedding model, ~270MB).
2. `setup.bat` — jednom (pravi `venv`, instalira zavisnosti, kopira `.env`).
3. `start.bat` — svaki put (servis: `http://127.0.0.1:8001`).

Provera: otvori `http://127.0.0.1:8001/health` — `indexed` mora biti > 0.

## Vektorska tabela (na Laravel strani)

1. `php artisan migrate` — kreira običnu tabelu `knjige_embeddings`
   (knjiga_id, embedding JSON, L2-normalizovan pri upisu) — KANONSKO
   skladište iz koga ovaj servis gradi svoje indekse.
2. `php artisan ai:reindex` — embeduje sve knjige. Pri dodavanju/izmeni/
   brisanju knjige model `Knjiga` automatski ažurira tabelu, a servis
   promenu sam otkriva (fingerprint).

## Endpoints

| Metoda | Putanja     | Opis                                                      |
|--------|-------------|-----------------------------------------------------------|
| GET    | `/health`   | status, embed model, dimenzija, broj indeksiranih vektora |
| POST   | `/retrieve` | `{message, history?, top_k?, fuse?}` → `{ok, text, clean, hits:[{id, score, rrf, source}], dim}` ili `{ok:false, reason}`; `fuse:false` = samo semantika (za A/B merenja) |
| POST   | `/sync`     | izričit rebuild vec0 + FTS5 indeksa → `{ok, indexed}`     |
| POST   | `/embed`    | `{texts:[...]}` → normalizacija + embed dokumenata: `{vectors, dim, model}` |

nomic-embed-text zahteva prefikse — servis ih dodaje sam
(`search_query:` za upite, `search_document:` za dokumente).

`score` u hits je uvek PRAVI cosine (RRF određuje samo redosled) — prag
`ai.rag_min_score` u Laravelu zato zadržava značenje.

## Konfiguracija

Vidi `.env.example`: `RAG_HOST`/`RAG_PORT` (uskladi sa `RAG_SERVICE_URL` u
Laravel `.env`), `OLLAMA_URL`, `RAG_EMBED_MODEL`/`RAG_EMBED_DIM`
(nomic-embed-text = 768), `LARAVEL_DB` (podrazumevano
`../database/database.sqlite`), `RAG_CAND_K`/`RAG_RRF_K` (hibrid),
`NORMALIZATION_MAP` (podrazumevano zajednička mapa u `../resources`).

## Detekcija jezika — napomena

`langdetect` nema profil za srpski ('sr'), pa se srpska latinica najčešće
detektuje kao hr/sl/bs — ti profili se prihvataju kao srpski. Srpska ćirilica
i dijakritici su direktan dokaz srpskog. Odbacuje se samo visoko-pouzdana
detekcija stranog jezika (en, de, ru...), a system prompt je rezervni sloj.
