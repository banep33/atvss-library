<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * RagClient — HTTP klijent ka Python RAG mikroservisu (FastAPI, :8001).
 *
 * Mikroservis radi ceo retrieval deo pipeline-a:
 *  - /retrieve: pretprocesiranje upita (ćirilica→latinica, normalizacija
 *    dijakritika, langdetect) + embedding upita (Ollama nomic-embed-text)
 *    + sqlite-vec KNN → sirovi top-K pogoci. Ako upit nije srpski →
 *    ok:false i pipeline se prekida porukom "Nisam Vas razumeo".
 *  - /embed: ista normalizacija + embedding tekstova knjiga (ai:reindex).
 *  - /sync: izričit rebuild vec0 indeksa iz knjige_embeddings.
 *
 * KANONSKO skladište vektora ostaje u Laravelu ({@see VectorStore}, tabela
 * knjige_embeddings) — mikroservis je čita read-only i gradi svoj indeks.
 *
 * OTPORNOST: ako servis nije pokrenut, pozivi bacaju RuntimeException.
 * Pozivaoci (AiChatService) to hvataju i nastavljaju bez RAG konteksta.
 */
class RagClient
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $timeout;
    private int $connectTimeout;

    public function __construct()
    {
        $this->baseUrl        = rtrim(config('ai.rag_service_url', 'http://127.0.0.1:8001'), '/');
        $this->apiKey         = config('ai.rag_service_key') ?: null;
        $this->timeout        = (int) config('ai.rag_timeout', 15);
        $this->connectTimeout = (int) config('ai.rag_connect_timeout', 3);
    }

    /**
     * Ceo retrieval za korisničko pitanje u JEDNOM pozivu: mikroservis radi
     * normalizaciju + langdetect + embedding + sqlite-vec KNN i vraća sirove
     * top-K pogotke BEZ praga (prag ai.rag_min_score primenjuje pozivalac).
     *
     * @param  array<int, array{role: string, content: string}>  $history
     *         prosleđuje se mikroservisu (rezervisano za FTS/reranking faze)
     * @return array{ok: bool, reason?: string, text?: string, clean?: string,
     *               hits?: array<int, array{id: int, score: float, source: string}>}
     * @throws RuntimeException ako servis nije dostupan
     */
    public function retrieve(string $message, array $history = []): array
    {
        return $this->post('/retrieve', [
            'message' => $message,
            'history' => array_values($history),
            'top_k'   => (int) config('ai.rag_top_k', 5),
        ]);
    }

    /**
     * Izričit rebuild vec0 indeksa u mikroservisu iz tabele knjige_embeddings
     * — zove ga ai:reindex posle upisa embedinga. (Mikroservis i sam otkriva
     * promene tabele po fingerprint-u, ovo je izričita potvrda.)
     *
     * @return array{ok: bool, indexed: int}
     * @throws RuntimeException ako servis nije dostupan
     */
    public function sync(): array
    {
        return $this->post('/sync', []);
    }

    /**
     * JEZIČKI GUARD (opciono): izlazna provera generisanog odgovora Hunspell
     * rečnikom + whitelist vlastitih imenica iz kataloga. Proverava FORMU
     * (čist srpski leksikon), ne istinitost.
     *
     * @return array{ok: bool, disabled?: bool, sumnjive?: array<int, string>}
     * @throws RuntimeException ako servis nije dostupan (pozivalac fail-open)
     */
    public function proveriTekst(string $tekst): array
    {
        return $this->post('/proveri-tekst', ['tekst' => $tekst], timeout: 5);
    }

    /**
     * Embeduje tekstove knjiga za indeks (batch). Mikroservis primenjuje
     * istu normalizaciju kao za upit pre embedovanja.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<float>>  vektori istim redosledom kao ulaz
     */
    public function embedDocuments(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $data = $this->post('/embed', [
            'texts' => array_values($texts),
        ], timeout: max($this->timeout, 120)); // batch embed može potrajati

        return $data['vectors'] ?? [];
    }

    /**
     * Preload pri startu aplikacije: minimalan /retrieve zahtev tera
     * mikroservis da učita langdetect profile i osveži vec0 indeks, a Ollamu
     * da učita nomic-embed-text u RAM — prvi pravi upit inače plaća taj
     * hladan start.
     *
     * Princip „fire-and-forget" (kao OllamaClient::warmup): upiši zahtev preko
     * sirovog socketa i odmah zatvori, da web zahtev koji je okinuo boot ne
     * čeka — servis nastavlja u pozadini. Ne baca izuzetak.
     */
    public function warmup(): void
    {
        try {
            $parts = parse_url($this->baseUrl);
            $host  = $parts['host'] ?? '127.0.0.1';
            $port  = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

            $body = json_encode(['message' => 'zagrevanje servisa', 'history' => []]);

            $fp = @fsockopen($host, (int) $port, $errno, $errstr, 2);
            if (!$fp) {
                Log::info('[RagClient] warmup — RAG servis nije dostupan', ['error' => $errstr]);
                return;
            }

            $req  = "POST /retrieve HTTP/1.1\r\n";
            $req .= "Host: {$host}:{$port}\r\n";
            $req .= "Content-Type: application/json\r\n";
            if ($this->apiKey) {
                $req .= "Authorization: Bearer {$this->apiKey}\r\n";
            }
            $req .= "Content-Length: " . strlen($body) . "\r\n";
            $req .= "Connection: close\r\n\r\n";
            $req .= $body;

            fwrite($fp, $req);
            fclose($fp); // zatvaramo odmah — servis nastavlja učitavanje u pozadini

            Log::info('[RagClient] warmup signal poslan');
        } catch (\Throwable $e) {
            Log::info('[RagClient] warmup neuspešan', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Health check — vraća telo /health ili baca izuzetak ako servis ne radi.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $response = $this->client($this->connectTimeout)->get("{$this->baseUrl}/health");

        if (!$response->successful()) {
            throw new RuntimeException('RAG servis vratio HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function post(string $path, array $body, ?int $timeout = null): array
    {
        $response = $this->client($timeout)->post("{$this->baseUrl}{$path}", $body);

        if (!$response->successful()) {
            Log::error('[RagClient] HTTP greška', [
                'path'   => $path,
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 200),
            ]);
            throw new RuntimeException('RAG servis vratio HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    private function client(?int $timeout = null): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::withOptions([
            'connect_timeout' => $this->connectTimeout,
        ])->timeout($timeout ?? $this->timeout);

        if ($this->apiKey) {
            $req = $req->withToken($this->apiKey);
        }

        return $req;
    }
}
