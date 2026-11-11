# Bib — specifikacija ponašanja i arhitekture

Prečišćena specifikacija chatbota "Bib", asistenta biblioteke ATVSS.
Usklađena sa refaktorisanjem od 2026-07-05: JEDAN RAG pipeline
(pretprocesiranje → embedding → vektorska pretraga → kontekst → qwen2.5:7b),
bez determinističkih FAQ/katalog slojeva iz ranijih iteracija.

------------------------------------------------------------
ULOGA
------------------------------------------------------------

Bib je asistent biblioteke Akademije tehničko-vaspitačkih strukovnih studija
(ATVSS) u Nišu. Pomaže članovima i gostima oko kataloga knjiga i informacija
o biblioteci (radno vreme, pravila, rezervacije).

Ključni princip: **model odgovara ISKLJUČIVO iz prosleđenog konteksta** —
pronađenih knjiga (RAG) i fiksnih podataka o biblioteci u system promptu.
Ništa se ne izmišlja; kada kontekst nije dovoljan, Bib to kaže.

------------------------------------------------------------
RAG PIPELINE (jedini tok)
------------------------------------------------------------

```
Korisnik postavlja pitanje
        ↓
Pretprocesiranje (Python mikroservis, deterministički):
  1. ćirilica → latinica (transliteracija)
  2. normalizacija dijakritika (ć/č → c, š → s, ž → z, đ → dj) + mala slova
  3. detekcija jezika (langdetect biblioteka, ne heuristika)
        ↓  nije srpski → "Nisam Vas razumeo" — KRAJ (model se ne poziva)
Embedding pitanja (Ollama nomic-embed-text, prefiks "search_query:")
        ↓
Pretraga vektorske baze (tabela knjige_embeddings, cosine KNN u PHP-u)
        ↓
Top 5 rezultata (sa score-om ≥ ai.rag_min_score) + hidracija iz baze
(naziv, autor, žanrovi, opis, broj slobodnih primeraka, link)
        ↓
Formiranje konteksta → JEDAN system prompt (pravila + podaci o biblioteci
+ pronađene knjige)
        ↓
Qwen2.5:7B (Ollama, SSE streaming)
        ↓
Odgovor (transliteracija izlaza u latinicu + rezervni guard za strani jezik)
```

Ista normalizacija (korak 2) primenjuje se i na tekst knjiga pri
indeksiranju (`ai:reindex` i automatski reindeks iz modela `Knjiga`) — zato
pretraga radi i bez kvačica i za ćirilične upite.

------------------------------------------------------------
PRAVILA PONAŠANJA (system prompt)
------------------------------------------------------------

1. Odgovara jasno i prirodno; UVEK persira ("Vi").
2. Nikada ne spominje da je AI, model, Ollama ili LLM.
3. Isključivo srpski jezik u latinici, ekavica. Ulaz na ćirilici se razume,
   odgovor je uvek latinica.
4. Poruka na stranom jeziku → tačno: "Nisam Vas razumeo" (deterministički,
   langdetect u mikroservisu; prompt je rezervni sloj).
5. Tipfeleri i tekst bez kvačica se razumeju (normalizacija pre embeddinga
   + uputstvo u promptu).
6. Odgovara isključivo iz konteksta (pronađene knjige + podaci o
   biblioteci); kada informacije nema → "Nemam tu informaciju…" + kontakt
   bibliotekara. Ne halucinira.
7. Svaka pomenuta knjiga se citira kao link `[Naziv knjige](/knjige/ID)`,
   sa autorom; dostupnost samo iz podatka "slobodno primeraka" u kontekstu.
8. Odgovori su kratki, prirodni, bez markdown formatiranja (osim linkova).

------------------------------------------------------------
KOMPONENTE
------------------------------------------------------------

Laravel (`app/Services/Ai/`):
- `AiChatService`  — orkestracija pipeline-a (retrieveContext →
  buildMessages → Ollama) + perzistencija razgovora.
- `RagClient`      — HTTP ka Python mikroservisu (/query, /embed, /health).
- `RagService`     — buildIndexText (naziv+autor+žanrovi+opis), reindex,
  reindexMany, removeFromIndex, pretrazi(vektor) → top 5 {knjiga, score}.
- `VectorStore`    — upsert/cosine KNN (PHP) nad tabelom `knjige_embeddings`.
- `SystemPromptService` — JEDAN prompt: pravila + podaci o biblioteci +
  RAG kontekst.
- `OllamaClient`   — chat/stream/warmup ka qwen2.5:7b.
- `TextUtils`      — transliteracija izlaza, rezervni guard stranog
  odgovora, injection zaštita.

Python mikroservis (`rag_service/`, FastAPI :8001):
- `/query` — pretprocesiranje (translit + dijakritici + langdetect) +
  embedding upita; `/embed` — normalizacija + embedding dokumenata.
- Embedding preko Ollama `/api/embed`, model `nomic-embed-text` (dim 768,
  prefiksi search_query/search_document). Bez torch-a — venv ~30MB.

Automatsko indeksiranje: model `Knjiga` na `saved` → reindex, na `deleted`
→ uklanjanje iz indeksa (greška se loguje, CRUD ne pada). Sync žanrova
(pivot) ne okida evente → pozivaoci rade `touch()`. Excel uvoz pauzira
auto-reindeks i radi jedan bulk reindeks na kraju. `ai:reindex` radi pun
rebuild (uklanja i vektore obrisanih knjiga).

------------------------------------------------------------
ARHITEKTURA I OTPORNOST
------------------------------------------------------------

- SSE streaming (fetch + ReadableStream; session lock se otpušta pre
  streama; `X-Conversation-Id` header).
- Istorija razgovora: `ai_conversations`/`ai_messages` SAMO za ulogovane
  članove, uz strogu `user_id` izolaciju.
- Rate limiting: 10/min član, 5/min gost.
- Graceful degradacija: pad mikroservisa ili nedostatak vektorske tabele ne obara chat —
  model dobija prazan kontekst (opšta pitanja i dalje radi iz podataka u
  promptu, za katalog kaže da nema pogodaka).
- Ollama opcije (Ryzen 7 5700U): num_predict 300, num_ctx 4096 (prompt
  nosi RAG kontekst), num_thread 8, keep_alive -1, temperature 0.05.
- Warmup na boot: qwen2.5:7b + nomic-embed-text/langdetect (fire-and-forget).

------------------------------------------------------------
BEZBEDNOST
------------------------------------------------------------

- Prompt injection: regex gard na ulazu + blokada `system` role kroz history.
- AI sloj ima isključivo READ pristup katalogu — jedini upis je sopstvena
  istorija razgovora.
- Post-provera izlaza: transliteracija + detekcija stranog odgovora (retry).

------------------------------------------------------------
ISTORIJAT ODLUKA
------------------------------------------------------------

- Ranije iteracije su imale determinističke slojeve (CatalogQueryService,
  FAQ regex + semantika, off-topic/unclear gardovi) kao protezu za slabosti
  malog modela (qwen2.5:3b). Prelaskom na qwen2.5:7b i čist RAG pipeline
  (2026-07-05) ti slojevi su uklonjeni — jedan tok, bez paralelnih puteva.
- Embedding je prešao sa multilingual-e5-small (sentence-transformers u
  Pythonu) na nomic-embed-text (preko Ollame) po planu implementacije;
  dimenzija 384 → 768, tabela `vec_knjige` → `knjige_embeddings`.
- sqlite-vec (vec0) je napušten jer PDO loadExtension na ZTS PHP build-u
  (standardni Windows PHP) ne radi van CLI-ja — vektorska pretraga nikada
  nije radila pod `php artisan serve`. Zamena: obična tabela + cosine KNN
  u PHP-u (za lokalni fond < 50ms), radi u svakom okruženju.
- Rangiranje popularnosti preko Google Books (popularnost_* kolone,
  ai:rangiraj-popularnost) uklonjeno je zajedno sa determinističkim
  preporukama.
