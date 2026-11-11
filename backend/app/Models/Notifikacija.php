<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Notifikacija extends Model
{

   
    
    protected $table = 'notifikacije';
    
    protected $fillable = [
        'user_id',
        'rezervacija_id',
        'zaduzivanje_id',
        'tip',
        'poruka',
        'procitano'
    ];

    /* `tip` NAMERNO nije kastovan u App\Enums\TipObavestenja: kastovanje bi na
       nepoznatoj vrednosti u bazi bacalo izuzetak i oborilo ceo spisak
       obaveštenja umesto jednog reda. Gde treba enum, koristi se
       TipObavestenja::iz($n->tip), koje na nepoznat tip vrati null. */
    protected $casts = [
        'procitano' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rezervacija()
    {
        return $this->belongsTo(Rezervacija::class);
    }

    // Popunjeno samo kod obaveštenja o pozajmici; kod rezervacija je null.
    public function zaduzivanje()
    {
        return $this->belongsTo(Zaduzivanje::class);
    }
}