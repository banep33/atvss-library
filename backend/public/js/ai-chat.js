/* =============================================================
   AI ASISTENT — logika chata (stream, istorija, panel)
   Ranije inline <script> u partialu (~18 KB po stranici, bez keša).
   Učitava se sa `defer`, pa ne blokira iscrtavanje.
   Zavisi od: window.__aiChat (guestMode, streamUrl) i markup-a iz
   partials/ai-chat-widget.blade.php.
   ============================================================= */

(function () {
    'use strict';

    // ── Konfiguracija ──────────────────────────────────────────────────
    const CSRF_TOKEN       = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    if (!CSRF_TOKEN) {
       console.error('[AI Chat] CSRF token nije pronađen. Proverite da layout ima <meta name="csrf-token">.');
    }
    /* Podešavanja stižu iz malog inline bloka u partialu (window.__aiChat),
       jer zavise od uloge korisnika i Laravel ruta. */
    const PODESAVANJA      = window.__aiChat || {};
    const GUEST_MODE       = !!PODESAVANJA.guestMode;
    const STREAM_URL       = PODESAVANJA.streamUrl || '';
    const MAX_HISTORY      = 10;

    // ── State ──────────────────────────────────────────────────────────
    let conversationHistory = [];
    let conversationId      = null; // ← Faza 2: ID konverzacije u ai_conversations (samo član)
    let isGenerating        = false;
    let currentBotBubble    = null;
    let currentBotText      = '';
    let activeController    = null; // ← NOVO: AbortController referenca

    // ── DOM referenci ──────────────────────────────────────────────────
    const inviteBtn  = document.getElementById('ai-chat-invite');
    const panel      = document.getElementById('ai-chat-panel');
    const closeBtn   = document.getElementById('ai-chat-close');
    const messagesDiv= document.getElementById('ai-chat-messages');
    const inputEl    = document.getElementById('ai-chat-input');
    const sendBtn    = document.getElementById('ai-chat-send');
    const typingInd  = document.getElementById('ai-typing-indicator');

    // ── Panel open/close ───────────────────────────────────────────────
    function openPanel() {
        panel.hidden     = false;
        inviteBtn.hidden = true;
        inputEl.focus();
    }

    function closePanel() {
        panel.hidden     = true;
        inviteBtn.hidden = false;
        if (activeController) {
            activeController.abort();
            activeController = null;
        }
    }

    inviteBtn.addEventListener('click', openPanel);
    closeBtn.addEventListener('click', closePanel);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panel.hidden) closePanel();
    });

    // ── Input handlers ─────────────────────────────────────────────────
    inputEl.addEventListener('input', () => {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + 'px';
        sendBtn.disabled = inputEl.value.trim() === '' || isGenerating;
    });

    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!sendBtn.disabled) sendMessage();
        }
    });

    sendBtn.addEventListener('click', sendMessage);

    // ── TokenRenderer ──────────────────────────────────────────────────
    // Batch-uje DOM write-ove na requestAnimationFrame granicama (jedan
    // write po frame-u umesto po tokenu — nema layout thrashing-a).
    //
    // Linkove knjiga renderuje U TOKU generisanja: umesto sirovog markdown
    // teksta ([Naslov](/knjige/12) — korisnik je video ID knjige dok
    // odgovor stiže), ceo dosadašnji tekst se renderuje kroz renderBotInto,
    // a NEZAVRŠEN link na kraju teksta se zadržava (visiblePortion) dok ne
    // stigne ceo — tada se pojavi odjednom, kao gotov <a> bez vidljivog ID-a.
    class TokenRenderer {
        constructor(element) {
            this.element = element;
            this.text    = '';
            this.frameId = null;
        }

        append(token) {
            this.text += token;
            // Ako već postoji zakazani frame — ne zakazuj novi.
            // Sledeći flush će pokupiti sve tokene koji su stigli u međuvremenu.
            if (!this.frameId) {
                this.frameId = requestAnimationFrame(() => this.flush());
            }
        }

        flush() {
            renderBotInto(this.element, visiblePortion(this.text));
            this.frameId = null;
        }

        // Poziva se na kraju streama — renderuje SVE (link je tada ceo)
        flushSync() {
            if (this.frameId) {
                cancelAnimationFrame(this.frameId);
                this.frameId = null;
            }
            renderBotInto(this.element, this.text);
        }

        destroy() {
            if (this.frameId) {
                cancelAnimationFrame(this.frameId);
                this.frameId = null;
            }
            this.text = '';
        }
    }

    // Deo teksta bezbedan za prikaz tokom streaminga: ako se na kraju
    // nalazi započet, još nezavršen link knjige ("[Naslov](/knjige/12" bez
    // zatvorene zagrade), taj rep se skriva dok link ne stigne ceo. Tekst
    // koji sigurno nije naš link (zatvorena uglasta bez "(" iza, ili
    // predugačak da bi bio link) se pušta — zaštita od zaglavljivanja,
    // isto pravilo kao serverski filter linkova.
    function visiblePortion(text) {
        const start = text.lastIndexOf('[');
        if (start === -1) return text;

        const tail = text.slice(start);
        if (/^\[[^\[\]]*\]\(\/knjige\/\d+\)/.test(tail)) return text;    // link već ceo
        if (/\][^(]/.test(tail) || tail.length > 160)    return text;    // sigurno nije link

        return text.slice(0, start); // link možda još stiže — sakrij rep
    }

    let tokenRenderer = null; // ← NOVO: globalna referenca na renderer

    // ── Pomoćne funkcije ───────────────────────────────────────────────
    function appendMessage(role, content) {
        const div    = document.createElement('div');
        div.className = `ai-msg ai-msg--${role === 'user' ? 'user' : 'bot'}`;
        const bubble  = document.createElement('div');
        bubble.className   = 'ai-msg__bubble';
        bubble.textContent = content;
        div.appendChild(bubble);
        messagesDiv.appendChild(div);
        scrollToBottom();
        return bubble;
    }

    function appendErrorMessage(text) {
        const div    = document.createElement('div');
        div.className = 'ai-msg ai-msg--bot ai-msg--error';
        const bubble  = document.createElement('div');
        bubble.className   = 'ai-msg__bubble';
        bubble.textContent = text;
        div.appendChild(bubble);
        messagesDiv.appendChild(div);
        scrollToBottom();
    }

    function scrollToBottom() {
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    // Faza 2 (RAG): bot citira knjige u formatu [Naslov](/knjige/{id}).
    // Bez markdown biblioteke — system prompt zabranjuje svaku drugu markdown
    // sintaksu (formRule()), pa je dovoljno prepoznati samo taj tačan obrazac.
    //
    // Odgovor modela se NE sklapa u HTML nisku niti dodeljuje preko innerHTML.
    // Čvorovi se prave programski: sav tekst ide kroz createTextNode, gde ga
    // pregledač po definiciji tretira kao podatak, a ne kao oznake, pa faza
    // parsiranja HTML-a nad izlazom modela uopšte i ne postoji. Jedini element
    // koji se kreira jeste veza ka knjizi, čiji se href sastavlja od celog
    // broja PROVERENOG regularnim izrazom, a naslov upisuje kroz textContent.
    // Escape-ovanje niski time nije ni potrebno: ranije rešenje je bilo
    // ispravno, ali je zavisilo od toga da nijedna buduća izmena ne propusti
    // poziv escape funkcije — ovako takva greška nije ni moguća.
    function renderBotInto(element, text) {
        const linkPattern = /\[([^\[\]]+)\]\(\/knjige\/(\d+)\)/g;
        const fragment    = document.createDocumentFragment();
        let lastIndex     = 0;
        let match;

        while ((match = linkPattern.exec(text)) !== null) {
            if (match.index > lastIndex) {
                fragment.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
            }

            const veza = document.createElement('a');
            veza.href        = '/knjige/' + String(parseInt(match[2], 10));
            veza.target      = '_blank';
            veza.rel         = 'noopener noreferrer';
            veza.textContent = match[1];
            fragment.appendChild(veza);

            lastIndex = linkPattern.lastIndex;
        }

        if (lastIndex < text.length) {
            fragment.appendChild(document.createTextNode(text.slice(lastIndex)));
        }

        element.textContent = '';        // ukloni prethodni sadržaj mehurića
        element.appendChild(fragment);
    }

    function setGenerating(val) {
        isGenerating     = val;
        sendBtn.disabled = val || inputEl.value.trim() === '';
        typingInd.hidden = !val;
        inputEl.disabled = val;
        sendBtn.title    = val ? 'Bib generiše odgovor...' : 'Pošalji poruku';
    }

    // ── Glavni tok ─────────────────────────────────────────────────────
    async function sendMessage(retryCount = 0) {
        const message = inputEl.value.trim();
        if (!message || isGenerating) return;

        isGenerating     = true;
        sendBtn.disabled = true;

        appendMessage('user', message);

        currentBotText   = '';
        currentBotBubble = appendMessage('bot', '');

        // ← NOVO: inicijalizuj renderer na novi bubble element
        tokenRenderer = new TokenRenderer(currentBotBubble);

        inputEl.value        = '';
        inputEl.style.height = 'auto';

        conversationHistory.push({ role: 'user', content: message });
        if (conversationHistory.length > MAX_HISTORY) {
            conversationHistory = conversationHistory.slice(-MAX_HISTORY);
        }

        setGenerating(true);
        typingInd.hidden = false;  // ← eksplicitno, ne čekaj token

        // novi AbortController za ovaj zahtev
        activeController = new AbortController();
        let reader = null;

        try {
            const response = await fetch(STREAM_URL, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'text/event-stream',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                body: JSON.stringify({
                    message,
                    history:         conversationHistory.slice(0, -1),
                    guest_mode:      GUEST_MODE,
                    conversation_id: conversationId,
                }),
                signal: activeController.signal, // ← NOVO: abort signal
            });

            if (response.status === 419) {
               showBotError('Sesija je istekla. Osvežite stranicu.');
               return;
            }

            // PREKORAČENJE BROJA UPITA (throttle na ruti → HTTP 429).
            // Mora se obraditi PRE opšteg bacanja izuzetka: inače bi zahtev
            // upao u granu za ponovni pokušaj i korisnik bi četrdeset pet
            // sekundi gledao poruku „Model se učitava" umesto pravog razloga.
            if (response.status === 429) {
                prikaziPrekoracenje(parseInt(response.headers.get('Retry-After'), 10));
                return;
            }

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            // Faza 2: backend vraća conversation_id kroz header (samo za člana —
            // gost nema perzistovanu konverzaciju, header neće biti postavljen).
            const headerConvId = response.headers.get('X-Conversation-Id');
            if (headerConvId) {
                conversationId = parseInt(headerConvId, 10);
            }

            reader = response.body.getReader(); // ← IZMENJENO: čuvamo referencu
            const decoder = new TextDecoder();
            let   buffer  = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                let boundary;
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const eventStr = buffer.slice(0, boundary);
                    buffer         = buffer.slice(boundary + 2);

                    for (const line of eventStr.split('\n')) {
                        if (!line.startsWith('data: ')) continue;
                        const raw = line.slice(6).trim();

                        if (raw === '[DONE]') {
                            tokenRenderer.flushSync(); // ← NOVO: flush pre završetka
                            finishBotMessage();
                            return;
                        }

                        let parsed;
                        try { parsed = JSON.parse(raw); } catch { continue; }

                        if (parsed.error) { showBotError(parsed.error); return; }

                        if (parsed.token) {
                            currentBotText += parsed.token;
                            tokenRenderer.append(parsed.token); // ← IZMENJENO: kroz renderer
                            // scrollToBottom samo jednom po frame, ne po tokenu
                            // renderer.flush() triggeruje jedan layout — scrollamo posle toga
                            requestAnimationFrame(scrollToBottom); // ← IZMENJENO
                            if (!typingInd.hidden) typingInd.hidden = true;
                        }
                    }
                }
            }

            tokenRenderer.flushSync(); // ← NOVO: flush ostatka
            finishBotMessage();

        } catch (err) {
            // ← NOVO: AbortError nije greška — korisnik je namerno prekinuo
            if (err.name === 'AbortError') {
                tokenRenderer?.destroy();
                return;
            }

            console.error('[AI Chat] Greška:', err);

            if (retryCount < 1) {
                currentBotBubble.textContent = 'Model se učitava, trenutak...';
                await new Promise(resolve => setTimeout(resolve, 45_000)); // 45 sekundi
                currentBotBubble.remove();
                tokenRenderer?.destroy(); // ← NOVO: cleanup renderer pre retry-a
                isGenerating     = false;
                sendBtn.disabled = false;
                inputEl.value    = message;
                sendMessage(retryCount + 1);
                return;
            }

            showBotError('Trenutno nisam u mogućnosti da odgovorim na ovo pitanje. Pokušajte ponovo kasnije.');

        } finally {
            // ← NOVO: uvek oslobodi reader ako postoji
            if (reader) {
                try { reader.cancel(); } catch {}
            }
            activeController = null;
            if (isGenerating) setGenerating(false);
        }
    }

    function finishBotMessage() {
        tokenRenderer?.destroy();
        tokenRenderer = null;

        if (currentBotText) {
            conversationHistory.push({ role: 'assistant', content: currentBotText });
            if (conversationHistory.length > MAX_HISTORY) {
                conversationHistory = conversationHistory.slice(-MAX_HISTORY);
            }

            if (currentBotBubble) {
                renderBotInto(currentBotBubble, currentBotText);
            }
        }
        currentBotBubble = null;
        currentBotText   = '';
        setGenerating(false);
        inputEl.focus();
    }

    /**
     * Slaganje broja uz imenicu „sekunda" po pravilu srpskog jezika:
     * 1 → sekundu, 2–4 → sekunde, ostalo → sekundi (uz izuzetak 11–14).
     */
    function sekundeRec(n) {
        const desetice = n % 100, jedinice = n % 10;
        if (desetice >= 11 && desetice <= 14) return 'sekundi';
        if (jedinice === 1)                   return 'sekundu';
        if (jedinice >= 2 && jedinice <= 4)   return 'sekunde';
        return 'sekundi';
    }

    /**
     * Obaveštenje o prekoračenju dozvoljenog broja upita u minuti.
     *
     * Poruka se prikazuje na dva mesta: u samom razgovoru, da ostane vidljiva
     * i pošto obaveštenje nestane, i kroz globalni toast aplikacije — isti
     * onaj koji koriste ostale stranice, da korisnik prepozna poznat oblik
     * obaveštenja. Preostalo vreme čitamo iz zaglavlja Retry-After, koje uz
     * odgovor 429 šalje posrednik za ograničavanje učestalosti.
     */
    function prikaziPrekoracenje(sekundi) {
        const cekanje = Number.isFinite(sekundi) && sekundi > 0
            ? `Pokušajte ponovo za ${sekundi} ${sekundeRec(sekundi)}.`
            : 'Pokušajte ponovo za koji trenutak.';

        // Gost ima nižu granicu od prijavljenog člana, pa mu se uz obaveštenje
        // nudi i način da je podigne.
        const dopuna = GUEST_MODE
            ? ' Prijavljeni članovi mogu da postave veći broj pitanja u minuti.'
            : '';

        showBotError('Postavili ste previše pitanja u kratkom vremenu. ' + cekanje + dopuna);

        if (typeof window.showToast === 'function') {
            window.showToast('Previše upita. ' + cekanje, 'warning', { duration: 6000 });
        }
    }

    function showBotError(message) {
        tokenRenderer?.destroy();
        tokenRenderer = null;

        if (conversationHistory.length > 0 &&
            conversationHistory[conversationHistory.length - 1].role === 'user') {
            conversationHistory.pop();
        }
        if (currentBotBubble) {
            currentBotBubble.parentElement.classList.add('ai-msg--error');
            currentBotBubble.textContent = message;
        } else {
            appendErrorMessage(message);
        }
        setGenerating(false);
    }

    // ── Welcome poruka ─────────────────────────────────────────────────
    const welcomeEl = document.getElementById('ai-welcome-msg');
    if (welcomeEl) {
        welcomeEl.innerHTML = GUEST_MODE
            ? 'Zdravo! Ja sam <strong>Bib</strong>, vaš vodič kroz biblioteku ATVSS. <i class="fa-solid fa-hand"></i><br><br>Mogu da vam pomognem sa opštim informacijama o biblioteci i njenim uslugama.<br><br>Postavite mi pitanje!'
            : 'Zdravo! Ja sam <strong>Bib</strong>, vaš lični asistent biblioteke ATVSS. <i class="fa-solid fa-hand"></i><br><br>Mogu da vam pomognem sa rezervacijama, pravilima korišćenja aplikacije i svim informacijama o našem katalogu knjiga.<br><br>O čemu biste hteli da razgovarate?';
    }

})();
