<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PredlogKnjige extends Model
{
    use HasFactory;

    protected $table = 'predlozi_knjiga';

    protected $fillable = [
        'user_id',
        'naziv_knjige',
        'autor',
        'razlog',
        'status',
        'odgovor_biblioteke',
    ];

    // Relacija
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}