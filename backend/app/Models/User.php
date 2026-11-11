<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\KorisnikUloga; // ← DODATO
use App\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles;

    protected $fillable = [
        'ime',
        'prezime',
        'email',
        'password',
        'role',
        'broj_clanske_karte',
        'telefon',
        'adresa',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'role' => KorisnikUloga::class, // ← DODATO
    ];

    

    // Relacije — nepromenjeno
    public function rezervacije()
    {
        return $this->hasMany(Rezervacija::class);
    }

    

    public function predlozi()
    {
        return $this->hasMany(PredlogKnjige::class);
    }

    public function zaduzivanja()
    {
        return $this->hasMany(Zaduzivanje::class, 'clan_id');
    }
}