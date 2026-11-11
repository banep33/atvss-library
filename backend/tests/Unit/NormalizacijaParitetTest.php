<?php

namespace Tests\Unit;

use App\Services\Ai\TextUtils;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Golden test normalizacije — PHP strana pariteta.
 *
 * Korpus (normalization-golden.json) je snimljen ponašanjem koda PRE
 * prelaska na zajedničku mapu (normalization-map.json): svaka izmena mape
 * ili koda koja promeni izlaz normalizacije pada ovde, bajt-za-bajt.
 *
 * Python stranu istog korpusa proverava rag_service/test_normalizacija.py —
 * zajedno dokazuju da PHP i Python normalizuju IDENTIČNO (uslov da leksički
 * sloj u PHP-u i embedding sloj u Pythonu porede isti oblik teksta).
 *
 * Ako izmena mape NAMERNO menja normalizaciju: regeneriši golden fajl,
 * pusti oba testa i obavezno pokreni `php artisan ai:reindex` (embedinzi
 * su računati nad starim oblikom teksta).
 */
class NormalizacijaParitetTest extends TestCase
{
    /** @return array<int, array{ulaz: string, ocekivano_translit: string, ocekivano_normalize: string}> */
    private function golden(): array
    {
        $putanja = \dirname(__DIR__, 2) . '/resources/normalization/normalization-golden.json';

        $this->assertFileExists($putanja);

        $korpus = json_decode((string) file_get_contents($putanja), true);

        $this->assertIsArray($korpus);
        $this->assertNotEmpty($korpus);

        return $korpus;
    }

    #[Test]
    public function cyrillic_to_latin_odgovara_golden_korpusu(): void
    {
        foreach ($this->golden() as $slucaj) {
            $this->assertSame(
                $slucaj['ocekivano_translit'],
                TextUtils::cyrillicToLatin($slucaj['ulaz']),
                "translit razlika za ulaz: {$slucaj['ulaz']}"
            );
        }
    }

    #[Test]
    public function normalize_odgovara_golden_korpusu(): void
    {
        foreach ($this->golden() as $slucaj) {
            $this->assertSame(
                $slucaj['ocekivano_normalize'],
                TextUtils::normalize($slucaj['ulaz']),
                "normalize razlika za ulaz: {$slucaj['ulaz']}"
            );
        }
    }
}
