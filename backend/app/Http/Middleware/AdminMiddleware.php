<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Korisnik dolazi iz Laravelovog session guard-a; uloga se UVEK čita
        // iz baze, ne iz sesije — promena/oduzimanje uloge važi odmah, a
        // obrisan nalog gubi pristup
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Morate biti prijavljeni.'
                ], 401);
            }
            return redirect()->guest('/login')->with('error', 'Morate biti prijavljeni.');
        }

        if (!$user->isAdmin()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nemate pristup ovoj stranici.'
                ], 403);
            }
            return redirect('/')->with('error', 'Nemate pristup ovoj stranici.');
        }

        return $next($request);
    }
}