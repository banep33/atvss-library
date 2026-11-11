<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admini
        User::updateOrCreate(['email' => 'bane.pavlovic@akademijanis.edu.rs'], [
            'ime'      => 'Bane',
            'prezime'  => 'Pavlović',
            'password' => Hash::make('tajna123'),
            'role'     => 'admin',
            'telefon'  => '+381601234567',
            'adresa'   => 'Bulevar Nemanjića 10, Niš',
        ]);

        User::updateOrCreate(['email' => 'milan.ristic@akademijanis.edu.rs'], [
            'ime'      => 'Milan',
            'prezime'  => 'Ristić',
            'password' => Hash::make('tajna123'),
            'role'     => 'admin',
            'telefon'  => '+381601234567',
            'adresa'   => 'Bulevar Nemanjića 10, Niš',
        ]);

        // Bibliotekar 
        User::updateOrCreate(['email' => 'silvana.bogicevic@akademijanis.edu.rs'], [
            'ime'      => 'Silvana',
            'prezime'  => 'Bogićević',
            'password' => Hash::make('silvana123'),
            'role'     => 'bibliotekar',
            'telefon'  => '+381601234567',
            'adresa'   => 'Bulevar Nemanjića 10, Niš',
        ]);

        // Članovi
        User::firstOrCreate(['broj_clanske_karte' => 'CLAN-001'], [
            'ime'               => 'Jovana',
            'prezime'           => 'Jovanović',
            'email'             => 'jovana@example.com',
            'password'          => Hash::make('clan123'),
            'role'              => 'clan',
            'telefon'           => '+381611234567',
            'adresa'            => 'Vojvode Tankosića 5, Niš',
        ]);

        User::firstOrCreate(['broj_clanske_karte' => 'CLAN-002'], [
            'ime'               => 'Stefan',
            'prezime'           => 'Stefanović',
            'email'             => 'stefan@example.com',
            'password'          => Hash::make('clan123'),
            'role'              => 'clan',
            'telefon'           => '+381621234567',
            'adresa'            => 'Obrenovićeva 20, Niš',
        ]);
    }
}