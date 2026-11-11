<?php

namespace App\Console\Commands;

use App\Services\Ai\FaqZnanje;
use App\Services\Ai\NauceniPrimeriService;
use App\Services\Ai\RagClient;
use App\Services\Ai\TextUtils;
use Illuminate\Console\Command;

/**
 * bot:nauci — puni tabelu `nauceni_primeri` embedovanim FAQ parovima za
 * SEMANTIČKO preuzimanje u chatu (Faza B).
 *
 * Izvor su kurirani P/O parovi iz few-shot fajla (FaqZnanje::sviParovi).
 * Svako pitanje se embeduje (RagClient → nomic-embed-text) i upiše u tabelu
 * (updateOrCreate po normalizovanom pitanju → dedup/supersede).
 *
 * BEZ LEAKAGE-a (tvrdo pravilo): pitanja iz eval/golden/ (golden + held-out)
 * NIKAD ne smeju u znanje bota — svaki par čije se pitanje poklopi sa golden
 * pitanjem se ODBACUJE. Time A/B merenje meri generalizaciju, ne memorizaciju.
 *
 * Preduslovi: Ollama + `ollama pull nomic-embed-text`, Python RAG servis dignut.
 *
 *   php artisan bot:nauci            # upsert (dedup po pitanju)
 *   php artisan bot:nauci --replace  # pun rebuild tabele
 */
class NauciBota extends Command
{
    protected $signature   = 'bot:nauci {--replace : Pun rebuild tabele nauceni_primeri}';
    protected $description = 'Embeduje kurirane FAQ parove u nauceni_primeri (semantičko preuzimanje)';

    public function handle(FaqZnanje $faq, NauceniPrimeriService $svc, RagClient $client): int
    {
        try {
            $health = $client->health();
            $this->info("RAG servis OK — embed model: {$health['model']}, dim: {$health['dim']}");
        } catch (\Throwable $e) {
            $this->error('RAG servis nije dostupan na ' . config('ai.rag_service_url') . '.');
            $this->warn('Pokreni ga prvo: rag_service\\start.bat (i proveri da je Ollama dignuta).');
            return self::FAILURE;
        }

        $parovi = $faq->sviParovi();

        if (empty($parovi)) {
            $this->warn('Nema kuriranih parova u few-shot fajlu — ništa za učenje.');
            return self::SUCCESS;
        }

        // LEAKAGE gard: odbaci parove čije pitanje postoji u golden/held-out setu.
        $golden      = $this->goldenPitanjaNorm();
        $zaUpis      = [];
        $preskoceno  = 0;

        foreach ($parovi as $p) {
            if (in_array(TextUtils::normalize($p['pitanje']), $golden, true)) {
                $preskoceno++;
                continue;
            }
            $zaUpis[] = $p;
        }

        if ($preskoceno > 0) {
            $this->warn("Leakage gard: preskočeno {$preskoceno} parova (poklapaju se sa golden/held-out setom).");
        }

        try {
            $upisano = $svc->sinhronizuj($zaUpis, replace: (bool) $this->option('replace'));
        } catch (\Throwable $e) {
            $this->error("Embedovanje/upis nije uspeo: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("Gotovo. Upisano/ažurirano: {$upisano} parova u nauceni_primeri"
            . ($this->option('replace') ? ' (pun rebuild).' : '.'));

        return self::SUCCESS;
    }

    /**
     * Normalizovana pitanja iz golden + held-out fajlova (leakage gard).
     *
     * @return array<int, string>
     */
    private function goldenPitanjaNorm(): array
    {
        $pitanja = [];

        foreach (['eval/golden/golden.json', 'eval/golden/held-out.json'] as $rel) {
            $putanja = base_path($rel);

            if (!is_file($putanja)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($putanja), true);

            if (!is_array($data)) {
                continue;
            }

            foreach (($data['pitanja'] ?? []) as $p) {
                if (!empty($p['tekst'])) {
                    $pitanja[] = TextUtils::normalize($p['tekst']);
                }
            }
            foreach (($data['parafraze'] ?? []) as $p) {
                if (!empty($p['tekst'])) {
                    $pitanja[] = TextUtils::normalize($p['tekst']);
                }
            }
        }

        return array_values(array_unique($pitanja));
    }
}
