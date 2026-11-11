# Plan: sistem za treniranje chatbota iz označenih razgovora

**Projekat:** ATVSS biblioteka chatbot (Laravel + FastAPI RAG + Ollama qwen2.5:7b + SQLite)
**Cilj rada:** dokazati merljiv napredak bota kroz tri stanja — **pre treniranja → tokom → posle** — na osnovu ručnog označavanja odgovora (dobar/loš + ispravka), uz poređenje kvaliteta srpskog jezika više modela (qwen, mistral, gemma…).

---

## 0. Ključna inženjerska odluka

„Treniranje" se izvodi u **dve tehnike, u fazama**, jer svaka pokriva drugu tačku merenja u radu:

| Tehnika | Šta menja | GPU | Tačka merenja u radu |
|---|---|---|---|
| **Dinamički few-shot** (Faza 2) | prompt — dobri odgovori postaju primeri | ne | „tokom treniranja" |
| **Fine-tuning: SFT + DPO** (Faza 3) | težine modela (LoRA adapter) | Colab T4 (besplatno) | „posle treniranja" |

Obe tehnike jedu **isti dataset** — tvoje oznake iz Faze 1. Zato feedback sistem ide prvi,
a evaluacioni okvir (Faza 0) ide **pre svega**, inače nemaš baseline „pre".

```
Faza 0: evaluacioni okvir + baseline merenje   ← PRE (ništa se ne menja u botu)
Faza 1: feedback sistem (označavanje u adminu)  ← prikupljanje podataka
Faza 2: dinamički few-shot iz dobrih odgovora   ← TOKOM (bot uči bez treninga)
Faza 3: SFT + DPO fine-tuning na Colabu         ← POSLE (novi model u Ollami)
Faza 4: finalna evaluacija + poređenje modela   ← tabele i grafikoni za rad
```

---

## Faza 0 — Evaluacioni okvir i baseline (OBAVEZNO PRVO)

Bez zamrznutog testnog seta i automatskog pokretanja, poređenje pre/tokom/posle je nenaučno.

### 0.1 Testni set pitanja (golden set)

- Fajl: `tests/eval/testna-pitanja.json` — **zamrznut posle kreiranja, ne menja se nikad**.
- 60–100 pitanja, izvor: `few-shot-pitanja-atvss.md` + `pitanja-za-katalog.md` + realna pitanja iz `ai_messages`.
- Svako pitanje ima: `id`, `tekst`, `kategorija` (FAQ / katalog-leksički / katalog-semantički / strukturni / van-teme / anti-halucinacija), `tip_korisnika` (guest/član), `ocekivani_odgovor` (referentni tačan odgovor) i `ocekivane_cinjenice` (lista stringova koji MORAJU biti u odgovoru, npr. „08:00 do 16:00").
- Podeliti: ~70% **eval set** (samo merenje) i ~30% pitanja sličnog tipa sme da se pojavi u treningu — pitanja iz eval seta NIKAD ne ulaze u trening (data leakage = nevalidan rad).

### 0.2 Harness za pokretanje

- Artisan komanda: `php artisan eval:run {naziv_runa}` — vrti sva pitanja kroz postojeći pipeline (`AiChatService`), snima u novu tabelu `eval_runs` / `eval_answers` (run, pitanje_id, odgovor, latencija_ms, model, git commit hash, datum).
- Zbog `temperature: 0` + fiksni `seed` odgovori su reproducibilni — to eksplicitno navedi u radu.

### 0.3 Metrike (kombinuj automatske i ručne)

1. **Tačnost činjenica** (automatski): % `ocekivane_cinjenice` prisutnih u odgovoru (normalizovan tekst preko postojećeg `TextUtils`).
2. **Halucinacija** (automatski + ručno): pominje li odgovor knjigu/autora/žanr koji ne postoji u katalogu (proveri linkove `/knjige/BROJ` protiv baze).
3. **Kvalitet srpskog** (ručno, skala 1–5): gramatika, ekavica (ne ijekavica), bez engleskih upada, persiranje.
4. **Relevantnost i dužina** (ručno 1–5): odgovara li na pitanje, poštuje li „zlatno pravilo dužine".
5. **LLM-as-judge** (opciono, jak dodatak za rad): veći model (npr. GPT-4o ili Claude preko API-ja) ocenjuje svaki odgovor po rubrici 1–3, pa u radu prikažeš korelaciju sa tvojim ručnim ocenama.

### 0.4 Baseline

`eval:run baseline-qwen25` **pre bilo kakve izmene koda**. Ovo je kolona „pre" u svim tabelama rada.

---

## Faza 1 — Feedback sistem (označavanje odgovora)

### 1.1 Migracija: `ai_message_feedback`

```php
Schema::create('ai_message_feedback', function (Blueprint $table) {
    $table->id();
    $table->foreignId('message_id')->unique()->constrained('ai_messages')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users');   // ko je označio (ti/bibliotekar)
    $table->boolean('dobar');                              // palac gore/dole
    $table->text('ispravka')->nullable();                  // kako je odgovor TREBALO da glasi
    $table->string('razlog')->nullable();                  // enum: netacno|halucinacija|los_jezik|predugacko|van_teme
    $table->boolean('u_treningu')->default(false);         // da li je već ušao u neki trening run
    $table->timestamps();
});
```

`ispravka` je najvredniji podatak: daje ti idealne parove *(pitanje, loš odgovor, dobar odgovor)* — direktno gorivo i za few-shot i za DPO.

### 1.2 API (admin-only rute, uz postojeće `ai/` rute u `routes/api.php`)

- `POST /api/ai/feedback` — `{message_id, dobar, ispravka?, razlog?}`
- `GET  /api/ai/feedback/pregled` — lista neoznačenih assistant poruka sa pitanjem i RAG kontekstom
- `GET  /api/ai/feedback/export?format=sft|dpo` — export dataseta (videti 3.1)

### 1.3 UI za označavanje

Dve tačke, obe jeftine jer front već postoji:

1. **U chat widgetu**: 👍/👎 pored svake assistant poruke (samo za admin rolu) → brzo označavanje tokom realnog korišćenja.
2. **Admin stranica „Treniranje bota"** (uz postojeći `Admin/AdminController` obrazac): tabela razgovora, filtriranje neoznačenih, inline forma za ispravku. Tu i dugme „Export dataseta" i brojači (koliko dobrih / loših / ispravljenih — te brojke idu u rad).

### 1.4 Snimati kontekst uz poruku (bitno!)

Proširi `ai_messages` (nova migracija): `rag_kontekst` (JSON — koje knjige je retrieval vratio), `namera` (rezultat `IntentClassifier`), `model` (string). Bez ovoga ne možeš u radu da analiziraš ZAŠTO je odgovor bio loš (loš retrieval vs. loša generacija) — a to je poenta poglavlja o analizi grešaka.

---

## Faza 2 — Dinamički few-shot („bot uči tokom rada", bez GPU)

Ideja: odobreni odgovori (dobri + ispravke) postaju **živa baza primera** koja se semantički pretražuje i ubacuje u system prompt — potpuno analogno postojećem `FaqZnanje::relevantni()`, samo nad naученim parovima.

### 2.1 Implementacija

1. Nova tabela `nauceni_primeri`: `pitanje`, `odgovor` (dobar ili ispravka), `embedding` (JSON, 768-dim `nomic-embed-text` — isti postojeći pipeline kao `knjige_embeddings`), `izvor` (feedback_id), `aktivan`.
2. Job/komanda `php artisan bot:nauci` — pokupi novi feedback (dobar=true ili ispravka≠null), embeduje pitanja preko postojećeg `RagClient`, upiše u `nauceni_primeri`, označi `u_treningu=true`.
3. U `SystemPromptService`: nova sekcija `sekcijaNauceniPrimeri()` — top 3 primera po kosinusnoj sličnosti sa tekućim pitanjem (isti mehanizam kao `VectorStore`), format identičan postojećem `sekcijaFaqPrimeri()`. Prag sličnosti ~0.75 da ne ubacuješ nerelevantne primere (trošе `num_ctx`).
4. Feature flag u `config/ai.php`: `'nauceni_primeri' => env('AI_NAUCENI_PRIMERI', true)` — da eval možeš da pokrećeš sa i bez (A/B poređenje u radu!).

### 2.2 Merenje „tokom"

Posle npr. 100+ označenih odgovora: `eval:run tokom-fewshot` (flag ON) i `eval:run tokom-kontrola` (flag OFF). Razlika između njih je čist doprinos ovog mehanizma — savršena tabela za rad.

---

## Faza 3 — Fine-tuning: SFT + DPO (Colab T4, besplatno)

### 3.1 Priprema dataseta (export iz Laravela)

`eval:export` generiše dva JSONL fajla iz feedback tabele:

**SFT (supervised fine-tuning)** — svi dobri + ispravljeni odgovori, ChatML format:
```json
{"messages": [
  {"role": "system", "content": "<SKRAĆENI system prompt — stil + pravila, BEZ živog RAG konteksta>"},
  {"role": "user", "content": "Kada radi biblioteka?"},
  {"role": "assistant", "content": "Biblioteka radi radnim danima od 08:00 do 16:00, vikendom ne radimo."}
]}
```

**DPO (preference pairs)** — gde postoji loš odgovor + ispravka:
```json
{"prompt": "...pitanje...", "chosen": "...ispravka...", "rejected": "...loš odgovor bota..."}
```

Pravila:
- U trening idu i RAG primeri: u `user` deo ubaci i kontekst pronađenih knjiga kakav model realno dobija — model uči da **koristi kontekst**, ne da pamti katalog (katalog se menja, težine ne smeju da ga „znaju").
- Pitanja iz eval seta se **isključuju** (leakage).
- Realan cilj: **150–300 SFT primera, 50–100 DPO parova**. Ispod toga fine-tuning 7B modela ne pokazuje stabilan pomak — planiraj 2–3 nedelje označavanja.

### 3.2 Trening (Unsloth notebook, Colab T4)

- Unsloth ima gotove Colab notebooke za Qwen 2.5 7B: QLoRA (4-bit) staje u 15 GB VRAM T4, trening je ~2× brži uz ~60–70% manje memorije; podržan je i DPO ([Unsloth notebooks](https://unsloth.ai/docs/get-started/unsloth-notebooks), [Qwen docs — Unsloth](https://qwen.readthedocs.io/en/latest/training/unsloth.html), [unslothai/unsloth](https://github.com/unslothai/unsloth/)).
- Redosled: **prvo SFT** (nauči stil/format/činjenice o biblioteci), **pa DPO** nad SFT adapterom (nauči preferencije: kratko > dugačko, bez halucinacije).
- Okvirni hiperparametri (polazna tačka, dokumentuj u radu): LoRA r=16, alpha=16, lr=2e-4 (SFT) / 5e-6 (DPO), 2–3 epohe, max_seq_len 2048.
- Export: merge adaptera → **GGUF (q4_k_m)** → download → lokalno:
  ```
  # Modelfile
  FROM ./atvss-qwen25-ft.q4_k_m.gguf
  ollama create atvss-bot -f Modelfile
  ```
- U `.env`: `OLLAMA_MODEL=atvss-bot`. **Nula izmena u PHP kodu** — cela integracija je konfiguraciona.

### 3.3 Merenje „posle"

`eval:run posle-ft` (few-shot flag OFF, da meriš čist efekat treninga) i `eval:run posle-ft-plus-fewshot` (obe tehnike zajedno — verovatno najbolji rezultat, finalna konfiguracija).

---

## Faza 4 — Poređenje modela za srpski jezik

Isti eval harness, samo menjaš `OLLAMA_MODEL` (svaki run je jedna komanda):

| Model | `ollama pull` | Zašto u poređenju |
|---|---|---|
| qwen2.5:7b | postojeći | baseline / trenutni izbor |
| mistral:7b | `mistral:7b-instruct` | profesorov predlog; osnova YugoGPT-a |
| gemma2:9b (ili gemma3) | `gemma2:9b` | Google, često dobar na južnoslovenskim |
| YugoGPT 7B | GGUF sa HuggingFace | jedini specijalizovan za srpski/HBS ([YugoGPT](https://huggingface.co/gordicaleksa/YugoGPT)) |
| atvss-bot (tvoj FT) | lokalni | dokaz doprinosa rada |

- Za svaki: `eval:run model-<naziv>` → iste metrike, posebno **kvalitet srpskog** (ijekavica/engleski upadi su najčešći problem kod ne-specijalizovanih modela).
- U radu citiraj postojeće benchmarke kao kontekst: [Serbian LLM Eval (Gordić)](https://github.com/gordicaleksa/serbian-llm-eval), [OZ-Eval](https://huggingface.co/datasets/datatab/serbian-llm-benchmark) — tvoja evaluacija je domenska (biblioteka), njihova opšta; to razgraničenje je dobar paragraf u metodologiji.
- Pazi: veći modeli (9B+) na Ryzen 7 5700U CPU-u biće spori — beleži latenciju kao metriku, to je legitimna inženjerska dimenzija poređenja.

---

## Redosled implementacije i procena

| # | Korak | Procena |
|---|---|---|
| 1 | Faza 0: testni set + `eval:run` + baseline | 3–4 dana |
| 2 | Faza 1: migracije + API + admin UI za označavanje | 3–5 dana |
| 3 | Prikupljanje oznaka (paralelno sa svim ostalim) | 2–3 nedelje kalendarski |
| 4 | Faza 2: `nauceni_primeri` + prompt sekcija + eval „tokom" | 2–3 dana |
| 5 | Faza 3: export dataseta + Colab SFT/DPO + GGUF + eval „posle" | 4–6 dana (iteracije) |
| 6 | Faza 4: poređenje modela | 2 dana (uglavnom čekanje CPU inferencije) |

## Rizici

1. **Premalo oznaka za fine-tuning** → Faza 2 (few-shot) je nezavisno kompletan rezultat; fine-tuning i sa 100 primera pokazuje pomak u stilu, samo to pošteno prikaži.
2. **Catastrophic forgetting** (FT model zaboravi opšte srpsko znanje) → zato DPO uz nizak lr i malo epoha; eval set hvata regresije.
3. **Colab prekine sesiju** → čuvaj checkpoint na Google Drive svakih N koraka.
4. **Testni set „procuri" u trening** → export komanda mora hard-koditi listu eval ID-jeva i odbiti ih.