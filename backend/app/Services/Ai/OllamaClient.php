<?php

namespace App\Services\Ai;

use App\Services\Ai\TextUtils;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OllamaClient — HTTP klijent ka Ollama /api/chat endpointu.
 *
 * Odgovornosti:
 *  - Sinhroni chat() za Tinker testove i PHPUnit
 *  - Streaming streamChat() Generator za SSE produkciju
 *  - warmup() za cold-start mitigaciju pri pokretanju aplikacije
 *
 * Tekstualne operacije (transliteracija, detekcija engleskog, injection check)
 * delegirane su u TextUtils — nema više private duplikata.
 */
class OllamaClient
{
    private string $baseUrl;
    private string $model;
    private int    $timeout;
    private int    $connectTimeout;
    private string $keepAlive;
    private array  $modelOptions;

    public function __construct()
    {
        $this->baseUrl        = (string) config('ai.url',             'http://127.0.0.1:11434');
        $this->model          = (string) config('ai.model',           'llama3.2:3b');
        $this->timeout        = (int)    config('ai.timeout',         180);
        $this->connectTimeout = (int)    config('ai.connect_timeout',  10);
        $this->keepAlive      = (string) config('ai.keep_alive',       '-1'); 

        // Opcije iz config/ai.php (temperature, num_predict) se čuvaju
        // i merge-uju sa per-request opcijama u buildOptions().
        // Prethodno: ove vrednosti su čitane iz config-a ali NIKAD prosleđivane
        // Ollami — bug je bio u tome što doRequest() i streamChat() imali
        // hardkodovane options umesto da koriste config vrednosti.
        $this->modelOptions   = config('ai.options', []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // JAVNI API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sinhroni chat — kompletan odgovor odjednom.
     * Koristiti za: Tinker testove, PHPUnit, non-streaming endpoint.
     *
     * Retry logika:
     *  1. Prazan odgovor → jedan retry odmah
     *  2. Engleski odgovor → jedan retry (cold start često daje engleski fragment)
     *  3. Posle retry-a i dalje loše → RuntimeException
     */
    public function chat(array $messages): string
    {
        $content = $this->doRequest($messages);

        if (empty($content)) {
            Log::warning('OllamaClient: prazan odgovor, retry...', ['model' => $this->model]);
            $content = $this->doRequest($messages);
        }

        if (empty($content)) {
            throw new \RuntimeException('Ollama je vratila prazan odgovor posle retry-a.');
        }

        $content = TextUtils::cyrillicToLatin($content);

        // Rezervni izlazni guard: mali 3b modeli (qwen2.5 — engleski/kineski
        // korpus; llama3.2 — pretežno engleski) umeju da skliznu u engleski
        // ili ubace strane tokene — kontrola izlaza pokriva engleski i strane
        // skripte (CJK, ne-srpska ćirilica) i generalna je za bilo koji model.
        if (TextUtils::isForeignResponse($content)) {
            Log::warning('OllamaClient: odgovor na stranom jeziku, retry...', [
                'preview' => mb_substr($content, 0, 80),
            ]);
            $content = TextUtils::cyrillicToLatin($this->doRequest($messages));

            if (TextUtils::isForeignResponse($content)) {
                throw new \RuntimeException('Model odgovorio na stranom jeziku posle retry-a.');
            }
        }

        return $content;
    }

    /**
     * Streaming chat — yield-uje tokene jedan po jedan čim stignu od Ollame.
     *
     * Tok:
     *   1. Guzzle otvara HTTP konekciju sa stream: true
     *   2. Body se čita u 4096-bajtnim blokovima
     *   3. Blokovi se bufferuju i splitu po \n (NDJSON format)
     *   4. Svaki chunk se parsira, token se transliteriše i yield-uje
     *   5. Kada Ollama pošalje done: true — stream se zatvara
     *
     * @return \Generator  yield string tokene
     * @throws \RuntimeException  Na konekcijskoj grešci
     */
    public function streamChat(array $messages): \Generator
    {
        $client = new \GuzzleHttp\Client([
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
            'stream'          => true,
        ]);

        try {
            $response = $client->post("{$this->baseUrl}/api/chat", [
                'json' => [
                    'model'      => $this->model,
                    'messages'   => $messages,
                    'stream'     => true,
                    'keep_alive' => $this->keepAliveValue(),
                    'options'    => $this->buildOptions(),
                ],
            ]);
        }  catch (\GuzzleHttp\Exception\ConnectException $e) {
             Log::error('OllamaClient: konekcija neuspešna (stream)', ['error' => $e->getMessage()]);
             throw new \RuntimeException('Ollama servis nije dostupan.');
        }  catch (\GuzzleHttp\Exception\RequestException $e) {
             Log::error('OllamaClient: HTTP greška (stream)', [
                 'status' => $e->getResponse()?->getStatusCode(),
                 'error'  => $e->getMessage(),
            ]);
            throw new \RuntimeException('Ollama servis vratio HTTP grešku.');
        }

        $body   = $response->getBody();
        $buffer = '';

        try {
            while (!$body->eof()) {
                $raw = $body->read(4096);

                if ($raw === '') {
                    continue;
                }

                $buffer .= $raw;
                $lines   = explode("\n", $buffer);
                $buffer  = array_pop($lines); // nepotpuni red čeka sledeći read

                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '') {
                        continue;
                    }

                    $chunk = json_decode($line, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::warning('OllamaClient: malformiran JSON chunk', ['line' => $line]);
                        continue;
                    }

                    $token = $chunk['message']['content'] ?? '';

                    if ($token !== '') {
                        yield TextUtils::cyrillicToLatin($token);
                    }

                    if ($chunk['done'] ?? false) {
                        break 2;
                    }
                }
            }
        } finally {
            $body->close();
        }
    }

    /**
     * Preload modela u RAM pri startu aplikacije. Poziva se iz
     * AppServiceProvider::boot(). Ne baca exception — greška nije kritična za boot.
     *
     * PRAVI PRELOAD (ne generisanje): šalje /api/generate BEZ 'prompt' polja —
     * Ollama tada samo UČITA model u RAM i odmah vrati done:true, bez ijednog
     * generisanog tokena. (Prethodno se slao /api/chat sa porukom ' ', što je
     * nepotrebno pokretalo generisanje i trošilo CPU.)
     *
     * keep_alive (default '-1') drži model trajno rezidentnim → posle ovog
     * jednog poziva nema više cold-starta tokom rada aplikacije.
     *
     * Šalje se preko sirovog socketa po principu „fire-and-forget" (upiši zahtev,
     * odmah zatvori) da web zahtev koji je okinuo boot ne čeka ~10s na učitavanje —
     * Ollama nastavlja preload u pozadini.
     */
    public function warmup(): void
    {
    try {
        $host = parse_url($this->baseUrl, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url($this->baseUrl, PHP_URL_PORT) ?: 11434;

        $body = json_encode([
            'model'      => $this->model,
            'stream'     => false,
            'keep_alive' => $this->keepAliveValue(),
        ]);

        $fp = @fsockopen($host, $port, $errno, $errstr, 3);
        if (!$fp) {
            Log::info('OllamaClient: warmup — Ollama nije dostupna', ['error' => $errstr]);
            return;
        }

        $http  = "POST /api/generate HTTP/1.1\r\n";
        $http .= "Host: {$host}:{$port}\r\n";
        $http .= "Content-Type: application/json\r\n";
        $http .= "Content-Length: " . strlen($body) . "\r\n";
        $http .= "Connection: close\r\n\r\n";
        $http .= $body;

        fwrite($fp, $http);
        fclose($fp); // zatvaramo odmah — Ollama nastavlja učitavanje u pozadini

        Log::info('OllamaClient: preload signal poslan', ['model' => $this->model]);

    } catch (\Throwable $e) {
        Log::info('OllamaClient: warmup neuspešan', ['error' => $e->getMessage()]);
    }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATNE METODE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tipizira keep_alive za Ollama JSON telo.
     *
     * Ollama prihvata keep_alive kao broj sekundi (negativno = beskonačno) ILI
     * kao duration string sa jedinicom ('30m', '2h'). Gola string '-1' NIJE
     * validan duration (time.ParseDuration očekuje jedinicu) → mora kao int -1.
     * Zato: numeričke vrednosti ('-1', '300') vraćamo kao int, ostalo kao string.
     *
     * @return int|string
     */
    private function keepAliveValue(): int|string
    {
        return is_numeric($this->keepAlive) ? (int) $this->keepAlive : $this->keepAlive;
    }

    /**
     * Gradi options niz za Ollama request.
     *
     * Merge logika: config/ai.php options (temperature, num_predict) su baza.
     * Per-request opcije (top_p, repeat_penalty) se dodaju preko.
     * Ako se isti ključ pojavi u oba — per-request pobedi.
     *
     * Ovo je fix za prethodnu verziju gde su temperature i num_predict
     * čitani iz config-a ali nikad prosleđivani Ollami.
     */
    private function buildOptions(): array
    {
        return array_merge(
            $this->modelOptions,        // iz config/ai.php: temperature, num_predict
            [                           // per-request override-i
                'top_p'          => 0.9,
                'repeat_penalty' => 1.1,
                // STOP sekvence (anti-leak): model ume da posle odgovora nastavi
                // da generiše izmišljeni "Pitanje:/Odgovor:" par. Čim pokuša da
                // emituje ove markere, generisanje se prekida.
                'stop'           => ['Pitanje:', 'Pitanje :', 'Korisnik:', 'Korisnik :'],
            ]
        );
    }

    /**
     * Izvršava jedan sinhroni HTTP poziv ka Ollama /api/chat.
     *
     * Koristi Laravel Http facade (Guzzle wrapper) sa http_errors: false
     * da bi greške bile uhvaćene kroz status() a ne kao exception.
     */
    private function doRequest(array $messages): string
    {
        $response = Http::withOptions([
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
            'http_errors'     => false,
        ])
        ->post("{$this->baseUrl}/api/chat", [
            'model'      => $this->model,         // config ai.model (llama3.2:3b)
            'messages'   => $messages,            // istorijat razgovora
            'stream'     => false,                // kompletan odgovor odjednom
            'keep_alive' => $this->keepAliveValue(),
            'options'    => $this->buildOptions(),  // temperature, num_predict, stop...
        ]);

        if (!$response->successful() || empty($response->body())) {
            Log::error('OllamaClient: HTTP greška ili prazan body', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 200),
            ]);
            throw new \RuntimeException('Ollama servis nije dostupan.');
        }

        return $response->json('message.content', '');
    }
}