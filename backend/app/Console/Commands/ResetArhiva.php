<?php

namespace App\Console\Commands;

use App\Models\Arhiva;
use Illuminate\Console\Command;

class ResetArhiva extends Command
{
    protected $signature   = 'arhiva:reset {--force : Preskoci potvrdu}';
    protected $description = 'Briše kompletnu arhivu. Samo za server konzolu.';

    public function handle()
    {
        $count = Arhiva::count();

        if (!$this->option('force')) {
            $potvrda = $this->ask(
                "Ovo će obrisati {$count} zapisa iz arhive. Ukucaj POTVRDI da nastaviš"
            );
            if ($potvrda !== 'POTVRDI') {
                $this->info('Operacija otkazana.');
                return;
            }
        }

        Arhiva::truncate();
        $this->info("Arhiva obrisana ({$count} zapisa).");
        \App\Helpers\ActivityLogger::log('arhiva_reset', "Obrisano {$count} zapisa iz arhive");
    }
}