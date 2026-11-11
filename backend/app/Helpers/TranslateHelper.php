<?php

use Turanjanin\SerbianTransliterator\Transliterator;

if (!function_exists('cirilica')) {
    function cirilica(string $text): string {
        return Transliterator::toCyrillic($text);
    }
}

if (!function_exists('latinica')) {
    function latinica(string $text): string {
        return Transliterator::toLatin($text);
    }
}