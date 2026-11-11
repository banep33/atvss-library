<?php

namespace App\Console\Commands;

use App\Services\Ai\AiChatService;
use App\Services\Ai\FaqZnanje;
use App\Services\Ai\TextUtils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * bot:offline-merenje — LAGANO merenje sinonimskog sloja (Faza B′) BEZ modela.
 *
 * Provlači golden + held-out pitanja kroz AiChatService::retrieveContext sa
 * NEUTRALISANOM mrežom (Http::fake — nijedan poziv ka RAG/Ollama). Meri se samo
 * DETERMINISTIČKI deo: pitanja koja dobiju FIKSNI odgovor (understood=false).
 * Za svako se proverava da li fiksni odgovor sadrži golden činjenice. Pušta se
 * dva puta — sinonimi OFF pa ON — pa se vidi šta je sinonimski sloj popravio.
 *
 * Nula opterećenja hardvera: nema inferencije. Član-tagovana pitanja se mere u
 * članskom režimu (onceUsingId), gost-pitanja bez naloga.
 */
class OfflineMerenje extends Command
{
    protected $signature   = 'bot:offline-merenje {--clan=4 : ID test-člana za član-tagovana pitanja}';
    protected $description = 'Offline merenje FAQ sinonimskog sloja (bez modela) na golden+held-out';

    public function handle(): int
    {
        Http::fake(); // RAG/Ollama se ne zovu — leksički/fiksni tok radi i bez njih

        $stavke = $this->ucitajStavke();
        if (empty($stavke)) {
            $this->error('Nema golden/held-out stavki (eval/golden/*.json).');
            return self::FAILURE;
        }

        $default = (string) config('ai.faq_sinonimi_path');
        $off = $this->prolaz($stavke, sinonimiPath: '');        // sinonimi isključeni
        $on  = $this->prolaz($stavke, sinonimiPath: $default);  // sinonimi uključeni

        $this->line('');
        $this->info('OFFLINE (samo deterministički/fiksni odgovori, bez modela)');
        $this->table(
            ['Metrika (fiksni odgovori)', 'sinonimi OFF', 'sinonimi ON'],
            [
                ['tačnih (fakti prolaze)', $off['tacno'], $on['tacno']],
                ['pogrešnih fiksnih',      $off['pogresno'], $on['pogresno']],
                ['ide na model (van ovog merenja)', $off['model'], $on['model']],
            ]
        );

        // Šta je sinonimski sloj popravio: ✗/model → ✓ (novi tačan fiksni odgovor).
        $flips = [];
        foreach ($stavke as $id => $_) {
            if (($off['po_id'][$id] ?? '') !== 'tacno' && ($on['po_id'][$id] ?? '') === 'tacno') {
                $flips[] = [$id, mb_substr($stavke[$id]['tekst'], 0, 60)];
            }
        }
        if ($flips) {
            $this->line('');
            $this->info('Sinonimski sloj popravio (postao tačan FIKSNI odgovor):');
            $this->table(['ID', 'pitanje'], $flips);
        }

        // Sigurnosna provera: fiksni odgovor koji je POGREŠAN (fakti padaju).
        $losi = [];
        foreach ($on['po_id'] as $id => $stanje) {
            if ($stanje === 'pogresno') {
                $losi[] = [$id, mb_substr($stavke[$id]['tekst'], 0, 60)];
            }
        }
        if ($losi) {
            $this->line('');
            $this->warn('PAŽNJA — fiksni odgovori koji ne prolaze fakte (proveriti):');
            $this->table(['ID', 'pitanje'], $losi);
        }

        return self::SUCCESS;
    }

    /**
     * Jedan prolaz kroz sve stavke za datu putanju sinonima. Vraća zbir i
     * stanje po ID-u ('tacno' | 'pogresno' | 'model').
     */
    private function prolaz(array $stavke, string $sinonimiPath): array
    {
        config(['ai.faq_sinonimi_path' => $sinonimiPath]);
        // Sveže instance da FaqZnanje pročita novu putanju sinonima.
        app()->forgetInstance(FaqZnanje::class);
        app()->forgetInstance(AiChatService::class);
        $svc = app()->make(AiChatService::class);

        $clanId = (int) $this->option('clan');
        $rez = ['tacno' => 0, 'pogresno' => 0, 'model' => 0, 'po_id' => []];

        foreach ($stavke as $id => $s) {
            if (($s['tip'] ?? '') === 'clan' && $clanId > 0) {
                Auth::onceUsingId($clanId);
            } else {
                Auth::forgetUser();
            }

            $ctx = $svc->retrieveContext($s['tekst']);

            if ($ctx['understood']) {
                $rez['model']++;
                $rez['po_id'][$id] = 'model';
                continue;
            }

            $stanje = $this->prolaziFakte((string) ($ctx['poruka'] ?? ''), $s['cinjenice'], $s['zabranjeno'])
                ? 'tacno' : 'pogresno';
            $rez[$stanje]++;
            $rez['po_id'][$id] = $stanje;
        }

        Auth::forgetUser();

        return $rez;
    }

    private function prolaziFakte(string $odgovor, array $cinjenice, array $zabranjeno): bool
    {
        $norm = TextUtils::normalize($odgovor);

        foreach ($cinjenice as $c) {
            if (!$this->sadrzi($norm, $c)) {
                return false;
            }
        }
        foreach ($zabranjeno as $z) {
            if ($this->sadrzi($norm, $z)) {
                return false;
            }
        }

        return $cinjenice !== [] || $zabranjeno !== [];
    }

    private function sadrzi(string $norm, string $stavka): bool
    {
        if (str_starts_with($stavka, 're:')) {
            return (bool) preg_match('/' . substr($stavka, 3) . '/u', $norm);
        }

        return str_contains($norm, TextUtils::normalize($stavka));
    }

    /**
     * Golden + held-out stavke iz JSON-a (held-out nasleđuje fakte od roditelja).
     *
     * @return array<string, array{tekst: string, tip: string, cinjenice: array, zabranjeno: array}>
     */
    private function ucitajStavke(): array
    {
        $golden  = json_decode((string) @file_get_contents(base_path('eval/golden/golden.json')), true) ?: [];
        $heldout = json_decode((string) @file_get_contents(base_path('eval/golden/held-out.json')), true) ?: [];

        $stavke = [];
        $poId   = [];

        foreach (($golden['pitanja'] ?? []) as $p) {
            $poId[$p['id']] = $p;
            $stavke[$p['id']] = [
                'tekst'      => $p['tekst'],
                'tip'        => $p['tip_korisnika'] ?? 'guest',
                'cinjenice'  => $p['ocekivane_cinjenice'] ?? [],
                'zabranjeno' => $p['zabranjeno'] ?? [],
            ];
        }

        foreach (($heldout['parafraze'] ?? []) as $pf) {
            $rod = $poId[$pf['roditelj']] ?? [];
            $stavke[$pf['id']] = [
                'tekst'      => $pf['tekst'],
                'tip'        => $rod['tip_korisnika'] ?? 'guest',
                'cinjenice'  => $rod['ocekivane_cinjenice'] ?? [],
                'zabranjeno' => $rod['zabranjeno'] ?? [],
            ];
        }

        return $stavke;
    }
}
