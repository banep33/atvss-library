<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Veza obaveštenja sa zaduženjem.
 *
 * Tabela je do sada znala samo za rezervacije (`rezervacija_id`), jer su sva
 * tri zatečena obaveštenja bila o rezervaciji. Dva nova — „rok pozajmice
 * ističe sutra" i „rok pozajmice je istekao danas" — govore o ZADUŽENJU, koje
 * ne mora da ima rezervaciju iza sebe (bibliotekar sme da zaduži knjigu i
 * direktno, bez rezervacije).
 *
 * Kolona nije samo evidencija: po njoj se prepoznaje da je obaveštenje za to
 * zaduženje već poslato. Podsetnike šalje zakazana komanda, a ona sme da se
 * pokrene i dvaput u istom danu (ručno, posle pada servera) — bez ovog ključa
 * bi član svaki put dobio novi mejl o istoj pozajmici.
 *
 * Nullable je jer je stara obaveštenja nemaju, a `set null` prati ponašanje
 * koje `rezervacija_id` već ima: brisanje zaduženja ne sme da obriše
 * obaveštenje koje je član možda već pročitao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifikacije', function (Blueprint $table) {
            $table->foreignId('zaduzivanje_id')
                ->nullable()
                ->after('rezervacija_id')
                ->constrained('zaduzivanja')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('notifikacije', function (Blueprint $table) {
            $table->dropForeign(['zaduzivanje_id']);
            $table->dropColumn('zaduzivanje_id');
        });
    }
};
