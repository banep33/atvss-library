<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama Base URL
    |--------------------------------------------------------------------------
    | Lokalna adresa na kojoj Ollama servis sluša. Default port je 11434.
    */
    'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),

    /*
    |--------------------------------------------------------------------------
    | Ollama Model
    |--------------------------------------------------------------------------
    | llama3.2:3b — generisanje odgovora iz RAG konteksta (pronađene knjige +
    | fiksni podaci o biblioteci u system promptu). Izbor za ograničene
    | resurse servera (CPU inferenca, bez jače grafičke): 8b modeli ne staju.
    | Ranije testirani: qwen2.5:3b (eval za_kod.md — testovi #19, #20, #22);
    | llama3.1:8b (izabran evaluacijom u eval/rezultati.db naspram qwen2.5:7b
    | i mistral:7b) se vraća kad server dobije jaču grafičku. Preduslov:
    |   ollama pull llama3.2:3b
    |
    | SVE opcije ispod su GENERIČKE Ollama opcije (nisu vezane za konkretan
    | model) — važe isto za qwen i za llama; menja se samo naziv modela.
    |
    | NAPOMENA: string mora biti bez razmaka i malim slovima. ' llama3.2:3b'
    | (sa razmakom) ili 'llama3.2:3B' daje 404 od Ollame — model name je
    | case-sensitive i whitespace-sensitive.
    */
    'model' => env('OLLAMA_MODEL', 'llama3.2:3b'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    | timeout: max vreme čekanja na kompletan odgovor (sinhroni poziv)
    |          ili na poslednji token (streaming).
    | connect_timeout: max vreme čekanja na TCP konekciju ka Ollami.
    */
    'timeout'         => env('OLLAMA_TIMEOUT',         180),
    'connect_timeout' => env('OLLAMA_CONNECT_TIMEOUT',  10),

    /*
    |--------------------------------------------------------------------------
    | Keep Alive
    |--------------------------------------------------------------------------
    | Koliko dugo Ollama drži model u RAM-u nakon poslednjeg zahteva.
    | '-1' = TRAJNO rezidentan → posle prvog preload-a (AppServiceProvider →
    | OllamaClient::warmup) nema više cold-starta tokom rada aplikacije.
    | Numerička vrednost se šalje kao broj sekundi (negativno = beskonačno);
    | string sa jedinicom (npr. '30m') kao duration.
    */
    'keep_alive' => env('OLLAMA_KEEP_ALIVE', '-1'),

    /*
    |--------------------------------------------------------------------------
    | Warmup
    |--------------------------------------------------------------------------
    | Ako je true, AppServiceProvider::boot() šalje preload zahtev Ollami
    | (chat model) i RAG mikroservisu (embed model) pre prvog pravog upita.
    | Postavi na false ako servisi nisu pokrenuti pri startu aplikacije.
    */
    'warmup_enabled' => env('OLLAMA_WARMUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Model Options
    |--------------------------------------------------------------------------
    | Prosleđuju se Ollama /api/chat kao 'options' parametar
    | (OllamaClient::buildOptions ih merge-uje sa per-request opcijama).
    |
    | temperature: 0.0 (deterministički) — odgovori moraju verno pratiti
    |   kontekst (pronađene knjige + podaci o biblioteci), ne kreativnost;
    |   niža temperatura smanjuje i skretanje u ijekavicu/engleski.
    | num_predict: max tokena po odgovoru. 700 — sa 300 su duži odgovori
    |   (spisak od 5 knjiga sa linkovima i autorima) bili PRESEČENI usred
    |   rečenice ("bot odjednom stane"); 700 pokriva i pun spisak i FAQ
    |   objašnjenje, a kratke odgovore ne produžava (model sam stane).
    | num_ctx: veličina kontekst prozora. 6144 — system prompt sadrži RAG
    |   kontekst (top 5 knjiga sa opisima), spisak žanrova, FAQ primere i
    |   istoriju razgovora; 4096 je umeo da bude tesan, a prelivanje ctx-a
    |   tera Ollamu da odbacuje početak prompta (gube se pravila).
    |   llama3.2 podržava i mnogo veći prozor (128k), ali 6144 ostaje zbog
    |   CPU inferencije — veći prozor = sporiji prefill bez dobiti za nas.
    | num_thread: broj CPU niti (8 = fizička jezgra Ryzen 7 5700U).
    | top_k: sampling samo iz top K tokena → brže, fokusiranije.
    | seed: fiksni seed → deterministični odgovori za isti prompt (uz nisku
    |   temperaturu). 0 = slučajan seed (Ollama default).
    */
    'options' => [
        'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.0),
        'num_predict' => (int)   env('OLLAMA_NUM_PREDICT', 700),
        'num_ctx'     => (int)   env('OLLAMA_NUM_CTX',    6144),
        'num_thread'  => (int)   env('OLLAMA_NUM_THREAD',    8),
        'top_k'       => (int)   env('OLLAMA_TOP_K',        20),
        'seed'        => (int)   env('OLLAMA_SEED',         42),
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG — mikroservis: ceo retrieval (pretprocesiranje + embedding + pretraga)
    |--------------------------------------------------------------------------
    | ARHITEKTURA (jedini tok):
    |   - Python servis (rag_service/, FastAPI) radi CEO retrieval u jednom
    |     pozivu (/retrieve): pretprocesiranje upita (ćirilica→latinica,
    |     dijakritici — zajednička mapa resources/normalization/, langdetect),
    |     embedding preko Ollame (nomic-embed-text) i HIBRIDNU pretragu:
    |     sqlite-vec KNN + FTS5/BM25 spojene RRF-om. Ostali endpointi:
    |     /embed (dokumenti za ai:reindex), /sync (rebuild indeksa).
    |   - KANONSKO SKLADIŠTE vektora: obična SQLite tabela `knjige_embeddings`
    |     (JSON kolona `embedding`, upisuje je samo Laravel) — mikroservis je
    |     čita read-only i iz nje gradi svoje indekse u sopstvenoj in-memory
    |     bazi (App\Services\Ai\VectorStore = samo skladištenje).
    |   Vidi rag_service/README.md.
    |
    | rag_service_url: adresa Python servisa (RAG_HOST/RAG_PORT u Python .env).
    | rag_service_key: opcioni Bearer ključ (= RAG_API_KEY u Python .env).
    | rag_timeout / rag_connect_timeout: kratki timeouti — ako servis nije
    |   dignut, chat nastavlja bez semantike (graceful, samo leksika).
    | rag_top_k: koliko kandidata /retrieve vraća (šalje se u zahtevu).
    | rag_min_score: min. cosine sličnost da SEMANTIČKI pogodak uđe u kontekst
    |   (leksički pogoci — naslov/autor/žanr — ne prolaze kroz ovaj prag).
    |   `score` u /retrieve hits je uvek pravi cosine (RRF samo redosled), pa
    |   prag zadržava značenje. Kalibrisano za nomic-embed-text nad OČIŠĆENIM
    |   upitom (mikroservis pre embedovanja skida upitni šablon "da li
    |   imate..."): pravi tematski pogoci ≈ 0.66+, šum fonda ≈ 0.55–0.63 →
    |   0.64 seče šum.
    */
    /*
    |--------------------------------------------------------------------------
    | FAQ primeri (few-shot)
    |--------------------------------------------------------------------------
    | Putanja do markdown fajla sa zvaničnim P/O parovima o biblioteci
    | (few-shot-pitanja-atvss.md). Za FAQ pitanja se najrelevantniji parovi
    | ubacuju u system prompt kao jedini izvor formulacija — model odgovara
    | dosledno primerima umesto da parafrazira iz opšteg znanja.
    */
    'faq_primeri_path' => env('AI_FAQ_PRIMERI_PATH', base_path('few-shot-pitanja-atvss.md')),

    // Kurirani FAQ sinonimi (Faza B′) — deterministička generalizacija leksičkog
    // FaqZnanje bez modela/hardvera. Prazna putanja = sloj isključen.
    'faq_sinonimi_path' => env('AI_FAQ_SINONIMI_PATH', base_path('resources/normalization/faq-sinonimi.json')),

    // Jezički guard: izlazna provera generisanog odgovora Hunspell rečnikom
    // (mikroservis /proveri-tekst) — odbija odgovor sa previše ne-srpskih reči.
    // Podrazumevano OFF; fail-open (servis/rečnik nedostupan → ne blokira).
    // Radi tek kada je u rag_service/resources/hunspell/ prisutan sr-Latn rečnik.
    'jezicki_guard' => (bool) env('AI_JEZICKI_GUARD', false),

    /*
    |--------------------------------------------------------------------------
    | EVAL ruta (Faza D) — brzo puštanje golden/held-out seta bez throttle-a
    |--------------------------------------------------------------------------
    | eval_enabled: kada je true, u routes/api.php se registruje eval-only ruta
    |   POST /api/ai/eval/chat (isti pipeline kao guest, ali BEZ throttle:5,1),
    |   da harness (eval/eval_app.py) provuče ceo set za par minuta umesto ~7.
    |   PODRAZUMEVANO false — u produkciji ruta NE postoji (nula napadne
    |   površine); upali se samo u lokalnom .env-u.
    | eval_token: deljeni tajni token; harness ga šalje kao X-Eval-Token, a
    |   EnsureEvalToken ga poredi hash_equals-om (timing-safe). CSRF ostaje.
    */
    'eval_enabled' => (bool) env('AI_EVAL_ENABLED', false),
    'eval_token'   => (string) env('AI_EVAL_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Naučeni primeri (Faza B) — semantičko preuzimanje FAQ parova
    |--------------------------------------------------------------------------
    | nauceni_primeri: kada je true, chat pored leksičkog FaqZnanje dohvata i
    |   SEMANTIČKI najsličnije naučene P/O parove (tabela nauceni_primeri,
    |   embedding upita) i ubacuje ih u system prompt — hvata parafraze koje
    |   leksičko promaši. PODRAZUMEVANO false (A/B: eval se pušta sa i bez).
    |   Puni ga `php artisan bot:nauci`.
    | nauceni_prag: min. cosine sličnost da naučeni par uđe u prompt. 0.75 —
    |   dovoljno visoko da ne ubacuje nerelevantne parove (troše num_ctx),
    |   dovoljno nisko da hvata parafraze. Kalibrisati na held-out setu.
    */
    'nauceni_primeri' => (bool)  env('AI_NAUCENI_PRIMERI', false),
    'nauceni_prag'    => (float) env('AI_NAUCENI_PRAG',    0.75),
    // Najviše naučenih parova u prompt. nomic nad srpskim „zbija" cosine (sve
    // ~0.80), pa je malo N (1–2) čistiji signal od 3 (izbegava šum parove).
    'nauceni_max'     => (int)   env('AI_NAUCENI_MAX',     2),

    /*
    | Semantički DIREKTAN odgovor (preporučeni put za 3b/CPU): jaka parafraza
    | kuriranog para → vrati odgovor para DIREKTNO, bez modela. Rasterećuje
    | hardver (fiksni odgovor umesto inferencije) i ne zavisi od (ne)sposobnosti
    | slabog modela da iskoristi primer u promptu.
    | direktno:  uključeno (default true — smanjuje opterećenje).
    | prag:      min. cosine da par uopšte bude kandidat (0.85 — visoko, kurirani
    |            odgovor mora da bude siguran pogodak).
    | margina:   koliko top mora da nadmaši DRUGI par (0.03) — kod „zbijenih"
    |            skorova (npr. 0.845 vs 0.823) NE okida, da ne vrati pogrešan FAQ.
    */
    'nauceni_direktno'         => (bool)  env('AI_NAUCENI_DIREKTNO',         false),
    'nauceni_direktno_prag'    => (float) env('AI_NAUCENI_DIREKTNO_PRAG',    0.85),
    'nauceni_direktno_margina' => (float) env('AI_NAUCENI_DIREKTNO_MARGINA', 0.03),

    'rag_service_url'      => env('RAG_SERVICE_URL', 'http://127.0.0.1:8001'),
    'rag_service_key'      => env('RAG_SERVICE_KEY', ''),
    'rag_timeout'          => (int)   env('RAG_TIMEOUT',         15),
    'rag_connect_timeout'  => (int)   env('RAG_CONNECT_TIMEOUT',  3),
    'rag_top_k'            => (int)   env('AI_RAG_TOP_K',         5),
    'rag_min_score'        => (float) env('AI_RAG_MIN_SCORE',     0.64),

];
