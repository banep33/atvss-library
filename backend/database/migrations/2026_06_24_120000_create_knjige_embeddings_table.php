<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vektorska tabela `knjige_embeddings` — po jedan embedding vektor po knjizi
 * (KANONSKO skladište za RAG pretragu kataloga).
 *
 * Obična SQLite tabela (embedding = JSON niz floatova, L2-normalizovan pri
 * upisu); upisuje je isključivo Laravel (App\Services\Ai\VectorStore), a KNN
 * pretragu radi Python mikroservis (rag_service, sqlite-vec) koji tabelu
 * čita read-only i gradi svoj indeks. vec0 nije u PHP-u jer se na ZTS PHP
 * build-ovima ne učitava van CLI-ja ("Not supported in multithreaded Web
 * servers") — u Pythonu to ograničenje ne postoji.
 *
 * Posle prvog pokretanja migracije indeks se puni komandom:
 *   php artisan ai:reindex
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knjige_embeddings', function (Blueprint $table) {
            $table->unsignedBigInteger('knjiga_id')->primary();
            $table->text('embedding'); // JSON niz floatova (nomic-embed-text, dim 768)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knjige_embeddings');
    }
};
