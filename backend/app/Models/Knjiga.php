<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Enums\PrimerakStatus;
use Illuminate\Support\Facades\Log;

class Knjiga extends Model
{
    protected $table = 'knjige';
    protected $fillable = [
        'in_broj', 'signatura', 'datum', 'autor', 'naziv_knjige',
        'mesto_izdavanja', 'godina_izdavanja', 'izdavac', 'nacin_nabavke', 'isbn',
        'opis', 'slika', 'je_eknjiga', 'eknjiga_link',
    ];

    protected $casts = [
        'godina_izdavanja' => 'integer',
    ];

    /**
     * Prikazni oblik „Mesto, godina" — mesto i godina su ZASEBNE kolone
     * (mesto_izdavanja, godina_izdavanja), ovo ih spaja samo za ispis.
     */
    public function getIzdanjePrikazAttribute(): string
    {
        return trim(implode(', ', array_filter([
            $this->mesto_izdavanja,
            $this->godina_izdavanja,
        ])));
    }

    /**
     * Pauza automatske RAG sinhronizacije — koristi je Excel uvoz
     * (KnjigaImportService) da izbegne embed poziv po svakoj knjizi i
     * umesto toga uradi jedan bulk reindex na kraju.
     */
    public static bool $ragSyncPaused = false;

    /**
     * Automatsko ažuriranje vektorskog indeksa (RAG): kada knjiga nastane ili
     * se izmeni → reindex; kada se obriše → uklanjanje iz indeksa. Greška
     * (npr. embed servis nije dignut) se samo loguje — CRUD nad knjigom ne
     * sme da padne zbog AI sloja.
     *
     * NAPOMENA: sync žanrova (pivot) ne okida model evente — pozivaoci koji
     * menjaju SAMO žanrove treba da pozovu $knjiga->touch() (okida saved).
     */
    protected static function booted(): void
    {
        static::saved(function (Knjiga $knjiga) {
            if (self::$ragSyncPaused) {
                return;
            }
            try {
                app(\App\Services\Ai\RagService::class)->reindex($knjiga);
            } catch (\Throwable $e) {
                Log::warning('[Knjiga] RAG reindeks nije uspeo', [
                    'knjiga_id' => $knjiga->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        });

        static::deleted(function (Knjiga $knjiga) {
            if (self::$ragSyncPaused) {
                return;
            }
            try {
                app(\App\Services\Ai\RagService::class)->removeFromIndex($knjiga->id);
            } catch (\Throwable $e) {
                Log::warning('[Knjiga] RAG uklanjanje iz indeksa nije uspelo', [
                    'knjiga_id' => $knjiga->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        });
    }

    // Relacija jedan-prema-više: knjiga ima više primeraka
    public function primerci()
    {
        return $this->hasMany(PrimerakKnjige::class, 'knjiga_id');
    }

     // Relacija više-prema-više: knjiga pripada većem broju žanrova
    public function zanrovi()
    {
        return $this->belongsToMany(Zanr::class, 'knjiga_zanr', 'knjiga_id', 'zanr_id');
    }

    // Istorijska zaduženja knjige iz arhive (za „koliko puta je zaduživana"
    // i „najčitanije" — AI chat čita svež COUNT preko withCount)
    public function arhivaZaduzenja()
    {
        return $this->hasMany(Arhiva::class, 'knjiga_id')
            ->where('tip', \App\Enums\ArhivaTip::Zaduzena->value);
    }

    // Sve rezervacije knjige (AI chat: „koja se najviše rezerviše/traži")
    public function rezervacije()
    {
        return $this->hasMany(Rezervacija::class, 'knjiga_id');
    }


    // Akcesor: broj slobodnih primeraka (uz izbegavanje N+1 upita)
    public function getTotalPrimerciAttribute()
    {
        return $this->primerci()->count();
    }

    // ISPRAVLJENO — uklonjen N+1, dodat Enum, ispravljen typo 'zauzeta' → 'zadužena'
    public function getSlobodniPrimerciAttribute()
    {
        // Ako je withCount već učitao vrednost, koristi je — nema dodatnog upita
        if (array_key_exists('slobodni_primerci_count', $this->attributes)) {
            return $this->attributes['slobodni_primerci_count'];
        }
        return $this->primerci()->where('status', PrimerakStatus::Slobodna->value)->count();
    }

    public function getZaduzeniPrimerciAttribute()
    {
        // ISPRAVLJENO — 'zauzeta' → PrimerakStatus::Zaduzena ('zadužena')
        if (array_key_exists('zaduzeni_primerci_count', $this->attributes)) {
            return $this->attributes['zaduzeni_primerci_count'];
        }
        return $this->primerci()->where('status', PrimerakStatus::Zaduzena->value)->count();
    }

    // ISPRAVLJENO — Enum umesto magic string
    public function imaSlobodnePrimerke(): bool
    {
        return $this->primerci()->where('status', PrimerakStatus::Slobodna->value)->exists();
    }
}
