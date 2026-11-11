<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uklanja popularnost_* kolone iz tabele knjige.
 *
 * Kolone je punila komanda `ai:rangiraj-popularnost` (Google Books), a čitao
 * ih je isključivo CatalogQueryService za rangiranje preporuka. Oba su
 * uklonjena prelaskom na jedinstveni RAG pipeline — kolone su mrtva šema.
 *
 * hasColumn guard: na svežoj bazi (gde migracija koja ih je dodavala više ne
 * postoji) migracija je no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('knjige', 'popularnost_score')) {
            return;
        }

        Schema::table('knjige', function (Blueprint $table) {
            $table->dropColumn(['popularnost_score', 'popularnost_izvor', 'popularnost_azurirano']);
        });
    }

    public function down(): void
    {
        Schema::table('knjige', function (Blueprint $table) {
            $table->double('popularnost_score')->nullable()->after('eknjiga_link');
            $table->string('popularnost_izvor')->nullable()->after('popularnost_score');
            $table->timestamp('popularnost_azurirano')->nullable()->after('popularnost_izvor');
        });
    }
};
