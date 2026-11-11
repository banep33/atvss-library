<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class OsveziNajcitanije extends Command
{
    protected $signature   = 'najcitanije:osvezi';
    protected $description = 'Nedeljno osvežavanje keša najčitanijih knjiga';

    public function handle(): void
    {
        Cache::forget('najcitanije_knjige');
        Cache::forget('featured_ids');
        $this->info('Keš najčitanijih knjiga osvežen.');
    }
}