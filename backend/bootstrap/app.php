<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // Iza reverse proxy-ja (nginx/Apache) pravu IP korisnika i "je li
        // HTTPS" nosi X-Forwarded-* zaglavlje. Bez ovoga bi $request->ip()
        // vratio IP proxy-ja — svi korisnici bi delili jedan brojač i login
        // rate limiter bi blokirao SVE odjednom, a secure() bi bio false.
        // TRUSTED_PROXIES=* je bezbedno samo ako aplikaciji pristupa isključivo
        // preko proxy-ja (standardno kod deploy-a); inače navesti IP proxy-ja.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES') ?: null,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        // AuthenticateSession čuva heš lozinke u sesiji i poništava svaku
        // sesiju čiji heš više ne odgovara — promena lozinke tako odjavljuje
        // sve OSTALE uređaje (podloga za Auth::logoutOtherDevices)
        $middleware->web(append: [
            \Illuminate\Session\Middleware\AuthenticateSession::class,
        ]);

        // Standardna API grupa — sa cookie i session middleware-ima.
        // ValidateCsrfToken IDE POSLE StartSession (token živi u sesiji):
        // API rute su autentifikovane cookie-jem pa moraju imati CSRF zaštitu,
        // frontend već šalje X-CSRF-TOKEN header uz svaki mutirajući zahtev
        $middleware->appendToGroup('api', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);

        // Sigurnosna HTTP zaglavlja na svim odgovorima (web + api)
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // `pismo` upisuje JavaScript (document.cookie), pa ne sme da ide kroz
        // Laravel-ovo šifrovanje kolačića — inače server dobije vrednost koju
        // ne može da dešifruje i tretira je kao da je nema. Kolačić ne nosi
        // ništa osetljivo: samo 'lat' ili 'cyr'.
        // `bio_prijavljen` je zastavica bez ikakvog podatka (vrednost je „1"):
        // služi samo da se posle isteka sesije zna da je pred nama korisnik
        // koji je BIO prijavljen, pa mu se kaže „Sesija je istekla" umesto
        // „Morate biti prijavljeni". Šifrovanje tu ništa ne bi štitilo, a
        // podmetanje kolačića ne donosi ništa osim druge poruke na prijavi.
        $middleware->encryptCookies(except: [
            \App\Http\Middleware\PreslovljavanjePisma::KOLACIC,
            \App\Http\Controllers\Auth\LoginController::TRAG_PRIJAVE,
        ]);

        // Preslovljavanje HTML odgovora u ćirilicu kada korisnik ima taj izbor
        // u kolačiću. Ide na `web`, ne globalno: API vraća JSON, a stream
        // asistenta se ne bafferuje. Ranije je posao radio pismo.js tek posle
        // prvog iscrtavanja, pa se videlo prebacivanje latinica -> ćirilica.
        $middleware->web(append: [
            \App\Http\Middleware\PreslovljavanjePisma::class,
        ]);

        // SSE grupa — samo session, bez cookie middleware-a koji bufferuju response
        $middleware->appendToGroup('api.sse', [
            \Illuminate\Session\Middleware\StartSession::class,
        ]);

        $middleware->alias([
            'admin'       => \App\Http\Middleware\AdminMiddleware::class,
            'bibliotekar' => \App\Http\Middleware\BibliotekarMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Neulogovan korisnik na zaštićenoj ruti (ugrađeni 'auth' middleware
        // baca AuthenticationException): API dobija JSON 401 — i kada fetch
        // ne šalje Accept header — a stranice redirect na login. redirect()
        // ->guest() pamti odakle je korisnik došao, pa ga login vraća tamo.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // Kolačić `bio_prijavljen` nadživljava sesiju (postavlja ga prijava,
            // briše odjava), pa razdvaja gosta koji je otvorio zaštićen link od
            // korisnika kome je sesija upravo istekla. Bez te razlike bi obojica
            // dobila „Morate biti prijavljeni", a onaj drugi ne bi saznao ZAŠTO
            // je odjednom odjavljen.
            $istekla = (bool) $request->cookie(LoginController::TRAG_PRIJAVE);

            $poruka = $istekla
                ? 'Sesija je istekla. Prijavite se ponovo da biste nastavili rad.'
                : 'Morate biti prijavljeni.';

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error'   => $poruka,
                    // Zastavica za frontend (public/js/notifications.js): kada je
                    // true, prikazuje se obaveštenje i sledi prelazak na prijavu.
                    'sesija_istekla' => $istekla,
                ], 401);
            }

            $odgovor = redirect()->guest('/login')->with('error', $poruka);

            // Poruka o isteku se prikazuje jednom — trag se briše čim je
            // isporučena, da je gost ne bi video na svakoj sledećoj stranici.
            return $istekla
                ? $odgovor->withCookie(Cookie::forget(LoginController::TRAG_PRIJAVE))
                : $odgovor;
        });

        // Neispravan CSRF token u praksi znači jedno: sesija je istekla dok je
        // forma stajala otvorena. Stranica (resources/views/errors/419.blade.php)
        // to i kaže, umesto Laravel-ove engleske „419 | Page Expired"; API
        // pozivi dobijaju istu poruku kao JSON, da je frontend prikaže kao toast.
        //
        // Tip je HttpException, a ne TokenMismatchException: Laravel prvo kroz
        // prepareException() pretvori TokenMismatch u HttpException(419), pa
        // tek onda zove ove rukovaoce — na originalni tip se nikada ne bi
        // poklopilo. Ostali HTTP statusi se propuštaju (`return null`).
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error'          => 'Sesija je istekla. Prijavite se ponovo da biste nastavili rad.',
                    'sesija_istekla' => true,
                ], 419);
            }

            // Content-Type se navodi izričito: response()->view() ga ostavlja
            // prazan do slanja, a PreslovljavanjePisma po njemu prepoznaje
            // stranicu koju treba preslovljati u ćirilicu.
            return response()
                ->view('errors.419', [], 419, ['Content-Type' => 'text/html; charset=UTF-8'])
                ->withCookie(Cookie::forget(LoginController::TRAG_PRIJAVE));
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 600);

            // Dva različita razloga traže i dve različite poruke. Rute asistenta
            // ograničene su po MINUTI (deset upita za člana, pet za gosta), pa se
            // preostalo vreme iskazuje u sekundama i ne pominju se „neuspeli
            // pokušaji" — korisnik nije pogrešio, već je bio brz. Kod prijave i
            // promene lozinke blokada traje više minuta, pa je minut prirodnija
            // jedinica. Zaglavlje Retry-After u oba slučaja nosi tačan broj
            // sekundi, iz kojeg klijentska strana sama sastavlja odbrojavanje.
            if ($request->is('api/ai/*')) {
                $jedinice = $seconds % 10;
                $desetice = $seconds % 100;
                $rec = match (true) {
                    $desetice >= 11 && $desetice <= 14 => 'sekundi',
                    $jedinice === 1                    => 'sekundu',
                    $jedinice >= 2 && $jedinice <= 4   => 'sekunde',
                    default                            => 'sekundi',
                };
                $poruka = 'Postavili ste previše pitanja u kratkom vremenu. '
                        . "Pokušajte ponovo za {$seconds} {$rec}.";
            } else {
                $minutes = (int) ceil($seconds / 60);
                $poruka  = "Previše neuspelih pokušaja. Pokušajte ponovo za {$minutes} " .
                           ($minutes === 1 ? 'minut' : 'minuta') . '.';
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => $poruka], 429, ['Retry-After' => $seconds]);
            }

            // back() a ne fiksno /login: blokada sada postoji i na rutama za
            // ulogovane (promena lozinke) — korisnik poruku vidi tamo gde je
            // i pokušao. Za POST /login "nazad" je ionako login stranica.
            return redirect()->back()
                ->withErrors(['throttle' => $poruka])
                ->with('error', $poruka)
                ->with('throttle_seconds', $seconds);
        });
    })->create();