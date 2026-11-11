<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'akcija',
        'opis',
        'ip_address',
        'user_agent'
    ];
}
