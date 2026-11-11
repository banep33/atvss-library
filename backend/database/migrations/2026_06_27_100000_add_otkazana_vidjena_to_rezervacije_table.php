<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * otkazana_vidjena — da li je bibliotekar/admin „pročitao" obaveštenje da je
 * korisnik otkazao rezervaciju. Otkazana rezervacija (status Otkazana) sa
 * otkazana_vidjena=false prikazuje se kao obaveštenje u admin tabu (Na čekanju
 * ili Odobrene, zaključeno iz datum_odobrenja); klikom na „Označi kao pročitano"
 * postaje true i obaveštenje se sklanja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rezervacije', function (Blueprint $table) {
            $table->boolean('otkazana_vidjena')->default(false)->after('vracena');
        });
    }

    public function down(): void
    {
        Schema::table('rezervacije', function (Blueprint $table) {
            $table->dropColumn('otkazana_vidjena');
        });
    }
};
