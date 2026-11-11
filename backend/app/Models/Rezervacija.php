<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\RezervacijaStatus; // ← DODATO
use App\Traits\HasStatusHelpers;

class Rezervacija extends Model
{
    use HasFactory, HasStatusHelpers;

    protected $table = 'rezervacije';

    protected $fillable = [
        'user_id',
        'knjiga_id',
        'primerak_id',
        'datum_rezervacije',
        'datum_odobrenja',
        'rok_preuzimanja',
        'datum_preuzimanja',
        'datum_vracanja',
        'odobrio_admin_id',
        'status',
        'vracena',
        'otkazana_vidjena',
    ];

    // Automatsko kastovanje kolona u odgovarajuće tipove:
    // datumski podaci u datetime, logička polja u boolean,
    // a status rezervacije u PHP enumeraciju RezervacijaStatus
    protected $casts = [
        'datum_rezervacije' => 'datetime',
        'datum_odobrenja'   => 'datetime',
        'rok_preuzimanja'   => 'datetime',
        'datum_preuzimanja' => 'datetime',
        'datum_vracanja'    => 'datetime',
        'vracena'           => 'boolean',
        'otkazana_vidjena'  => 'boolean',
        'status'            => RezervacijaStatus::class, 
    ];

    // Relacija: rezervacija pripada korisniku koji ju je napravio
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    // Relacija: rezervacija se odnosi na određenu knjigu
    public function knjiga()
    {
        return $this->belongsTo(Knjiga::class);
    }

    // Relacija: rezervacija je vezana za konkretan primerak knjige
    public function primerak()
    {
        return $this->belongsTo(PrimerakKnjige::class, 'primerak_id');
    }

    public function odobrio()
    {
        return $this->belongsTo(User::class, 'odobrio_admin_id');
    }

    public function notifikacije()
    {
        return $this->hasMany(Notifikacija::class);
    }

    // Postojeće helper metode — ažurirane da koriste Enum
    public function jeVracena(): bool
    {
        return $this->status === RezervacijaStatus::Vracena;
    }

    public function jePreuzeta(): bool
    {
        return $this->status === RezervacijaStatus::Preuzeta && !$this->vracena;
    }

    public function jeOdobrena(): bool
    {
        return $this->status === RezervacijaStatus::Odobrena;
    }

    public function jeNaCekanju(): bool
    {
        return $this->status === RezervacijaStatus::NaCekanju;
    }

    // Nove helper metode
    public function jeOdbijena(): bool
    {
        return $this->status === RezervacijaStatus::Odbijena;
    }

    public function jeIstekla(): bool
    {
        return $this->status === RezervacijaStatus::Istekla;
    }

    
}