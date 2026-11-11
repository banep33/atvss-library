<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Enums\PrimerakStatus; // ← DODATO
use App\Traits\HasStatusHelpers;


class PrimerakKnjige extends Model
{

    use HasStatusHelpers;
    
    protected $table = 'primerci_knjiga';

    protected $fillable = [
        'knjiga_id', 'inventarski_broj', 'signatura', 'status',
        'lokacija', 'nacin_nabavke', 'cena', 'datum_unosa'
    ];

    protected $casts = [
        'status' => PrimerakStatus::class, // ← DODATO
    ];

    // Relacije — nepromenjeno
    public function knjiga()
    {
        return $this->belongsTo(Knjiga::class, 'knjiga_id');
    }

    public function aktivnoZaduzivanje()
    {
        return $this->hasOne(Zaduzivanje::class, 'primerak_id')
                    ->whereNull('datum_vracanja');
    }

    // Nove helper metode
    public function isSlobodan(): bool
    {
        return $this->status === PrimerakStatus::Slobodna;
    }

    public function isRezervisan(): bool
    {
        return $this->status === PrimerakStatus::Rezervisana;
    }

    public function isZaduzen(): bool
    {
        return $this->status === PrimerakStatus::Zaduzena;
    }

    

    // Dodaj na kraj modela, pre zatvorene vitičaste zagrade
public function toArray()
{
    $array = parent::toArray();
    if ($this->status instanceof PrimerakStatus) {
        $array['status'] = $this->status->value; // API vraća string, ne Enum objekat
    }
    return $array;
}
}