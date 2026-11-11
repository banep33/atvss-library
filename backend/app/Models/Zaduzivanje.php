<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zaduzivanje extends Model
{
    protected $table = 'zaduzivanja';

    protected $fillable = [
        'user_id',
        'primerak_id',
        'datum_izdavanja',
        'rok_vracanja',
        'datum_vracanja',
        'napomena'
    ];

    protected $casts = [
        'datum_izdavanja' => 'datetime',
        'rok_vracanja'    => 'datetime',
        'datum_vracanja'  => 'datetime',
    ];

    // Jedna jasna relacija ka korisniku
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function primerak()
    {
        return $this->belongsTo(PrimerakKnjige::class, 'primerak_id');
    }
}