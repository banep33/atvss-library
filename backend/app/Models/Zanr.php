<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Zanr extends Model
{
    use HasFactory;

    protected $table = 'zanrovi';

    protected $fillable = ['naziv'];

    // Relacija sa knjigama (many-to-many)
    public function knjige()
    {
        return $this->belongsToMany(Knjiga::class, 'knjiga_zanr', 'zanr_id', 'knjiga_id');
    }
}