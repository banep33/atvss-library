<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Arhiva extends Model
{
    protected $table = 'arhiva';

    protected $fillable = [
        'rezervacija_id',
        'user_id',
        'knjiga_id',
        'tip',
        'naziv_knjige',
        'autor',
        'ime_clana',
        'email_clana',
        'broj_clanske_karte',
        'datum_akcije',
        'rok_vracanja',
    ];

    protected $casts = [
        'datum_akcije' => 'datetime',
        'rok_vracanja' => 'datetime',
    ];

    public function rezervacija()
    {
        return $this->belongsTo(Rezervacija::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function knjiga()
    {
        return $this->belongsTo(Knjiga::class);
    }
}