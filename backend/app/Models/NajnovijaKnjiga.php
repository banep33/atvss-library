<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NajnovijaKnjiga extends Model
{
    protected $table = 'najnovije_knjige';
    protected $fillable = ['knjiga_id'];

    public function knjiga()
    {
        return $this->belongsTo(Knjiga::class);
    }
}