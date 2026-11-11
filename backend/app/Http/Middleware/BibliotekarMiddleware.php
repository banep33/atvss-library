<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BibliotekarMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Korisnik dolazi iz Laravelovog session guard-a; uloga se UVEK čita
        // iz baze, ne iz sesije — promena/oduzimanje uloge važi odmah, a
        // obrisan nalog gubi pristup
        $user = $request->user();

        // Neprijavljen korisnik → 401 / login, bez otkrivanja da ruta postoji
        if (!$user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'Morate biti prijavljeni.'], 401);
            }
            return redirect()->guest('/login')->with('error', 'Morate biti prijavljeni.');
        }

        if (!$user->isAdminOrBibliotekar()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'Nemate pristup.'], 403);
            }
            return redirect('/')->with('error', 'Nemate pristup ovoj stranici.');
        }

        return $next($request);
    }
}