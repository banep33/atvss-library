<?php

namespace App\Helpers;

use App\Models\ActivityLog;

class ActivityLogger
{
    public static function log($akcija, $opis = null)
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'akcija' => $akcija,
            'opis' => $opis,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}