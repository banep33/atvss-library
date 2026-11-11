
{{-- ═══════════════════════════════════════════════════════════
     HTML STRUKTURA
═══════════════════════════════════════════════════════════ --}}

{{-- Floating Action Button --}}
<div id="ai-chat-launcher">
    {{-- Invite dugme — vidljivo na startu --}}
    <button id="ai-chat-invite" aria-label="Otvori AI asistenta">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" width="20" height="20" aria-hidden="true">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <span id="ai-invite-text">Potrebna vam je pomoć? <i class="fa-solid fa-comment"></i></span>
    </button>

</div>

{{-- Chat Panel --}}
<div id="ai-chat-panel" role="dialog" aria-label="AI Asistent" aria-modal="true" hidden>
    {{-- Header --}}
    <div id="ai-chat-header">
        <div id="ai-chat-header-info">
            <div id="ai-chat-avatar">B</div>
            <div>
                <div id="ai-chat-name">Bib</div>
                <div id="ai-chat-status">
                    <span id="ai-status-dot"></span>
                    <span id="ai-status-text">Aktivan</span>
                </div>
            </div>
        </div>
        <button id="ai-chat-close" aria-label="Zatvori chat">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    {{-- Messages --}}
    <div id="ai-chat-messages" role="log" aria-live="polite">
       <div class="ai-msg ai-msg--bot">
           <div class="ai-msg__bubble" id="ai-welcome-msg">
              Zdravo! Ja sam Bib, asistent biblioteke ATVSS. <i class="fa-solid fa-hand"></i>
              Postavite mi pitanje o biblioteci!
           </div>
       </div>
   </div>

    {{-- Typing indicator — tri animirane tačke (efekat kucanja, bez teksta) --}}
    <div id="ai-typing-indicator" hidden aria-label="Asistent kuca odgovor" data-no-tr>
        <div class="ai-typing-bubble">
            <div class="ai-typing-dot"></div>
            <div class="ai-typing-dot"></div>
            <div class="ai-typing-dot"></div>
        </div>
    </div>

    {{-- Input --}}
    <div id="ai-chat-input-area">
        <textarea
            id="ai-chat-input"
            placeholder="Postavite pitanje o biblioteci..."
            rows="1"
            maxlength="1000"
            aria-label="Poruka za AI asistenta"
        ></textarea>
        <button id="ai-chat-send" aria-label="Pošalji poruku" disabled>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                <line x1="22" y1="2" x2="11" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
        </button>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════
     CSS
═══════════════════════════════════════════════════════════ --}}

{{-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════ --}}
{{-- ═══════════════════════════════════════════════════════════
     PODEŠAVANJA + SKRIPTA
     Izgled je u public/css/ai-chat.css (učitava ga layout u <head>-u),
     logika u public/js/ai-chat.js. Ovde ostaje samo ono što zavisi od
     korisnika: da li je gost i koja ruta prima stream.
═══════════════════════════════════════════════════════════ --}}
<script>
    window.__aiChat = {
        guestMode: {{ $guestMode ? 'true' : 'false' }},
        streamUrl: @json($guestMode ? route('ai.guest.chat.stream') : route('ai.chat.stream'))
    };
</script>
<script src="{{ asset_v('js/ai-chat.js') }}" defer></script>