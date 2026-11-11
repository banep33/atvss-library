<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetKnjige extends Command
{
    protected $signature   = 'knjige:reset {--force : Preskoci potvrdu}';
    protected $description = 'Briše sve knjige i povezane podatke. Samo za server konzolu.';

    public function handle()
    {
        if (!$this->option('force')) {
            $potvrda = $this->ask(
                "Ovo će obrisati SVE knjige, primerke, rezervacije i zaduženja. Ukucaj POTVRDI da nastaviš"
            );
            if ($potvrda !== 'POTVRDI') {
                $this->info('Operacija otkazana.');
                return;
            }
        }

        DB::transaction(function () {
            DB::table('notifikacije')->truncate();
            DB::table('zaduzivanja')->truncate();
            DB::table('rezervacije')->truncate();
            DB::table('knjiga_zanr')->truncate();
            DB::table('primerci_knjiga')->truncate();
            DB::table('knjige')->truncate();
        });

        $this->info('Sve knjige i povezani podaci su obrisani.');
        \App\Helpers\ActivityLogger::log('knjige_reset', 'Obrisane sve knjige i povezani podaci');
    }
}