<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        if (Schema::hasTable('zanrovi')) return;

        Schema::create('zanrovi', function (Blueprint $table) {
            $table->id();                      //primarni ključ (auto-increment)
            $table->string('naziv')->unique(); // naziv žanra, jedinstven
            $table->timestamps();              // created_at i updated_at
        });                        
    }

    public function down(): void
    {
        Schema::dropIfExists('zanrovi');
    }
};