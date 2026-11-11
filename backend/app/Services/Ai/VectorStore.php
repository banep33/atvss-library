<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * VectorStore — KANONSKO SKLADIŠTE vektora u običnoj SQLite tabeli.
 *
 * Tabela `knjige_embeddings` (knjiga_id PK, embedding JSON) živi u glavnoj
 * bazi. KNN PRETRAGA NE ŽIVI OVDE: nju radi Python mikroservis (rag_service,
 * sqlite-vec/vec0) koji iz ove tabele gradi svoj indeks (čita je read-only) —
 * PHP samo upisuje/briše vektore pri (re)indeksiranju.
 *
 * ZAŠTO vec0 nije u PHP-u: PDO loadExtension je na ZTS PHP build-ovima
 * (standardni Windows PHP) BLOKIRAN van CLI-ja ("Not supported in
 * multithreaded Web servers") — u Pythonu to ograničenje ne postoji.
 *
 * COSINE: vektori se L2-normalizuju PRI UPISU, pa je cosine sličnost u
 * pretrazi običan skalarni proizvod (prag ai.rag_min_score zadržava isto
 * značenje kroz sve verzije pretrage).
 *
 * GRACEFUL: ako tabela ne postoji (migracije nisu puštene), upisi su no-op
 * — aplikacija radi i bez semantike.
 */
class VectorStore
{
    /** Ime tabele (ujedno bela lista — sprečava SQL injection kroz ime). */
    public const KNJIGE = 'knjige_embeddings';

    private ?bool $available = null;

    public function available(): bool
    {
        if ($this->available === null) {
            try {
                $this->available = Schema::hasTable(self::KNJIGE);
            } catch (\Throwable) {
                $this->available = false;
            }
        }

        return $this->available;
    }

    /**
     * Upsert jednog vektora.
     */
    public function upsert(string $table, int $id, array $vector): void
    {
        if (!$this->available()) {
            return;
        }

        $idCol = $this->idColumn($table);
        $json  = $this->encode($vector);

        if ($json === null) {
            return;
        }

        DB::table($table)->updateOrInsert([$idCol => $id], ['embedding' => $json]);
    }

    /**
     * Bulk upsert. $items: [id => vector]. Ako $replace, prvo prazni tabelu
     * (pun rebuild — uklanja i "siročiće" obrisanih entiteta).
     *
     * @param  array<int, array<float>>  $items
     */
    public function upsertMany(string $table, array $items, bool $replace = false): int
    {
        if (!$this->available()) {
            return 0;
        }

        $idCol = $this->idColumn($table);

        return DB::transaction(function () use ($table, $idCol, $items, $replace) {
            if ($replace) {
                DB::table($table)->delete();
            }

            $n = 0;
            foreach ($items as $id => $vector) {
                $json = $this->encode($vector);
                if ($json === null) {
                    continue;
                }
                DB::table($table)->updateOrInsert([$idCol => (int) $id], ['embedding' => $json]);
                $n++;
            }

            return $n;
        });
    }

    public function remove(string $table, int $id): void
    {
        if (!$this->available()) {
            return;
        }

        DB::table($table)->where($this->idColumn($table), $id)->delete();
    }

    public function reset(string $table): void
    {
        if (!$this->available()) {
            return;
        }

        $this->idColumn($table); // validacija imena
        DB::table($table)->delete();
    }

    public function count(string $table): int
    {
        if (!$this->available()) {
            return 0;
        }

        $this->idColumn($table);
        return DB::table($table)->count();
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** Bela lista tabela → ime PK kolone. Štiti od injekcije kroz ime tabele. */
    private function idColumn(string $table): string
    {
        return match ($table) {
            self::KNJIGE => 'knjiga_id',
            default      => throw new InvalidArgumentException("Nepoznata vektorska tabela: {$table}"),
        };
    }

    /** L2-normalizacija; null za nulti/prazan vektor. @return array<float>|null */
    private function normalizeVector(array $vector): ?array
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

    /** Normalizovan vektor kao JSON string, ili null ako je vektor prazan. */
    private function encode(array $vector): ?string
    {
        $normalized = $this->normalizeVector($vector);

        return $normalized === null
            ? null
            : json_encode($normalized, JSON_THROW_ON_ERROR);
    }
}