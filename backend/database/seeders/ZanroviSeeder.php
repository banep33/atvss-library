<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Zanr;

class ZanroviSeeder extends Seeder
{
    public function run(): void
    {
        // Lista žanrova koje želimo u bazi
        $zanrovi = [
            'Roman',
            'Fantastika',
            'Krimi',
            'Drama',
            'Poetika',
            'Biografija',
            'Triler',
            'Naučna fantastika',
            'Psihologija',
            'Programiranje',
            'Baze podataka',
            'Humor',
            'Autobiografija',
        ];

        foreach ($zanrovi as $naziv) {
            // firstOrCreate sprečava ubacivanje duplikata
            Zanr::firstOrCreate([
                'naziv' => $naziv
            ]);
        }
    }
}