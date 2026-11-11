<?php

namespace App\Services\Ai;

use App\Models\Knjiga;
use Illuminate\Support\Collection;

/**
 * RagService — INDEKSIRANJE kataloga za semantičku pretragu: građenje
 * indeksnog teksta + orkestracija embedovanja + upis u kanonsko skladište
 * (VectorStore, tabela knjige_embeddings).
 *
 * SAMA PRETRAGA NE ŽIVI OVDE: vektorski KNN radi Python mikroservis
 * (rag_service /retrieve, sqlite-vec) koji iz knjige_embeddings gradi svoj
 * indeks; AiChatService pogotke dobija kroz RagClient::retrieve i primenjuje
 * prag ai.rag_min_score. Hidrataciju pogodaka radi LibraryDataService.
 *
 * PIPELINE INDEKSIRANJA:
 *   1. buildIndexText — spaja naziv, autora, izdanje i opis u jedan tekst,
 *   2. RagClient::embedDocuments — normalizacija + embedding u mikroservisu
 *      (Ollama nomic-embed-text),
 *   3. VectorStore — upis u knjige_embeddings (mikroservis promenu tabele
 *      otkriva sam po fingerprint-u; ai:reindex zove i izričit /sync).
 *
 * OTPORNOST: ako embed servis nije dignut, RagClient baca RuntimeException;
 * ako tabela ne postoji, VectorStore upis je no-op. U oba slučaja chat radi
 * dalje, samo bez kataloškog konteksta.
 */
class RagService
{
    public function __construct(
        private readonly RagClient   $client,
        private readonly VectorStore $store,
    ) {}

    /**
     * Tekst koji se embeduje za jednu knjigu — naziv, autor, mesto i godina
     * izdanja, opis (bib-pravila.md tačka 6: pretraga i po godini i mestu
     * izdanja). Normalizaciju radi mikroservis, isto kao za upit.
     *
     * IME ŽANRA se NAMERNO NE embeduje (Nalaz 1): semantika služi TEMATSKOJ
     * pretrazi po opisu, a žanr u vektoru je „curio" u temu — „na temu
     * priče" je vraćalo knjige žanra „Priče". Žanrovska pitanja rešava
     * deterministički sloj (KatalogPretraga + StrukturniUpiti::odgovorZanrLista),
     * kome embedding nije potreban. Posle izmene ovog teksta obavezan je
     * `php artisan ai:reindex`.
     */
    public function buildIndexText(Knjiga $knjiga): string
    {
        // Mesto i godina su zasebne kolone — za indeks se spajaju u isti
        // oblik („Beograd, 2015") kakav je bio i pre razdvajanja, pa
        // postojeći embedinzi konvertovanih knjiga ostaju validni.
        $izdanje = trim(implode(', ', array_filter([
            $knjiga->mesto_izdavanja,
            $knjiga->godina_izdavanja,
        ])));

        return trim(implode(' — ', array_filter([
            $knjiga->naziv_knjige,
            $knjiga->autor,
            $izdanje !== '' ? $izdanje : null,
            $knjiga->opis,
        ])));
    }

    /**
     * Indeksira jednu knjigu (embed + upsert u knjige_embeddings).
     *
     * @throws \RuntimeException ako embed servis nije dostupan
     */
    public function reindex(Knjiga $knjiga): void
    {
        $vector = $this->client->embedDocuments([$this->buildIndexText($knjiga)])[0] ?? [];

        if (!empty($vector)) {
            $this->store->upsert(VectorStore::KNJIGE, $knjiga->id, $vector);
        }
    }

    /**
     * Bulk indeksiranje — koristi `ai:reindex`. `replace=true` radi pun rebuild.
     *
     * @param  Collection<int, Knjiga>  $knjige
     * @throws \RuntimeException
     */
    public function reindexMany(Collection $knjige, bool $replace = false): int
    {
        if ($knjige->isEmpty()) {
            return 0;
        }

        $knjige  = $knjige->values();
        $texts   = $knjige->map(fn (Knjiga $k) => $this->buildIndexText($k))->all();
        $vectors = $this->client->embedDocuments($texts);

        // Uparujemo vektore sa ID-jevima istim redosledom kao tekstovi.
        $items = [];
        foreach ($knjige as $i => $knjiga) {
            if (!empty($vectors[$i])) {
                $items[$knjiga->id] = $vectors[$i];
            }
        }

        return $this->store->upsertMany(VectorStore::KNJIGE, $items, $replace);
    }

    /**
     * Uklanja knjigu iz indeksa (poziva se posle brisanja).
     */
    public function removeFromIndex(int $knjigaId): void
    {
        $this->store->remove(VectorStore::KNJIGE, $knjigaId);
    }
}
