<?php

/**
 * URL statičkog fajla sa verzijom koja se menja kada se fajl promeni.
 *
 * nginx na produkciji servira /css i /js sa
 * `Cache-Control: public, max-age=31536000, immutable`. `immutable` znači da
 * pregledač NEĆE ni pitati da li ima novija verzija, pa su ETag i Last-Modified
 * bez efekta — korisnik koji je aplikaciju već posetio zadržao bi stari
 * theme.js ili admin.css godinu dana, bez obzira na deploy. Verzija u upitnom
 * delu URL-a pravi novu adresu čim se fajl promeni, pa deploy probije keš, a
 * dugo keširanje ostaje za sve što se nije menjalo.
 *
 * Ovim se ujedno gasi i `?v={{ time() }}`, koje je stajalo na dva CSS fajla:
 * time() se menja pri SVAKOM zahtevu, pa ta dva fajla nikada nisu koristila
 * keš — ni na produkciji.
 *
 * Napomena: verzija je `filemtime`. U CI-ju se repozitorijum klonira iznova,
 * pa svi fajlovi dobiju isto novo vreme izmene i posle deploy-a se preuzimaju
 * svi, a ne samo izmenjeni. To je bezbedna strana greške i u praksi jeftino
 * (ukupno ~350 KB CSS-a i JS-a).
 */
if (!function_exists('asset_v')) {
    function asset_v(string $path): string
    {
        /* Statički keš: ista stranica traži theme.css/theme.js po jednom,
           ali partiali se uključuju više puta, pa se stat() ne ponavlja. */
        static $verzije = [];

        if (!array_key_exists($path, $verzije)) {
            $puna = public_path($path);
            $verzije[$path] = is_file($puna) ? (string) filemtime($puna) : null;
        }

        $url = asset($path);

        /* Fajl ne postoji (pogrešna putanja) — vrati adresu bez verzije,
           da se greška vidi kao 404, a ne kao 404 sa čudnim upitom. */
        if ($verzije[$path] === null) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $verzije[$path];
    }
}
