<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 0001_01_01_000002_create_core_tables.php
public function up(): void
{
    // Knjige
     if (!Schema::hasTable('knjige')) {
    Schema::create('knjige', function (Blueprint $table) {
        $table->id();
        $table->integer('in_broj')->nullable();
        $table->string('naziv_knjige');
        $table->string('signatura')->nullable();
        $table->string('autor')->nullable();
        $table->date('datum')->nullable();
        $table->string('mesto_izdavanja')->nullable();
        $table->unsignedSmallInteger('godina_izdavanja')->nullable();
        $table->string('izdavac')->nullable();
        $table->string('nacin_nabavke')->nullable();
        $table->string('isbn')->nullable();
        $table->text('opis')->nullable();
        $table->string('slika')->nullable();
        $table->boolean('je_eknjiga')->default(false);
        $table->string('eknjiga_link')->nullable();
        $table->timestamps();
    });
    }

    // Pivot tabela knjiga-zanr
    if (!Schema::hasTable('knjiga_zanr')) {
    Schema::create('knjiga_zanr', function (Blueprint $table) {
        $table->id();
        $table->foreignId('knjiga_id')->constrained('knjige')->onDelete('cascade');
        $table->foreignId('zanr_id')->constrained('zanrovi')->onDelete('cascade');
        $table->unique(['knjiga_id', 'zanr_id']);
    });
    }

    // Primerci knjiga
    if (!Schema::hasTable('primerci_knjiga')) {
    Schema::create('primerci_knjiga', function (Blueprint $table) {
        $table->id();
        $table->foreignId('knjiga_id')->constrained('knjige')->onDelete('cascade');
        $table->integer('inventarski_broj')->unique();
        $table->string('signatura')->nullable();
        $table->string('status')->default('slobodna'); // slobodna, zadužena, rezervisana, vracena
        $table->string('lokacija')->nullable();
        $table->string('nacin_nabavke')->nullable();
        $table->decimal('cena', 10, 2)->nullable();
        $table->date('datum_unosa')->nullable();
        $table->timestamps();
    });
    }

    // Rezervacije — finalna verzija (bez typo, sa primerak_id jednom)
    if (!Schema::hasTable('rezervacije')) {
    Schema::create('rezervacije', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
        $table->foreignId('knjiga_id')->constrained('knjige')->onDelete('cascade');
        $table->foreignId('primerak_id')->nullable()->constrained('primerci_knjiga')->onDelete('set null');
        $table->foreignId('odobrio_admin_id')->nullable()->constrained('users')->onDelete('set null');
        $table->string('status')->default('na_cekanju'); // na_cekanju, odobrena, odbijena, otkazana
        $table->timestamp('datum_rezervacije')->nullable();
        $table->timestamp('datum_isteka')->nullable();
        $table->timestamp('datum_odobrenja')->nullable();      
        $table->timestamp('rok_preuzimanja')->nullable();      
        $table->timestamp('datum_preuzimanja')->nullable();    
        $table->timestamp('datum_vracanja')->nullable();      
        $table->boolean('vracena')->default(false);
        $table->text('napomena')->nullable();
        $table->timestamps();
    });
    }

    // Zaduživanja — finalna verzija
    if (!Schema::hasTable('zaduzivanja')) {
    Schema::create('zaduzivanja', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
        $table->foreignId('clan_id')->nullable()->constrained('users')->onDelete('set null');
        $table->foreignId('primerak_id')->constrained('primerci_knjiga')->onDelete('cascade');
        $table->foreignId('rezervacija_id')->nullable()->constrained('rezervacije')->onDelete('set null');
        $table->date('datum_izdavanja');
        $table->date('rok_vracanja');
        $table->date('datum_vracanja')->nullable();
        $table->string('status')->default('aktivno'); // aktivno, vraćeno, kasni
        $table->timestamps();
    });
    }

    // Arhiva
    if (!Schema::hasTable('arhiva')) {
        Schema::create('arhiva', function (Blueprint $table) {
        $table->id();
        $table->foreignId('rezervacija_id')->nullable()->constrained('rezervacije')->onDelete('set null');
        $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
        $table->foreignId('knjiga_id')->nullable()->constrained('knjige')->onDelete('set null');
        $table->string('tip');
        $table->string('naziv_knjige')->nullable();
        $table->string('autor')->nullable();
        $table->string('ime_clana')->nullable();
        $table->string('email_clana')->nullable();
        $table->string('broj_clanske_karte')->nullable();
        $table->timestamp('datum_akcije')->nullable();
        $table->date('rok_vracanja')->nullable();
        $table->timestamps();
        
    });
    }

    // Notifikacije
    if (!Schema::hasTable('notifikacije')) {
        Schema::create('notifikacije', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('rezervacija_id')->nullable()->constrained('rezervacije')->onDelete('set null');
            $table->string('tip');
            $table->text('poruka');
            $table->boolean('procitano')->default(false);
            $table->timestamps();
        });
    }
    

    

    // Predlozi knjiga
    if (!Schema::hasTable('predlozi_knjiga')) {
    Schema::create('predlozi_knjiga', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
        $table->string('naziv');
        $table->string('autor')->nullable();
        $table->text('obrazlozenje')->nullable();
        $table->string('status')->default('na_cekanju');
        $table->timestamps();
    });
    }
    
}

public function down(): void
{
    Schema::dropIfExists('dogadjaji');
    Schema::dropIfExists('predlozi_knjiga');
    Schema::dropIfExists('ocene');
    Schema::dropIfExists('notifikacije');
    Schema::dropIfExists('arhiva');
    Schema::dropIfExists('zaduzivanja');
    Schema::dropIfExists('rezervacije');
    Schema::dropIfExists('primerci_knjiga');
    Schema::dropIfExists('knjiga_zanr');
    Schema::dropIfExists('knjige');
}
};