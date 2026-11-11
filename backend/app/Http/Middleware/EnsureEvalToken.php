<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * EnsureEvalToken — štiti eval-only rutu (POST /api/ai/eval/chat) koja NAMERNO
 * nema throttle da bi harness pustio ceo golden+held-out set brzo (Faza D).
 *
 * Ruta se u routes/api.php registruje SAMO kada je config('ai.eval_enabled')
 * true — u produkciji je flag isključen, pa ruta ni ne postoji. Ovaj
 * middleware je drugi sloj: zahteva tačan X-Eval-Token (poređenje otporno na
 * timing napad, hash_equals — isti princip kao /eknjige/verify), pa čak i da
 * ruta procuri upaljena, bez tokena vraća 403.
 *
 * CSRF OSTAJE (ruta je u „api" grupi) — token je dodatni gejt, ne zamena.
 */
class EnsureEvalToken
{
    public function handle(Request $request, Closure $next)
    {
        $ocekivani = (string) config('ai.eval_token', '');

        // Flag ugašen ili token nije podešen → ruta se tretira kao da ne postoji.
        if (!config('ai.eval_enabled') || $ocekivani === '') {
            abort(404);
        }

        $poslati = (string) $request->header('X-Eval-Token', '');

        if (!hash_equals($ocekivani, $poslati)) {
            return response()->json(['error' => 'Neispravan eval token.'], 403);
        }

        return $next($request);
    }
}
