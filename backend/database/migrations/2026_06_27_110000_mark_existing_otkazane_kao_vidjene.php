<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jednokratno: sve POSTOJEĆE otkazane rezervacije označi kao „viđene" da se ne
 * pojavljuju kao nova obaveštenja bibliotekaru. Nova otkazivanja (status se menja
 * na 'otkazana' na redu koji je imao otkazana_vidjena=false) i dalje će se prikazati.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rezervacije')
            ->where('status', 'otkazana')
            ->update(['otkazana_vidjena' => true]);
    }

    public function down(): void
    {
        // Namerno bez vraćanja na false — ne želimo da resurfejsujemo stara obaveštenja.
    }
};
