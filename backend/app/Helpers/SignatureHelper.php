<?php

namespace App\Helpers;

class SignatureHelper
{
    /**
     * Normalize/canonicalize a signatura string.
     * - trim whitespace
     * - collapse multiple spaces
     * - replace non-breaking spaces
     * - remove surrounding punctuation noise
     */
    public static function normalize(?string $s): string
    {
        if ($s === null) return '';

        // Replace non-breaking spaces with normal spaces
        $s = str_replace("\xc2\xa0", ' ', $s);

        // Trim and collapse whitespace
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s);

        // Remove invisible control chars
        $s = preg_replace('/[\p{C}]+/u', '', $s);

        // Normalize common punctuation: remove surrounding quotes
        $s = trim($s, " \t\n\r\0\x0B\"'()[]{}");

        return $s;
    }
}
