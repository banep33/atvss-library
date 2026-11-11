<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('najnovije_knjige', function (Blueprint $table) {
        $table->id();
        $table->foreignId('knjiga_id')->unique()->constrained('knjige')->onDelete('cascade');
        $table->timestamps();
    });
}

    public function down(): void
    {
    Schema::dropIfExists('najnovije_knjige');
    }
};
