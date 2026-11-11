<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uklanja kolonu `pristupni_kod_klub` iz tabele korisnika.
 *
 * Klub čitalaca nema ni stranicu ni odeljak u aplikaciji, pa je kod bio
 * podatak koji se generisao i prikazivao bez ijedne namene. Kolona je
 * izbačena i iz create-migracije, zato `hasColumn` provera — na svežoj bazi
 * kolone nema, pa ova migracija tada nema šta da radi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'pristupni_kod_klub')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pristupni_kod_klub');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'pristupni_kod_klub')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('pristupni_kod_klub')->nullable();
            });
        }
    }
};
