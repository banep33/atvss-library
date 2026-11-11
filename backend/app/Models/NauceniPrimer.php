<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NauceniPrimer — jedan naučeni FAQ P/O par sa embedingom pitanja (Faza B).
 *
 * Puni ga `php artisan bot:nauci` iz kuriranih few-shot parova; u chatu ga
 * semantički preuzima {@see \App\Services\Ai\NauceniPrimeriService} i ubacuje u
 * system prompt. Embedding je L2-normalizovan (cosine = skalarni proizvod).
 */
class NauceniPrimer extends Model
{
    protected $table = 'nauceni_primeri';

    protected $fillable = [
        'pitanje', 'odgovor', 'pitanje_norm', 'embedding', 'izvor', 'aktivan',
    ];

    protected $casts = [
        'embedding' => 'array',
        'aktivan'   => 'boolean',
    ];
}
