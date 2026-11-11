<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Services\Ai\AiChatService;
use App\Services\Ai\TextUtils;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AiChatController — prima korisničke poruke i vraća AI odgovore.
 *
 * Endpoint mapa:
 *  POST /api/ai/chat              → sinhroni odgovor (Tinker, PHPUnit)
 *  POST /api/ai/chat/stream       → SSE streaming za ulogovanog člana
 *  POST /api/ai/guest/chat        → sinhroni odgovor za gosta (testiranje)
 *  POST /api/ai/guest/chat/stream → SSE streaming za gosta
 *
 * Biznis logika (RAG pipeline, message building, injection check, Ollama
 * pozivi) živi u App\Services\Ai\AiChatService. Controller je odgovoran
 * samo za HTTP sloj: validaciju Request-a i JSON/SSE response building.
 *
 * TOK (isti za sva 4 endpointa):
 *   injection check → AiChatService::retrieveContext() (pretprocesiranje +
 *   embedding + vektorska pretraga; strani jezik → fiksno "Nisam Vas razumeo",
 *   bez poziva modelu) → buildMessages() → Ollama (sinhrono ili SSE).
 *
 * set_time_limit(300): na Windowsu limit meri REALNO vreme (ne CPU), a prvi
 * odgovor posle dizanja aplikacije (hladan KV keš modela) ume da traje duže
 * od starih 150s — stream je tada bio ubijan usred generisanja, a ponovljeno
 * pitanje je prolazilo jer je prompt već keširan u Ollami.
 *
 * BEZBEDNOST:
 *  - Oba SSE endpoint-a koriste api.sse middleware grupu (session lock release)
 *  - Rate limiting: 10/min za člana, 5/min za gosta (throttle middleware na ruti)
 *  - Injection guard delegiran u AiChatService::containsInjection()
 *  - History role guard: blokira ubacivanje system role kroz history
 */
class AiChatController extends Controller
{
    private const MAX_MESSAGE_LENGTH  = 1000;

    /**
     * VALIDACIJA prihvata do 20 poruka istorije (JS widget šalje najviše 10,
     * margina je za testove i direktne API pozive), ali se MODELU prosleđuje
     * samo poslednjih 8 (4 razmene).
     *
     * Namerno KRATKO: duga istorija „zarazi" odgovore — model ranije pomenute
     * knjige tretira kao činjenice i za tekuće pitanje (halucinacija
     * postojanja), a raste i vreme odgovora. Podaci za tekuće pitanje su
     * UVEK u system promptu; istorija služi samo za nadovezivanje.
     */
    private const MAX_HISTORY_VALIDATION = 20;
    private const MAX_HISTORY_MODEL      = 8;

    public function __construct(
        private readonly AiChatService $aiChat,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // SINHRONI ENDPOINT-I (testiranje)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/ai/chat
     * Sinhroni endpoint — vraća kompletan odgovor odjednom.
     * Koristiti za Tinker testove i PHPUnit; produkcija koristi /stream.
     */
    public function chat(Request $request): \Illuminate\Http\JsonResponse
    {
        set_time_limit(300);

        $guestMode = $this->aiChat->resolveGuestMode();

        $validated   = $this->validateChatRequest($request);
        $userMessage = trim($validated['message']);

        if ($this->aiChat->containsInjection($userMessage)) {
            Log::warning('[AiChat] Injection detektovan u chat()', [
                'message' => mb_substr($userMessage, 0, 200),
            ]);
            return response()->json([
                'error' => 'Poruka sadrži nedozvoljeni sadržaj.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversation = null;
        $history      = array_slice($validated['history'] ?? [], -self::MAX_HISTORY_MODEL);

        if (!$guestMode) {
            $conversation = $this->aiChat->resolveConversation(
                $validated['conversation_id'] ?? null,
                auth()->id()
            );
            $history = $this->aiChat->historyFromDb($conversation, self::MAX_HISTORY_MODEL);
            $this->aiChat->recordMessage($conversation, 'user', $userMessage);
        }

        // RAG pipeline: pretprocesiranje (translit + dijakritici + langdetect)
        // + leksička i semantička pretraga. Strani jezik / neprepoznata namera
        // → fiksni odgovor (poruka zavisi od razloga), bez poziva modelu.
        // Istorija ide i u klasifikaciju namere (razrešavanje nastavaka).
        $ctx = $this->aiChat->retrieveContext($userMessage, $history);
        if (!$ctx['understood']) {
            $poruka   = $ctx['poruka'] ?? TextUtils::NOT_UNDERSTOOD;
            $response = ['reply' => $poruka];
            if ($conversation) {
                $this->aiChat->recordMessage($conversation, 'assistant', $poruka);
                $response['conversation_id'] = $conversation->id;
            }
            return response()->json($response);
        }

        $messages = $this->aiChat->buildMessages($userMessage, $history, $ctx);

        try {
            // Deterministička validacija izlaza: nevalidni linkovi → običan tekst.
            $reply = $this->aiChat->validirajOdgovor($this->aiChat->getReply($messages), $ctx);

            $response = ['reply' => $reply];

            if ($conversation) {
                $this->aiChat->recordMessage($conversation, 'assistant', $reply);
                $response['conversation_id'] = $conversation->id;
            }

            return response()->json($response);
        } catch (RuntimeException $e) {
            // Stvaran uzrok ide u sistemski log; korisniku samo neutralna poruka
            // bez tehničkih detalja i naziva interne komponente (bib-izmene t. 2).
            Log::warning('[AiChatController] chat() greška', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Trenutno nisam u mogućnosti da odgovorim na ovo pitanje. Pokušajte ponovo kasnije.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * POST /api/ai/guest/chat
     * Sinhroni guest endpoint — uvek guestMode = true.
     */
    public function guestChat(Request $request): \Illuminate\Http\JsonResponse
    {
        set_time_limit(300);

        $validated   = $this->validateChatRequest($request);
        $userMessage = trim($validated['message']);

        if ($this->aiChat->containsInjection($userMessage)) {
            Log::warning('[AiChat] Injection detektovan u guestChat()', [
                'message' => mb_substr($userMessage, 0, 200),
            ]);
            return response()->json(['error' => 'Poruka sadrži nedozvoljeni sadržaj.'], 422);
        }

        $history = array_slice($validated['history'] ?? [], -self::MAX_HISTORY_MODEL);

        // RAG pipeline; strani jezik / neprepoznata namera → fiksni odgovor.
        $ctx = $this->aiChat->retrieveContext($userMessage, $history);
        if (!$ctx['understood']) {
            return response()->json(['reply' => $ctx['poruka'] ?? TextUtils::NOT_UNDERSTOOD]);
        }

        $messages = $this->aiChat->buildMessages($userMessage, $history, $ctx);

        try {
            // Deterministička validacija izlaza: nevalidni linkovi → običan tekst.
            $reply = $this->aiChat->validirajOdgovor($this->aiChat->getReply($messages), $ctx);
            return response()->json(['reply' => $reply]);
        } catch (RuntimeException $e) {
            Log::warning('[AiChatController] guestChat() greška', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Trenutno nisam u mogućnosti da odgovorim na ovo pitanje. Pokušajte ponovo kasnije.',
            ], 503);
        }
    }

    /**
     * POST /api/ai/eval/chat  (Faza D — SAMO lokalno, iza AI_EVAL_ENABLED + token)
     *
     * Isti pipeline kao guestChat, ali BEZ throttle-a — da harness pusti ceo
     * golden+held-out set brzo. Opcioni `member_id` impersonira člana SAMO za
     * ovaj zahtev (auth()->onceUsingId — bez sesije), da se član-tagovana golden
     * pitanja mere u ČLANSKOM režimu (inače ih guest tok hvata kao auth-gate i
     * merenje je nepošteno). Ruta je token-gated i lokalna (EnsureEvalToken), pa
     * je impersonacija bezbedna; u produkciji ruta ne postoji.
     */
    public function evalChat(Request $request): \Illuminate\Http\JsonResponse
    {
        set_time_limit(300);

        $validated   = $this->validateChatRequest($request);
        $userMessage = trim($validated['message']);

        if ($this->aiChat->containsInjection($userMessage)) {
            Log::warning('[AiChat] Injection detektovan u evalChat()', [
                'message' => mb_substr($userMessage, 0, 200),
            ]);
            return response()->json(['error' => 'Poruka sadrži nedozvoljeni sadržaj.'], 422);
        }

        // Impersonacija člana za merenje član-pitanja (važi samo za ovaj request).
        $memberId = (int) $request->input('member_id', 0);
        if ($memberId > 0 && \App\Models\User::whereKey($memberId)->exists()) {
            auth()->onceUsingId($memberId);
        }

        // A/B override (samo eval ruta): `nauceni=0|1` privremeno pali/gasi
        // semantičke naučene primere za OVAJ zahtev — da harness izmeri OFF i ON
        // pod istim uslovima, bez prebacivanja .env flaga na sred seta.
        if ($request->has('nauceni')) {
            config(['ai.nauceni_primeri' => $request->boolean('nauceni')]);
        }

        $history = array_slice($validated['history'] ?? [], -self::MAX_HISTORY_MODEL);

        $ctx = $this->aiChat->retrieveContext($userMessage, $history);
        if (!$ctx['understood']) {
            return response()->json(['reply' => $ctx['poruka'] ?? TextUtils::NOT_UNDERSTOOD]);
        }

        $messages = $this->aiChat->buildMessages($userMessage, $history, $ctx);

        try {
            $reply = $this->aiChat->validirajOdgovor($this->aiChat->getReply($messages), $ctx);
            return response()->json(['reply' => $reply]);
        } catch (RuntimeException $e) {
            Log::warning('[AiChatController] evalChat() greška', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'AI servis trenutno nije dostupan.'], 503);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SSE STREAMING ENDPOINT-I
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/ai/chat/stream
     * SSE streaming za ulogovanog člana.
     */
    public function stream(Request $request): StreamedResponse
    {
        set_time_limit(300);

        $guestMode = $this->aiChat->resolveGuestMode();

        $validated   = $this->validateStreamRequest($request);
        $userMessage = trim($validated['message']);

        if ($this->aiChat->containsInjection($userMessage)) {
            Log::warning('[AiChat] Injection detektovan u stream()', [
                'message' => mb_substr($userMessage, 0, 200),
            ]);
            return $this->sseErrorResponse('Poruka sadrži nedozvoljeni sadržaj.');
        }

        $conversation = null;
        $history      = array_slice($validated['history'] ?? [], -self::MAX_HISTORY_MODEL);

        if (!$guestMode) {
            $conversation = $this->aiChat->resolveConversation(
                $validated['conversation_id'] ?? null,
                auth()->id()
            );
            $history = $this->aiChat->historyFromDb($conversation, self::MAX_HISTORY_MODEL);
            $this->aiChat->recordMessage($conversation, 'user', $userMessage);
        }

        // RAG pipeline; strani jezik / neprepoznata namera → fiksni odgovor preko SSE.
        $ctx = $this->aiChat->retrieveContext($userMessage, $history);
        if (!$ctx['understood']) {
            $poruka = $ctx['poruka'] ?? TextUtils::NOT_UNDERSTOOD;
            if ($conversation) {
                $this->aiChat->recordMessage($conversation, 'assistant', $poruka);
            }
            session()->save();
            return $this->sseFixedReply($poruka, $conversation);
        }

        $messages = $this->aiChat->buildMessages($userMessage, $history, $ctx);

        // Oslobodi session lock pre streaminga.
        // Session je pročitana kroz resolveGuestMode() i više nije potrebna.
        // Bez ovoga PHP drži fajl zaključan tokom celog streama (~30-120s),
        // blokirajući sve ostale requestove od istog korisnika.
        session()->save();

        return $this->doStream($messages, $conversation, $this->aiChat->dozvoljeneKnjige($ctx));
    }

    /**
     * POST /api/ai/guest/chat/stream
     * SSE streaming za nelogovanog posetioca — uvek guestMode = true.
     */
    public function guestStream(Request $request): StreamedResponse
    {
        set_time_limit(300);

        $validated   = $this->validateStreamRequest($request);
        $userMessage = trim($validated['message']);

        if ($this->aiChat->containsInjection($userMessage)) {
            Log::warning('[AiChat] Injection detektovan u guestStream()', [
                'message' => mb_substr($userMessage, 0, 200),
            ]);
            return $this->sseErrorResponse('Poruka sadrži nedozvoljeni sadržaj.');
        }

        $history = array_slice($validated['history'] ?? [], -self::MAX_HISTORY_MODEL);

        // RAG pipeline; strani jezik / neprepoznata namera → fiksni odgovor preko SSE.
        $ctx = $this->aiChat->retrieveContext($userMessage, $history);
        if (!$ctx['understood']) {
            session()->save();
            return $this->sseFixedReply($ctx['poruka'] ?? TextUtils::NOT_UNDERSTOOD);
        }

        $messages = $this->aiChat->buildMessages($userMessage, $history, $ctx);

        // Guest nema session data koja je potrebna posle ovog mesta,
        // ali session()->save() je ipak pozvan zbog konzistentnosti:
        // ako gost dobije sesiju (CSRF, flash data), i tu treba da se otpusti lock.
        session()->save();

        return $this->doStream($messages, null, $this->aiChat->dozvoljeneKnjige($ctx));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATNI CORE — SHARED SSE LOGIKA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Izvršava SSE streaming — ovo je jedino mesto gde živi SSE output logika.
     *
     * Prethodna verzija: ~60 identičnih linija duplikovano u stream() i guestStream().
     * Sada: jedan private metod, pozivaju ga oba public endpoint-a.
     *
     * Redosled koraka i zašto je svaki potreban:
     *
     *  1. zlib.output_compression off
     *     → zlib na Windowsu "guta" flush() pozive — stream stiže odjednom na kraju
     *
     *  2. ob_end_clean() while petlja
     *     → Laravel može imati više nivoa output bufferinga (ob_get_level() > 1)
     *     → Jedan ob_end_clean() skida samo sloj sa vrha, ostali i dalje bufferuju
     *     → Petlja garantuje kompletno čišćenje pre starta streama
     *
     *  3. ini_set output_buffering + implicit_flush
     *     → Deluje samo na tekući request, ne menja php.ini globalno
     *     → implicit_flush: svaki echo automatski ide na output bez flush() poziva
     *     → Ostavljamo i eksplicitne flush() kao dupli sloj sigurnosti
     *
     *  4. 2KB padding
     *     → Browser (Chrome, Safari) čeka minimum bajtova pre nego što počne
     *        da prikazuje SSE stream — padding osigurava da se prikazivanje
     *        počne odmah čim stigne prvi pravi token
     *
     *  5. ob_flush() check u petlji
     *     → Siguran call jer smo uklonili sve buffere gore
     *     → Poziva se samo ako je neki buffer slučajno otvoren posle čišćenja
     *
     * X-Conversation-Id header (kad postoji $conversation) se postavlja PRE
     * starta stream-a — već je resolved u stream(), a frontend čita response
     * header preko fetch+ReadableStream (ne EventSource), pa ovo ne zahteva
     * izmenu SSE token formata.
     *
     * Tokeni prolaze kroz AiChatService::streamReplyValidiran — linkovi ka
     * knjigama van konteksta pitanja svode se na običan tekst U LETU, pre
     * slanja korisniku (deterministička validacija, bez drugog LLM prolaza).
     *
     * @param  array               $messages      Ollama message format (system + history + user)
     * @param  AiConversation|null $conversation  Konverzacija člana, null za gosta
     * @param  array<int, int>     $dozvoljeneKnjige ID-jevi knjiga iz konteksta (validacija linkova)
     */
    private function doStream(array $messages, ?AiConversation $conversation = null, array $dozvoljeneKnjige = []): StreamedResponse
    {
        $aiChat = $this->aiChat;

        // NAPOMENA: 'Connection: close' NAMERNO izostavljen — hop-by-hop
        // zaglavlje zabranjeno u HTTP/2 (iza Traefik-a). Slanje ga obara
        // browser sa ERR_HTTP2_PROTOCOL_ERROR. SSE ga ne zahteva.
        $headers = [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];

        if ($conversation) {
            $headers['X-Conversation-Id'] = (string) $conversation->id;
        }

        return response()->stream(function () use ($messages, $aiChat, $conversation, $dozvoljeneKnjige) {

            // ── 1. Isključi zlib kompresiju ──────────────────────────────────
            @ini_set('zlib.output_compression', '0');

            // ── 2. Ukloni sve PHP output buffere ────────────────────────────
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // ── 3. Isključi buffering, uključi implicit flush ────────────────
            ini_set('output_buffering', 'Off');
            ini_set('implicit_flush', '1');

            ignore_user_abort(true);

            // ── 4. Padding (2KB) da browser počne prikazivanje odmah ─────────
            echo ': ' . str_repeat(' ', 2048) . "\n\n";
            flush();

            // ── 5. Generisanje + IZLAZNI GUARD ────────────────────────────────
            //     Bafer ceo odgovor (link/ekavica filteri rade u
            //     streamReplyValidiran), pa VALIDIRAJ pre slanja — živi widget
            //     dobija istu zaštitu kao eval (hrvatski/nonsens/izmišljene
            //     knjige/jezik → fallback). Cena: odgovor stiže posle
            //     generisanja (bez „kucanja" u toku), pa se emituje po rečima.
            try {
                $sirovi = '';
                foreach ($aiChat->streamReplyValidiran($messages, $dozvoljeneKnjige) as $token) {
                    $sirovi .= $token;
                }

                $fullReply = $aiChat->validirajStreamOdgovor($sirovi);

                foreach (preg_split('/(\s+)/u', $fullReply, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$fullReply] as $deo) {
                    if ($deo === '') {
                        continue;
                    }
                    echo 'data: ' . json_encode(['token' => $deo]) . "\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }

                if ($conversation && $fullReply !== '') {
                    $aiChat->recordMessage($conversation, 'assistant', $fullReply);
                }

                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();

            } catch (RuntimeException $e) {
                // Stvaran uzrok (Ollama/mikroservis) ostaje u logu; korisniku
                // se šalje samo neutralna poruka bez tehničkih detalja (t. 2).
                Log::warning('[AiChatController] Stream prekinut', [
                    'error' => $e->getMessage(),
                ]);
                echo 'data: ' . json_encode(['error' => 'Trenutno nisam u mogućnosti da odgovorim na ovo pitanje. Pokušajte ponovo kasnije.']) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }

        }, 200, $headers);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATNI HELPERI
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validacija za sinhroni chat endpoint.
     * History max 10 — sinhroni se češće koristi za kratke testove.
     */
    private function validateChatRequest(Request $request): array
    {
        return $request->validate([
            'message'           => ['required', 'string', 'max:' . self::MAX_MESSAGE_LENGTH],
            'history'           => ['nullable', 'array', 'max:' . self::MAX_HISTORY_VALIDATION],
            'history.*.role'    => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:2000'],
            'conversation_id'   => ['nullable', 'integer'],
        ]);
    }

    /**
     * Validacija za SSE streaming endpoint.
     * History max 20 — streaming podržava duže konverzacije.
     */
    private function validateStreamRequest(Request $request): array
    {
        return $request->validate([
            'message'           => ['required', 'string', 'max:' . self::MAX_MESSAGE_LENGTH],
            'history'           => ['nullable', 'array', 'max:' . self::MAX_HISTORY_VALIDATION],
            'history.*.role'    => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:2000'],
            'conversation_id'   => ['nullable', 'integer'],
        ]);
    }

    /**
     * Vraća minimalni SSE error response za slučajeve kada stream ne može
     * ni da počne (injection, pre-stream validacija).
     */
    private function sseErrorResponse(string $message): StreamedResponse
    {
        return response()->stream(function () use ($message) {
            echo 'data: ' . json_encode(['error' => $message]) . "\n\n";
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Vraća fiksnu poruku kao normalan (ne-error) SSE odgovor — bot ispisuje
     * tekst i završava. Koristi se za strani jezik (langdetect u mikroservisu),
     * gde ne zovemo model već vraćamo fiksni tekst "Nisam Vas razumeo".
     *
     * Format je isti kao kod doStream() (token + [DONE]) da bi ga JS widget
     * renderovao kao običnu poruku bota.
     */
    private function sseFixedReply(string $message, ?AiConversation $conversation = null): StreamedResponse
    {
        // NAPOMENA: 'Connection: close' NAMERNO izostavljen — hop-by-hop
        // zaglavlje zabranjeno u HTTP/2 (iza Traefik-a). Slanje ga obara
        // browser sa ERR_HTTP2_PROTOCOL_ERROR. SSE ga ne zahteva.
        $headers = [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];
        if ($conversation) {
            $headers['X-Conversation-Id'] = (string) $conversation->id;
        }

        return response()->stream(function () use ($message) {
            @ini_set('zlib.output_compression', '0');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo ': ' . str_repeat(' ', 2048) . "\n\n";
            echo 'data: ' . json_encode(['token' => $message]) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
        }, 200, $headers);
    }
}
