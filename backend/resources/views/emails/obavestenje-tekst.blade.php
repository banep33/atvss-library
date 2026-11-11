{{-- Tekstualna verzija iste poruke.

     Šalje se uporedo sa HTML-om, kao drugi deo iste pošiljke. Klijent bira
     šta ume da prikaže, a filteri neželjene pošte bolje ocenjuju poruku koja
     ima oba oblika nego onu koja je samo HTML. --}}
{{ $naslov }}

Poštovani/a {{ $ime }},

{{ $poruka }}

Srdačan pozdrav!

--
{{ $nazivBiblioteke }}
Ovo je automatska poruka, na nju se ne odgovara.
