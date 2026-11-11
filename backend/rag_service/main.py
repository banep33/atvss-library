"""
Bib RAG mikroservis — pretprocesiranje jezika + embedovanje (nomic-embed-text).
================================================================================

ULOGA U PIPELINE-U (jedini tok):
  [Laravel] --/retrieve--> [ovaj servis :8001] --/api/embed--> [Ollama :11434]

  1. PRETPROCESIRANJE (deterministički, bez LLM-a):
     - transliteracija ćirilice u latinicu,
     - normalizacija dijakritika (ć/č → c, š → s, ž → z, đ → dj) + mala slova
       (pravila u zajedničkom normalization-map.json — isti izvor kao PHP),
     - detekcija jezika bibliotekom `langdetect` (ne heuristikom po rečima).
       Ako upit nije srpski → {"ok": false} i Laravel prekida pipeline
       porukom "Nisam Vas razumeo".
  2. EMBEDOVANJE preko Ollama /api/embed, model `nomic-embed-text` (dim 768).
     nomic zahteva prefikse: upit "search_query: ...", dokument
     "search_document: ...". ISTA normalizacija se primenjuje i na upit i na
     tekst knjiga (ai:reindex), da pretraga radi bez obzira na dijakritike.
  3. HIBRIDNA PRETRAGA nad indeksima izgrađenim iz Laravel baze (read-only):
     sqlite-vec (vec0) KNN + FTS5/BM25 nad naslov/autor/opis (koren-prefiks
     upiti za padeže), spojeni Reciprocal Rank Fusion-om — sirovi top-K
     {id, score(cosine), source} bez praga; prag relevantnosti i domenski
     leksički sloj ostaju u Laravelu.

  KANONSKO skladište vektora je Laravel tabela `knjige_embeddings`; ovaj
  servis iz nje gradi sqlite-vec (vec0) KNN indeks u zasebnoj in-memory
  bazi (database.sqlite se čita ISKLJUČIVO read-only) i radi celu
  semantičku pretragu za Laravel (/retrieve).

ENDPOINTI:
  GET  /health   — status + model + dimenzija + broj indeksiranih vektora
  POST /retrieve — {message, history?, top_k?} → normalizacija + langdetect
                   + embed + vec0 KNN → {ok, text, clean, hits, dim}
                   ili {ok: false, reason}
  POST /sync     — rebuild vec0 indeksa iz knjige_embeddings (posle ai:reindex)
  POST /embed    — {texts: [...]} → normalizacija + embed dokumenata
                   {vectors, dim, model}

POKRETANJE (Windows):
  1. ollama pull nomic-embed-text   (jednom)
  2. setup.bat                      (jednom — venv + zavisnosti)
  3. start.bat                      (svaki put — http://127.0.0.1:8001)

NAPOMENA o langdetect-u: biblioteka nema profil za 'sr', pa se srpska latinica
najčešće detektuje kao 'hr'/'sl'/'bs'. Zato: srpska ćirilica i dijakritici su
direktan dokaz srpskog; za ostalo se prihvataju južnoslovenski profili, a
odbacuje se samo visoko-pouzdana detekcija stranog jezika (en, de, ru...).
Lažno prihvatanje nije problem — system prompt je rezervni sloj.
"""

from __future__ import annotations

import hmac
import json
import os
import re
import sqlite3
import threading
from contextlib import asynccontextmanager
from pathlib import Path

import requests
import sqlite_vec
from dotenv import load_dotenv
from fastapi import FastAPI, Header, HTTPException
from langdetect import DetectorFactory, detect_langs
from langdetect.lang_detect_exception import LangDetectException
from pydantic import BaseModel

# ──────────────────────────────────────────────────────────────────────────────
# KONFIGURACIJA
# ──────────────────────────────────────────────────────────────────────────────
BASE_DIR = Path(__file__).resolve().parent
load_dotenv(BASE_DIR / ".env")

OLLAMA_URL  = os.getenv("OLLAMA_URL", "http://127.0.0.1:11434").rstrip("/")
EMBED_MODEL = os.getenv("RAG_EMBED_MODEL", "nomic-embed-text")
EMBED_DIM   = int(os.getenv("RAG_EMBED_DIM", "768"))
API_KEY     = os.getenv("RAG_API_KEY", "")  # prazno = bez auth (lokalni dev)

# Laravel SQLite baza — čita se ISKLJUČIVO read-only (mode=ro): svi upisi
# (knjige, knjige_embeddings, razgovori) ostaju u Laravelu.
LARAVEL_DB = Path(os.getenv(
    "LARAVEL_DB",
    str(BASE_DIR.parent / "database" / "database.sqlite"),
))

# Podrazumevani top-K za /retrieve (Laravel šalje svoj ai.rag_top_k u zahtevu).
RETRIEVE_TOP_K = int(os.getenv("RAG_TOP_K", "5"))

# Hibridna pretraga: veličina kandidatskih bazena (semantika i leksika) pre
# RRF spajanja, i RRF konstanta k (standardnih 60 — veće k = blaži uticaj ranga).
CAND_K = int(os.getenv("RAG_CAND_K", "20"))
RRF_K  = int(os.getenv("RAG_RRF_K", "60"))

# Jezički guard (izlazna provera odgovora Hunspell rečnikom + whitelist iz
# kataloga). Prazna/nepostojeća putanja rečnika = guard onemogućen (vraća ok).
HUNSPELL_DIR = Path(os.getenv(
    "HUNSPELL_DIR", str(Path(__file__).resolve().parent / "resources" / "hunspell")))
JEZICKI_PRAG = int(os.getenv("JEZICKI_PRAG", "2"))

# Determinističke detekcije (langdetect je inače stohastičan za kratke tekstove).
DetectorFactory.seed = 0

# ──────────────────────────────────────────────────────────────────────────────
# PRETPROCESIRANJE JEZIKA (Korak 5 — deterministički)
# ──────────────────────────────────────────────────────────────────────────────

# Pravila normalizacije (ćirilica→latinica + dijakritici) žive u ZAJEDNIČKOM
# JSON-u koji čitaju i PHP (TextUtils) i ovaj servis — jedan izvor istine.
# Nedostajući/nepotpun fajl obara servis pri startu (bolje nego tiho
# razilaženje normalizacije između leksičkog i semantičkog sloja).
_NORM_MAP_PATH = Path(os.getenv(
    "NORMALIZATION_MAP",
    str(BASE_DIR.parent / "resources" / "normalization" / "normalization-map.json"),
))


def _ucitaj_normalizacionu_mapu() -> dict:
    with open(_NORM_MAP_PATH, encoding="utf-8") as f:
        mapa = json.load(f)

    for sekcija in ("cir_digrafi", "cir_slova", "dijakritici"):
        if not isinstance(mapa.get(sekcija), dict) or not mapa[sekcija]:
            raise RuntimeError(
                f"normalization-map.json: sekcija '{sekcija}' nedostaje ili je prazna ({_NORM_MAP_PATH})"
            )

    if not isinstance(mapa.get("nastavci"), list) or not mapa["nastavci"]:
        raise RuntimeError(
            f"normalization-map.json: sekcija 'nastavci' nedostaje ili je prazna ({_NORM_MAP_PATH})"
        )

    return mapa


_NORM_MAP = _ucitaj_normalizacionu_mapu()

# Srpska ćirilica → latinica (digrafi pre pojedinačnih slova).
_CYR_DIGRAPHS = _NORM_MAP["cir_digrafi"]
_CYR_TABLE = str.maketrans(_NORM_MAP["cir_slova"])

# Dijakritici → osnovna slova (isto pravilo za upit I za tekst knjiga u
# indeksu); str.maketrans podržava višeznačne zamene (đ → dj).
_DIACRITICS = str.maketrans(_NORM_MAP["dijakritici"])

# Strani skriptovi (CJK, kana, hangul, arapski, hebrejski, grčki) → odmah strano.
_FOREIGN_SCRIPT = re.compile(
    "[一-鿿぀-ヿ가-힯؀-ۿ֐-׿Ͱ-Ͽ]"
)
# Ćirilična slova van srpske azbuke (ы, э, ё, ъ, ѓ, ќ...) → ruski/mak./bug.
_SERBIAN_CYR = set("абвгдђежзијклљмнњопрстћуфхцчџш")
_ANY_CYR = re.compile("[Ѐ-ӿ]")

# Engleske funkcijske/upitne reči koje se NE javljaju kao samostalne reči u
# srpskom latiničnom tekstu — namerno bez homografa sa srpskim ("do", "sam",
# "me", "a", "i" su izostavljeni). Dve i više → tekst je engleski i pre nego
# što ijedan srpski dijakritik (npr. ime „Andrić") prevari detekciju (Nalaz
# #57: „Do you have any books by Andrić" je pre ovoga prolazilo kao srpski).
_ENGLISH_WORDS = {
    "the", "and", "but", "not", "are", "was", "were", "have", "has", "had",
    "will", "would", "could", "should", "can", "this", "that", "you", "your",
    "with", "from", "for", "any", "some", "book", "books", "please", "show",
    "give", "tell", "what", "which", "does", "how", "want", "there", "these",
    "those", "about", "available",
}


def _english_score(norm: str) -> int:
    return sum(1 for w in re.split(r"[^a-z0-9]+", norm) if w in _ENGLISH_WORDS)

# langdetect nema 'sr' profil — srpsku latinicu prijavljuje kao srodne
# južnoslovenske jezike. Njih prihvatamo kao "srpski".
_ACCEPTED_LANGS = {"hr", "sl", "bs", "mk"}
# ODBACUJU se samo eksplicitno strani jezici, i to sa visokom pouzdanošću.
# Kratke srpske fraze langdetect ume pouzdano da promaši u EGZOTIČNE profile
# ("hvala puno" → fi, "kada radi biblioteka" → id) — zato se ne odbacuje
# "sve što nije prihvaćeno", već samo poznati strani jezici; ostalo prolazi
# (system prompt je rezervni sloj).
_REJECT_LANGS = {
    "en", "de", "fr", "es", "it", "pt", "ru", "uk", "bg", "pl", "cs",
    "hu", "ro", "tr", "el", "ar", "he", "hi", "th", "vi",
    "zh-cn", "zh-tw", "ja", "ko",
}
_REJECT_CONFIDENCE = 0.90


def cyrillic_to_latin(text: str) -> str:
    for dig, lat in _CYR_DIGRAPHS.items():
        text = text.replace(dig, lat)
    return text.translate(_CYR_TABLE)


def fold_diacritics(text: str) -> str:
    return text.translate(_DIACRITICS)


def normalize(text: str) -> str:
    """Kanonski oblik za embedovanje: latinica, mala slova, bez dijakritika."""
    text = cyrillic_to_latin(text)
    text = fold_diacritics(text)
    return re.sub(r"\s+", " ", text).strip().lower()


# Upitni šablon koji se skida PRE embedovanja upita. Cela rečenica
# ("da li imate knjigu X?") guši signal naslova/teme u vektoru — izmereno:
# goli naslov skoruje ~0.77 prema svojoj knjizi, ista rečenica sa šablonom
# samo ~0.69, ispod šuma fonda. Skidaju se samo reči bez informacione
# vrednosti za katalog; sadržajne reči (naslov, autor, tema, žanr) ostaju.
_BOILERPLATE_PHRASES = [
    "da li imate", "da li ima", "da li postoji", "da li mozete", "da li moze",
    "imate li", "ima li", "postoji li", "mozete li", "moze li",
    "preporucite mi", "preporuci mi", "hteo bih", "htela bih", "zeleo bih",
    "zelela bih", "molim vas", "molim te", "zanima me", "interesuje me",
    "trazim", "trebam", "treba mi",
]
_BOILERPLATE_WORDS = {
    "knjiga", "knjige", "knjigu", "knjizi", "knjigama", "naslov", "naslova",
    "nesto", "neku", "neka", "neko", "neki", "nekog", "nekog", "bilo",
    "koju", "koje", "koja", "kod", "vas", "vam", "kad", "sta", "ako",
    "da", "li", "u", "na", "za", "od", "do", "sa", "se", "je", "su", "i",
    "a", "o", "mi", "me", "te", "biblioteci", "biblioteka", "biblioteke",
    "katalogu", "katalog", "kataloga", "vasem", "vasoj", "nasem", "nasoj",
}


def strip_boilerplate(norm: str) -> str:
    """
    Iz NORMALIZOVANOG upita uklanja upitni šablon i vraća sadržajne reči
    za embedovanje. Ako ne preostane ništa, vraća pun normalizovan upit
    (bolje išta nego prazno).
    """
    text = norm
    for phrase in _BOILERPLATE_PHRASES:
        text = text.replace(phrase, " ")

    words = [w for w in re.split(r"[^a-z0-9]+", text) if w and w not in _BOILERPLATE_WORDS]
    cleaned = " ".join(words)

    return cleaned if cleaned else norm


def is_serbian(raw: str) -> bool:
    """
    Deterministička detekcija: True ako upit tretiramo kao srpski.

    1. Strani skript (CJK/arapski/grčki...) → strano.
    2. Ne-srpska ćirilica (ы, э, ё...) → strano; srpska ćirilica → srpski.
    3. Srpski dijakritici (š, č, ć, ž, đ) → srpski.
    4. Inače langdetect nad NORMALIZOVANIM tekstom (mala slova — profili su
       trenirani na malim slovima, velika slova kvare detekciju): odbaci SAMO
       ako je top jezik u skupu poznatih stranih (_REJECT_LANGS) sa
       pouzdanošću ≥ 0.90 i nijedan prihvaćen jezik nije među kandidatima.
    """
    if _FOREIGN_SCRIPT.search(raw):
        return False

    cyr = _ANY_CYR.findall(raw)
    if cyr:
        return all(ch.lower() in _SERBIAN_CYR for ch in cyr)

    # Jasno engleska rečenica (≥ 2 engleske funkcijske reči) → strano, čak i
    # kad sadrži srpski dijakritik u imenu ("... by Andrić"). Ovo mora PRE
    # provere dijakritika, koja bi inače kratko-spojila na "srpski".
    if _english_score(normalize(raw)) >= 2:
        return False

    if re.search(r"[šđžčćŠĐŽČĆ]", raw):
        return True

    try:
        candidates = detect_langs(normalize(raw))
    except LangDetectException:
        return True  # prekratko/gibberish — ne odbacujemo deterministički

    if not candidates:
        return True

    if any(c.lang in _ACCEPTED_LANGS and c.prob >= 0.10 for c in candidates):
        return True

    top = candidates[0]
    return not (top.lang in _REJECT_LANGS and top.prob >= _REJECT_CONFIDENCE)


# ──────────────────────────────────────────────────────────────────────────────
# EMBEDOVANJE — Ollama /api/embed (nomic-embed-text)
# ──────────────────────────────────────────────────────────────────────────────

# Veliki reindeks (stotine knjiga) se deli u manje Ollama pozive — jedan
# ogroman batch ume da probije timeout kada je i chat model rezidentan u RAM-u.
_EMBED_BATCH = 32


def embed(texts: list[str], kind: str) -> list[list[float]]:
    """kind: 'query' ili 'document' — nomic zahteva odgovarajući prefiks."""
    prefix = "search_query: " if kind == "query" else "search_document: "

    vectors: list[list[float]] = []
    for start in range(0, len(texts), _EMBED_BATCH):
        chunk = [prefix + t for t in texts[start:start + _EMBED_BATCH]]

        try:
            resp = requests.post(
                f"{OLLAMA_URL}/api/embed",
                json={"model": EMBED_MODEL, "input": chunk},
                timeout=120,
            )
        except requests.RequestException as e:
            raise HTTPException(status_code=502, detail=f"Ollama nije dostupna: {e}")

        if resp.status_code != 200:
            raise HTTPException(
                status_code=502,
                detail=f"Ollama /api/embed HTTP {resp.status_code}: {resp.text[:200]}",
            )

        vectors.extend(resp.json().get("embeddings", []))

    return vectors


# ──────────────────────────────────────────────────────────────────────────────
# INDEKS PRETRAGE — sqlite-vec (vec0) KNN + FTS5 (BM25) nad knjige_embeddings
# ──────────────────────────────────────────────────────────────────────────────
#
# Kanonsko skladište embedinga je Laravel tabela `knjige_embeddings`
# (knjiga_id, embedding JSON — L2-normalizovan pri upisu). Ovaj servis iz nje
# (JOIN sa `knjige` za tekst) gradi SVOJA dva indeksa u ZASEBNOJ in-memory bazi:
#   - vec0 `knjige_vec` — semantički KNN,
#   - FTS5 `knjige_fts` — leksička BM25 pretraga nad naslov/autor/opis
#     (normalizovan tekst, ista pravila kao embedding — normalization-map.json);
#   - u database.sqlite se NIŠTA ne upisuje (read-only pravilo + izbegnut
#     SQLite locking između Laravela i ovog procesa),
#   - fond je mali (stotine knjiga), pa je pun rebuild jeftin (<1s).
#
# SVEŽINA: pre svake pretrage se poredi jeftin fingerprint (count, max rowid,
# suma dužina JSON-a embedinga + count/max knjiga) — promena okida automatski
# rebuild. POST /sync radi izričit rebuild (zove ga `ai:reindex`).
#
# cosine: vektori su normalizovani pri upisu, upit vec0 normalizuje sam →
# score = 1 - cosine_distance, isto značenje kao raniji PHP dot-product
# (prag ai.rag_min_score ostaje u Laravelu sa istim značenjem). U hits se
# UVEK vraća pravi cosine (i za leksičke pogotke — dot nad _vectors), da
# pragovi u Laravelu zadrže smisao; RRF služi samo za REDOSLED kandidata.

_index_lock = threading.Lock()
_vec_db: sqlite3.Connection | None = None
_vectors: dict[int, list[float]] = {}
_index_fingerprint: tuple | None = None
_index_count = 0


def _laravel_ro() -> sqlite3.Connection:
    """Read-only konekcija na Laravel bazu (URI mode=ro sprečava svaki upis)."""
    return sqlite3.connect(f"file:{LARAVEL_DB.as_posix()}?mode=ro", uri=True)


def _fingerprint(con: sqlite3.Connection) -> tuple:
    return con.execute(
        "SELECT"
        "  (SELECT count(*) FROM knjige_embeddings),"
        "  (SELECT coalesce(max(rowid), 0) FROM knjige_embeddings),"
        "  (SELECT coalesce(sum(length(embedding)), 0) FROM knjige_embeddings),"
        "  (SELECT count(*) FROM knjige),"
        "  (SELECT coalesce(max(rowid), 0) FROM knjige)"
    ).fetchone()


def _rebuild_index() -> int:
    """Pun rebuild vec0 + FTS5 indeksa iz knjige_embeddings ⋈ knjige."""
    global _vec_db, _vectors, _index_fingerprint, _index_count

    with _laravel_ro() as src:
        fingerprint = _fingerprint(src)
        rows = src.execute(
            "SELECT e.knjiga_id, e.embedding, k.naziv_knjige, k.autor, k.opis "
            "FROM knjige_embeddings e JOIN knjige k ON k.id = e.knjiga_id"
        ).fetchall()

    vec_db = sqlite3.connect(":memory:", check_same_thread=False)
    vec_db.enable_load_extension(True)
    sqlite_vec.load(vec_db)
    vec_db.enable_load_extension(False)

    vec_db.execute(
        f"CREATE VIRTUAL TABLE knjige_vec USING "
        f"vec0(knjiga_id integer primary key, embedding float[{EMBED_DIM}] distance_metric=cosine)"
    )
    # rowid FTS tabele = knjiga_id (nema posebne kolone za ID).
    vec_db.execute("CREATE VIRTUAL TABLE knjige_fts USING fts5(naziv, autor, opis)")

    vectors: dict[int, list[float]] = {}
    n = 0
    for knjiga_id, emb_json, naziv, autor, opis in rows:
        try:
            vec = json.loads(emb_json)
        except (TypeError, ValueError):
            continue
        if not isinstance(vec, list) or len(vec) != EMBED_DIM:
            continue

        knjiga_id = int(knjiga_id)
        vec = [float(v) for v in vec]

        vec_db.execute(
            "INSERT INTO knjige_vec(knjiga_id, embedding) VALUES (?, ?)",
            (knjiga_id, sqlite_vec.serialize_float32(vec)),
        )
        vec_db.execute(
            "INSERT INTO knjige_fts(rowid, naziv, autor, opis) VALUES (?, ?, ?, ?)",
            (knjiga_id, normalize(naziv or ""), normalize(autor or ""), normalize(opis or "")),
        )
        vectors[knjiga_id] = vec
        n += 1

    vec_db.commit()

    if _vec_db is not None:
        _vec_db.close()

    _vec_db = vec_db
    _vectors = vectors
    _index_fingerprint = fingerprint
    _index_count = n

    return n


def _ensure_index(force: bool = False) -> None:
    """Rebuild indeksa ako je tabela u Laravel bazi promenjena (ili force)."""
    global _index_fingerprint

    with _index_lock:
        try:
            if not force and _vec_db is not None:
                with _laravel_ro() as src:
                    if _fingerprint(src) == _index_fingerprint:
                        return
            n = _rebuild_index()
            print(f"[index] knjige_vec obnovljen: {n} vektora")
        except Exception as e:  # baza/tabela nedostupna → radi bez semantike
            print(f"[index] rebuild neuspešan ({e}) — pretraga vraća prazno")


def _knn(vector: list[float], top_k: int) -> list[dict]:
    """Top-K najsličnijih knjiga: [{id, score, source}], score = 1 - distance."""
    with _index_lock:
        if _vec_db is None or _index_count == 0 or top_k < 1:
            return []

        rows = _vec_db.execute(
            "SELECT knjiga_id, distance FROM knjige_vec "
            "WHERE embedding MATCH ? AND k = ? ORDER BY distance",
            (sqlite_vec.serialize_float32([float(v) for v in vector]), top_k),
        ).fetchall()

    return [
        {"id": int(kid), "score": 1.0 - float(dist), "source": "semantic"}
        for kid, dist in rows
    ]


# Nastavci promene srpskih imenica/prideva — JEDAN izvor sa PHP-om
# (normalization-map.json, sekcija "nastavci"; čita ga i TextUtils::koren):
# leksički upit se svodi na koren sa prefiks-džokerom, pa „andrica" (genitiv)
# pogađa „andric", „price" pogađa „prica"/„pricama". Bez ovoga bi BM25
# promašivao padeže.
_NASTAVCI = _NORM_MAP["nastavci"]


def _koren(rec: str) -> str:
    """Gruba osnova reči: skida JEDAN padeški nastavak ako osnova ostaje ≥ 4."""
    for nastavak in _NASTAVCI:
        if len(rec) - len(nastavak) >= 4 and rec.endswith(nastavak):
            return rec[: len(rec) - len(nastavak)]
    return rec


def _fts_match_izraz(clean: str) -> str:
    """
    FTS5 MATCH izraz od očišćenog upita: svaki token ≥ 3 slova postaje
    prefiks-upit nad korenom ('"andric"*'), spojeni sa OR. Tokeni su pod
    navodnicima → korisnički unos ne može da ubaci FTS5 sintaksu.
    """
    tokeni = [t for t in re.split(r"[^a-z0-9]+", clean) if len(t) >= 3]
    koreni = list(dict.fromkeys(_koren(t) for t in tokeni))  # unikat, čuva red

    return " OR ".join(f'"{k}"*' for k in koreni)


def _fts_search(clean: str, limit: int) -> list[int]:
    """ID-jevi knjiga po BM25 relevantnosti (najbolji prvi); [] bez pogodaka."""
    izraz = _fts_match_izraz(clean)
    if izraz == "":
        return []

    with _index_lock:
        if _vec_db is None or _index_count == 0:
            return []

        rows = _vec_db.execute(
            "SELECT rowid FROM knjige_fts WHERE knjige_fts MATCH ? "
            "ORDER BY bm25(knjige_fts) LIMIT ?",
            (izraz, limit),
        ).fetchall()

    return [int(r[0]) for r in rows]


def _cosine(qvec: list[float], knjiga_id: int) -> float:
    """Pravi cosine upita prema knjizi (vektori u indeksu su normalizovani)."""
    dvec = _vectors.get(knjiga_id)
    if not dvec:
        return 0.0

    qnorm = sum(v * v for v in qvec) ** 0.5
    if qnorm <= 0.0:
        return 0.0

    return sum(q * d for q, d in zip(qvec, dvec)) / qnorm


def _fuse(sem: list[dict], fts_ids: list[int], top_k: int, qvec: list[float]) -> list[dict]:
    """
    Reciprocal Rank Fusion semantičkog i leksičkog bazena:
    rrf(id) = Σ 1/(RRF_K + rang) po listama u kojima se id pojavljuje.
    REDOSLED određuje RRF (tie-break cosine), a `score` ostaje PRAVI cosine
    — pragovi u Laravelu (rag_min_score, filter ravnog šuma) zadržavaju smisao.
    """
    rrf: dict[int, float] = {}
    for rang, hit in enumerate(sem):
        rrf[hit["id"]] = rrf.get(hit["id"], 0.0) + 1.0 / (RRF_K + rang + 1)
    for rang, kid in enumerate(fts_ids):
        rrf[kid] = rrf.get(kid, 0.0) + 1.0 / (RRF_K + rang + 1)

    sem_ids = {hit["id"] for hit in sem}
    sem_score = {hit["id"]: hit["score"] for hit in sem}
    fts_set = set(fts_ids)

    kandidati = []
    for kid, r in rrf.items():
        cos = sem_score.get(kid)
        if cos is None:
            cos = _cosine(qvec, kid)
        kandidati.append({
            "id": kid,
            "score": cos,
            "rrf": r,
            "source": "both" if (kid in sem_ids and kid in fts_set)
                      else ("semantic" if kid in sem_ids else "lexical"),
        })

    kandidati.sort(key=lambda h: (-h["rrf"], -h["score"]))

    return kandidati[:top_k]


# ──────────────────────────────────────────────────────────────────────────────
# FASTAPI
# ──────────────────────────────────────────────────────────────────────────────

@asynccontextmanager
async def lifespan(app: FastAPI):
    print(f"[startup] Bib RAG servis — embed model: {EMBED_MODEL} (Ollama: {OLLAMA_URL})")
    print(f"[startup] Laravel baza (read-only): {LARAVEL_DB}")
    _ensure_index(force=True)
    _rebuild_whitelist()   # jezički guard — whitelist vlastitih imenica iz kataloga
    yield


app = FastAPI(title="Bib RAG servis", version="3.0.0", lifespan=lifespan)


def _check_auth(authorization: str | None) -> None:
    """Provera zajedničkog ključa iz zaglavlja Authorization.

    Poređenje ide preko hmac.compare_digest, a ne operatorom `!=`: obično
    poređenje niski prekida se na prvom razlicitom bajtu, pa vreme odgovora
    otkriva koliko je početnih znakova pogođeno i ključ se može rekonstruisati
    znak po znak. compare_digest troši isto vreme bez obzira na to gde je
    razlika. Prazan ključ (lokalni razvoj) znači da provere nema.
    """
    if not API_KEY:
        return

    ocekivano = f"Bearer {API_KEY}"

    if not hmac.compare_digest(authorization or "", ocekivano):
        raise HTTPException(status_code=401, detail="Neispravan API ključ.")


class RetrieveRequest(BaseModel):
    message: str
    history: list[dict] = []   # rezervisano za kasnije faze (reranking)
    top_k: int | None = None
    fuse: bool = True          # False = samo semantika (merenje pre/posle, Faza 3)


class EmbedRequest(BaseModel):
    texts: list[str]


@app.get("/health")
def health():
    return {
        "status": "ok",
        "model": EMBED_MODEL,
        "dim": EMBED_DIM,
        "ollama": OLLAMA_URL,
        "indexed": _index_count,
    }


@app.post("/retrieve")
def retrieve(req: RetrieveRequest, authorization: str | None = Header(default=None)):
    """
    Ceo retrieval za korisničko pitanje u JEDNOM pozivu:
    normalizacija → langdetect → embed upita → HIBRID (sqlite-vec KNN +
    FTS5/BM25) → RRF spajanje → top-K kandidata.

    `score` u hits je uvek PRAVI cosine (RRF određuje samo redosled), pa
    prag relevantnosti (ai.rag_min_score) u Laravelu zadržava značenje.
    Leksički DOMENSKI sloj (score 1.0, razlog naziv/autor/žanr) i dalje
    živi u Laravelu. `history` se prima ali još ne koristi (reranking faza).
    """
    _check_auth(authorization)

    raw = req.message.strip()
    if raw == "":
        return {"ok": False, "reason": "prazan_upit"}

    if not is_serbian(raw):
        return {"ok": False, "reason": "strani_jezik"}

    text = normalize(raw)
    clean = strip_boilerplate(text)          # sadržajne reči — embeduje se OVO
    vectors = embed([clean], kind="query")

    _ensure_index()

    top_k = req.top_k or RETRIEVE_TOP_K
    hits: list[dict] = []

    if vectors and vectors[0]:
        sem = _knn(vectors[0], CAND_K)
        hits = (
            _fuse(sem, _fts_search(clean, CAND_K), top_k, vectors[0])
            if req.fuse
            else sem[:top_k]
        )

    return {
        "ok": True,
        "text": text,                        # pun normalizovan upit (klasifikacija u Laravelu)
        "clean": clean,                      # očišćen upit (ono što je embedovano)
        "hits": hits,                        # top-K {id, score(cosine), rrf?, source} BEZ praga
        "dim": EMBED_DIM,
    }


@app.post("/sync")
def sync(authorization: str | None = Header(default=None)):
    """Izričit rebuild vec0 indeksa iz knjige_embeddings (zove ga ai:reindex)."""
    _check_auth(authorization)

    _ensure_index(force=True)
    _rebuild_whitelist()   # katalog se promenio → osveži i whitelist guarda

    return {"ok": True, "indexed": _index_count}


@app.post("/embed")
def embed_documents(req: EmbedRequest, authorization: str | None = Header(default=None)):
    """Embedovanje tekstova knjiga za indeks (ai:reindex) — ista normalizacija."""
    _check_auth(authorization)

    if not req.texts:
        return {"vectors": [], "dim": EMBED_DIM, "model": EMBED_MODEL}

    texts = [normalize(t) for t in req.texts]
    return {"vectors": embed(texts, kind="document"), "dim": EMBED_DIM, "model": EMBED_MODEL}


# ─────────────────────────────────────────────────────────────────────────────
# JEZIČKI GUARD — izlazna provera odgovora (Hunspell + whitelist iz kataloga)
# ─────────────────────────────────────────────────────────────────────────────
# Reč iz odgovora je PRIHVATLJIVA ako je u srpskom (latiničnom) rečniku ILI je
# vlastita imenica iz kataloga (autor/naslov/mesto/izdavač) ILI fiksni pojam.
# Reč koja nije nijedno = sumnjiva (hrvatska/engleska/gibberiš). Previše
# sumnjivih (> JEZICKI_PRAG) → ok=false, pa Laravel guard obara odgovor.
# Proverava se FORMA (leksikon), ne istinitost. Rečnik nedostupan → disabled.

_REC_ALFA = re.compile(r"[A-Za-zŠĐŽČĆšđžčć]{2,}")

_FIKSNI_POJMOVI = {
    "atvss", "bib", "silvana", "bogicevic", "nis", "notifikacije",
    "rezervisi", "dostupna", "nedostupna", "eknjige", "onlajn",
}

_SR_DICT = None
_SR_DICT_LOADED = False
_WHITELIST: set[str] = set()


def _ucitaj_recnik():
    """Lazy učitavanje spylls Hunspell rečnika (sr-Latn). Nema fajlova/pakета →
    None (guard onemogućen, endpoint vraća ok)."""
    global _SR_DICT, _SR_DICT_LOADED
    if _SR_DICT_LOADED:
        return _SR_DICT
    _SR_DICT_LOADED = True
    try:
        from spylls.hunspell import Dictionary
        if (HUNSPELL_DIR / "sr-Latn.dic").exists():
            _SR_DICT = Dictionary.from_files(str(HUNSPELL_DIR / "sr-Latn"))
            print(f"[jezicki-guard] rečnik učitan: {HUNSPELL_DIR / 'sr-Latn'}")
        else:
            print(f"[jezicki-guard] rečnik nije nađen u {HUNSPELL_DIR} — guard onemogućen")
    except Exception as e:  # spylls nije instaliran ili rečnik neispravan
        print(f"[jezicki-guard] rečnik/spylls nedostupan ({e}) — guard onemogućen")
    return _SR_DICT


def _rebuild_whitelist() -> None:
    """Whitelist vlastitih imenica iz kataloga (osvežava se na /sync i startu)."""
    global _WHITELIST
    reci = set(_FIKSNI_POJMOVI)
    try:
        with _laravel_ro() as con:
            for kol in ("autor", "naziv_knjige", "mesto_izdavanja", "izdavac"):
                for (v,) in con.execute(
                    f"SELECT DISTINCT {kol} FROM knjige WHERE {kol} IS NOT NULL AND {kol} <> ''"
                ):
                    for t in _REC_ALFA.findall(cyrillic_to_latin(v or "")):
                        reci.add(t.lower())
    except Exception as e:
        print(f"[jezicki-guard] whitelist rebuild greška: {e}")
    _WHITELIST = reci
    print(f"[jezicki-guard] whitelist: {len(_WHITELIST)} pojmova")


class ProveriRequest(BaseModel):
    tekst: str


@app.post("/proveri-tekst")
def proveri_tekst(req: ProveriRequest, authorization: str | None = Header(default=None)):
    """Izlazna jezička provera: vraća {ok, disabled, sumnjive}. Fail-safe je na
    Laravel strani (servis/rečnik nedostupan → ne blokira bota)."""
    _check_auth(authorization)

    recnik = _ucitaj_recnik()
    if recnik is None:
        return {"ok": True, "disabled": True, "sumnjive": []}

    if not _WHITELIST:
        _rebuild_whitelist()

    # Izlaz je latinica; ipak transliteruj za svaki slučaj, pa skini link markup
    # ([Naslov](/knjige/ID) → Naslov, da se naslovi provere kao whitelist reči).
    tekst = cyrillic_to_latin(req.tekst or "")
    tekst = re.sub(r"\[([^\[\]]+)\]\(/knjige/\d+\)", r"\1", tekst)

    sumnjive: list[str] = []
    for rec in _REC_ALFA.findall(tekst):
        low = rec.lower()
        if low in _WHITELIST:
            continue
        if recnik.lookup(rec) or recnik.lookup(low):
            continue
        sumnjive.append(rec)

    return {"ok": len(sumnjive) <= JEZICKI_PRAG, "disabled": False, "sumnjive": sumnjive[:12]}


if __name__ == "__main__":
    import uvicorn

    host = os.getenv("RAG_HOST", "127.0.0.1")
    port = int(os.getenv("RAG_PORT", "8001"))
    uvicorn.run("main:app", host=host, port=port, workers=1)

    