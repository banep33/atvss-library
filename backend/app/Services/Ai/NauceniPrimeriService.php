<?php

namespace App\Services\Ai;

use App\Models\NauceniPrimer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * NauceniPrimeriService — SEMANTIČKO preuzimanje naučenih FAQ parova (Faza B).
 *
 * Dopuna leksičkog {@see FaqZnanje}: leksičko uparivanje (koren tokena + fuzzy)
 * promaši parafraze bez zajedničkih reči („osoba koja vodi biblioteku" vs „ko
 * je bibliotekar"). Ovde se pitanje embeduje (isti pipeline kao knjige —
 * RagClient → nomic-embed-text) i najsličniji naučeni parovi ulaze u system
 * prompt. Leksičko ostaje netaknuto; ovaj sloj se DODAJE iznad.
 *
 * Embedding se čuva L2-normalizovan, pa je cosine sličnost običan skalarni
 * proizvod (isti princip kao {@see VectorStore}). Tabela `nauceni_primeri` je
 * mala (kurirani FAQ parovi), pa je pun scan u PHP-u dovoljan — KNN mikroservis
 * (za katalog) se ovde ne koristi.
 *
 * GRACEFUL: ako RAG servis nije dostupan ili tabela ne postoji → prazan rezultat
 * (chat radi dalje, samo bez naučene sekcije).
 */
class NauceniPrimeriService
{
    public function __construct(
        private readonly RagClient $rag,
    ) {}

    /**
     * Top-N naučenih parova najsličnijih pitanju (cosine ≥ prag). Prazan niz
     * kada tabela/servis nisu dostupni ili nijedan par nije dovoljno sličan.
     *
     * @return array<int, array{pitanje: string, odgovor: string}>
     */
    public function retrieve(string $message): array
    {
        if (!$this->available()) {
            return [];
        }

        $redovi = NauceniPrimer::query()
            ->where('aktivan', true)
            ->get(['pitanje', 'odgovor', 'embedding']);

        if ($redovi->isEmpty()) {
            return [];
        }

        $upit = $this->embedQuery($message);

        if ($upit === null) {
            return [];
        }

        $prag      = (float) config('ai.nauceni_prag', 0.75);
        $kandidati = [];

        foreach ($redovi as $r) {
            $emb = $r->embedding;

            if (!is_array($emb) || count($emb) !== count($upit)) {
                continue;
            }

            $score = self::dot($upit, $emb);

            if ($score >= $prag) {
                $kandidati[] = ['pitanje' => $r->pitanje, 'odgovor' => $r->odgovor, 'score' => $score];
            }
        }

        usort($kandidati, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_map(
            fn (array $k) => ['pitanje' => $k['pitanje'], 'odgovor' => $k['odgovor']],
            array_slice($kandidati, 0, max(1, (int) config('ai.nauceni_max', 3)))
        );
    }

    /**
     * Semantički DIREKTAN odgovor (preporučeni put za slab model/CPU): ako je
     * pitanje jako slično jednom kuriranom paru — cosine ≥ prag I jasno odvojen
     * od DRUGOG para po redu (margina) — vrati odgovor tog para DIREKTNO, bez
     * pozivanja modela. Dopuna leksičkog {@see FaqZnanje::direktanOdgovor} za
     * parafraze bez zajedničkih reči.
     *
     * Margina je zaštita: kad su skorovi „zbijeni" (nomic nad srpskim daje
     * bliske cosine), NE okida — radije ništa nego pogrešan kurirani odgovor.
     */
    public function direktanOdgovor(string $message): ?string
    {
        if (!$this->available()) {
            return null;
        }

        $redovi = NauceniPrimer::query()
            ->where('aktivan', true)
            ->get(['odgovor', 'embedding']);

        if ($redovi->isEmpty()) {
            return null;
        }

        $upit = $this->embedQuery($message);

        if ($upit === null) {
            return null;
        }

        $scored = [];
        foreach ($redovi as $r) {
            $emb = $r->embedding;

            if (!is_array($emb) || count($emb) !== count($upit)) {
                continue;
            }

            $scored[] = ['odgovor' => $r->odgovor, 'score' => self::dot($upit, $emb)];
        }

        if (empty($scored)) {
            return null;
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $prag    = (float) config('ai.nauceni_direktno_prag', 0.85);
        $margina = (float) config('ai.nauceni_direktno_margina', 0.03);

        $top   = $scored[0]['score'];
        $drugi = $scored[1]['score'] ?? 0.0;

        if ($top < $prag || ($top - $drugi) < $margina) {
            return null; // nedovoljno siguran / „zbijen" pogodak → prepusti dalje
        }

        return $scored[0]['odgovor'];
    }

    /**
     * Embeduje kurirane parove i upisuje ih (updateOrCreate po pitanje_norm →
     * dedup/supersede). Poziva ga `bot:nauci`.
     *
     * @param  array<int, array{pitanje: string, odgovor: string, izvor?: string}>  $parovi
     * @return int  broj upisanih/ažuriranih parova
     */
    public function sinhronizuj(array $parovi, bool $replace = false): int
    {
        if (empty($parovi)) {
            if ($replace) {
                NauceniPrimer::query()->delete();
            }
            return 0;
        }

        // Batch embed (mikroservis primenjuje istu normalizaciju kao za upit).
        $vektori = $this->rag->embedDocuments(array_map(fn (array $p) => $p['pitanje'], $parovi));

        return DB::transaction(function () use ($parovi, $vektori, $replace) {
            if ($replace) {
                NauceniPrimer::query()->delete();
            }

            $n = 0;
            foreach ($parovi as $i => $p) {
                $vec = self::l2Normalize($vektori[$i] ?? []);

                if ($vec === null) {
                    continue;
                }

                NauceniPrimer::updateOrCreate(
                    ['pitanje_norm' => TextUtils::normalize($p['pitanje'])],
                    [
                        'pitanje'  => $p['pitanje'],
                        'odgovor'  => $p['odgovor'],
                        'embedding' => $vec,
                        'izvor'    => $p['izvor'] ?? 'few-shot',
                        'aktivan'  => true,
                    ]
                );
                $n++;
            }

            return $n;
        });
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function available(): bool
    {
        try {
            return Schema::hasTable('nauceni_primeri');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Embeduje i L2-normalizuje upit; null ako servis padne ili je vektor prazan. */
    private function embedQuery(string $message): ?array
    {
        try {
            $vec = $this->rag->embedDocuments([$message])[0] ?? [];
        } catch (\Throwable $e) {
            Log::warning('[NauceniPrimeri] embed upita nije uspeo — bez naučene sekcije', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return self::l2Normalize($vec);
    }

    /** L2-normalizacija; null za prazan/nulti vektor. @return array<float>|null */
    private static function l2Normalize(array $vector): ?array
    {
        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += (float) $v * (float) $v;
        }

        if ($norm <= 0.0) {
            return null;
        }

        $norm = sqrt($norm);

        return array_map(fn ($v) => (float) $v / $norm, array_values($vector));
    }

    /** Skalarni proizvod (oba vektora su L2-normalizovana → cosine sličnost). */
    private static function dot(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $i => $v) {
            $s += (float) $v * (float) ($b[$i] ?? 0.0);
        }

        return $s;
    }
}
