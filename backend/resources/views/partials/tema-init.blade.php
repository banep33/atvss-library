{{-- ═══════════════════════════════════════════════════════════════════
     TEMA PRE PRVOG ISCRTAVANJA

     Izbor teme živi u localStorage-u, pa ga server ne zna — mora da ga
     pročita skripta, i to PRE nego što pregledač bilo šta iscrta. Zato
     ovaj snippet stoji u <head>-u, izvršava se sinhrono i nije zaseban
     fajl (spoljna skripta bi se čekala, i to na svakoj stranici).

     Postavlja DVE stvari na <html>:
       - klasu `dark`  — paleta (tokeni u core.css),
       - `data-tema`   — koji je režim izabran (light/dark/system).

     `data-tema` postoji da bi se i KONTROLE mogle iscrtati odmah tačne:
     ikonica na dugmetu za temu bira se u CSS-u po ovom atributu. Dok je
     ikonicu postavljao theme.js na DOMContentLoaded, u tamnom režimu se
     na trenutak videlo sunce pa tek onda mesec — i to na svakoj promeni
     stranice.

     Vrednost u atributu na <html> (`data-tema="system"`) je rezerva za
     slučaj da localStorage nije dostupan (privatni režim, isključeni
     kolačići), pa snippet pukne pre nego što bilo šta upiše.
     ═══════════════════════════════════════════════════════════════════ --}}
<script>(function(){try{var t=localStorage.getItem('theme')||'system';var d=document.documentElement;d.setAttribute('data-tema',t);if(t==='dark'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches))d.classList.add('dark');}catch(e){}})();</script>
