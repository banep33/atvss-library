<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Obaveštenja e-poštom
    |--------------------------------------------------------------------------
    |
    | Obaveštenja u aplikaciji (tabela `notifikacije`) rade uvek. Ovaj prekidač
    | odlučuje samo da li se uz njih šalje i mejl.
    |
    | Isključen je podrazumevano zato što do prvog podešavanja MAIL_* podataka
    | nema kuda da se pošalje: Laravel bi pao na „log" nalog i lične podatke
    | članova upisivao u storage/logs/laravel.log. Uključuje se tek kada je
    | nalog za slanje podešen i proveren komandom `obavestenja:proba`.
    |
    | Prekidač važi za celu aplikaciju. Odjave po pojedinačnom članu nema
    | namerno: šalju se isključivo poruke o ishodu radnje koju je član sam
    | pokrenuo (njegova rezervacija ili pozajmica), dakle transakcione, ne
    | reklamne.
    |
    */

    'mejl' => (bool) env('OBAVESTENJA_MEJL', false),

    /*
    |--------------------------------------------------------------------------
    | Potpis biblioteke u mejlu
    |--------------------------------------------------------------------------
    |
    | Stoji ispod logotipa akademije, u zaglavlju svake poruke. Zaseban je od
    | APP_NAME jer APP_NAME ide i u <title> stranica i u naslov prozora, gde
    | je poželjno kraće ime.
    |
    */

    'naziv_biblioteke' => env(
        'OBAVESTENJA_NAZIV_BIBLIOTEKE',
        'Biblioteka Akademije tehničko-vaspitačkih strukovnih studija'
    ),

    /*
    |--------------------------------------------------------------------------
    | Podsetnici o rokovima
    |--------------------------------------------------------------------------
    |
    | Koliko dana pre isteka roka ide podsetnik. Komanda `obavestenja:podsetnici`
    | traži rokove koji padaju tačno toliko dana od danas, pa tekst poruke
    | („ističe sutra") prati ovu vrednost — menjaj oboje zajedno.
    |
    */

    'dana_pre_isteka' => (int) env('OBAVESTENJA_DANA_PRE_ISTEKA', 1),

];
