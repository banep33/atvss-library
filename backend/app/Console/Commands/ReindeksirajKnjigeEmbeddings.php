<?php

namespace App\Console\Commands;

use App\Models\Knjiga;
use App\Services\Ai\RagClient;
use App\Services\Ai\RagService;
use App\Services\Ai\VectorStore;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * ai:reindex — gradi/ažurira vektorski indeks knjiga (knjige_embeddings).
 *
 * Za svaku knjigu spaja naziv, autora, žanrove i opis u jedan tekst
 * (RagService::buildIndexText), Python mikroservis ga normalizuje
 * (latinica, bez dijakritika) i embeduje preko Ollame (nomic-embed-text),
 * a vektor se upisuje u tabelu knjige_embeddings.
 *
 * Bez --only: embeduje SVE knjige (bulk) i radi pun rebuild
 *   (replace=true — uklanja i "siroče" vektore obrisanih knjiga).
 * Sa --only=ID: reindeksira samo jednu knjigu (upsert).
 *
 * Preduslovi:
 *   - Ollama pokrenuta + `ollama pull nomic-embed-text`,
 *   - Python RAG servis pokrenut (rag_service/start.bat).
 */
class ReindeksirajKnjigeEmbeddings extends Command
{
    protected $signature   = 'ai:reindex {--only= : ID jedne knjige za reindeksiranje}';
    protected $description = 'Gradi/ažurira vektorski indeks knjiga (RAG servis + knjige_embeddings)';

    public function handle(RagService $rag, RagClient $client, VectorStore $store): int
    {
        // 1) RAG servis dignut?
        try {
            $health = $client->health();
            $this->info("RAG servis OK — embed model: {$health['model']}, dim: {$health['dim']}");
        } catch (\Throwable $e) {
            $this->error('RAG servis nije dostupan na ' . config('ai.rag_service_url') . '.');
            $this->warn('Pokreni ga prvo: rag_service\\start.bat (i proveri da je Ollama dignuta).');
            return self::FAILURE;
        }

        // 2) tabela knjige_embeddings postoji?
        if (!$store->available()) {
            $this->error('Tabela knjige_embeddings ne postoji — pokreni: php artisan migrate');
            return self::FAILURE;
        }

        if ($onlyId = $this->option('only')) {
            $knjiga = Knjiga::find($onlyId);
            if (!$knjiga) {
                $this->warn("Knjiga #{$onlyId} ne postoji.");
                return self::SUCCESS;
            }
            try {
                $rag->reindex($knjiga);
                $this->info("Reindeksirana knjiga #{$onlyId} ({$knjiga->naziv_knjige}).");
                $this->syncMikroservis($client);
            } catch (RuntimeException $e) {
                $this->error("Greška: {$e->getMessage()}");
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        $knjige = Knjiga::with('zanrovi')->get();

        if ($knjige->isEmpty()) {
            $this->warn('Nema knjiga za reindeksiranje.');
            return self::SUCCESS;
        }

        $this->info("Embedujem {$knjige->count()} knjiga i upisujem u knjige_embeddings (pun rebuild)...");

        try {
            $indeksirano = $rag->reindexMany($knjige, replace: true);
            $this->info("Gotovo. Knjige: {$indeksirano} (ukupno u knjige_embeddings: {$store->count(VectorStore::KNJIGE)}).");
            $this->syncMikroservis($client);
        } catch (RuntimeException $e) {
            $this->error("Reindeks knjiga nije uspeo: {$e->getMessage()}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Izričit rebuild vec0 indeksa u mikroservisu posle upisa embedinga.
     * Best-effort: mikroservis promenu tabele ionako otkriva sam
     * (fingerprint pre svake pretrage), pa neuspeh nije greška komande.
     */
    private function syncMikroservis(RagClient $client): void
    {
        try {
            $sync = $client->sync();
            $this->info('Mikroservis /sync: vec0 indeks obnovljen (' . ($sync['indexed'] ?? '?') . ' vektora).');
        } catch (\Throwable $e) {
            $this->warn("Mikroservis /sync nije uspeo ({$e->getMessage()}) — indeks će se osvežiti sam pri prvoj pretrazi.");
        }
    }
}
