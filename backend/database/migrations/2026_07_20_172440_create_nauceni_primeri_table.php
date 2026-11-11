<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Naučeni FAQ P/O parovi za SEMANTIČKO preuzimanje (Faza B): pitanje se
        // embeduje (nomic-embed-text, 768-dim, L2-normalizovan JSON), a u chatu
        // se najsličniji parovi ubacuju u system prompt. Dopuna leksičkog
        // FaqZnanje — hvata parafraze koje koren+fuzzy promaše.
        Schema::create('nauceni_primeri', function (Blueprint $table) {
            $table->id();
            $table->text('pitanje');
            $table->text('odgovor');
            // Normalizovan oblik pitanja (TextUtils::normalize) — dedup/supersede
            // (jedan par po pitanju) i leakage gard prema golden setu.
            $table->string('pitanje_norm')->unique();
            $table->json('embedding');                 // L2-normalizovan vektor
            $table->string('izvor')->default('few-shot'); // few-shot | eval
            $table->boolean('aktivan')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nauceni_primeri');
    }
};
