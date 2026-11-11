<?php

namespace App\Enums;

enum PrimerakStatus: string
{
    case Slobodna    = 'slobodna';
    case Rezervisana = 'rezervisana';
    case Zaduzena    = 'zadužena';
    case Rashodovana = 'rashodovana';
    case Citaonica   = 'čitaonica';

    public function label(): string
    {
        return match($this) {
            self::Slobodna    => 'Slobodna',
            self::Rezervisana => 'Rezervisana',
            self::Zaduzena    => 'Zadužena',
            self::Rashodovana => 'Rashodovana',
            self::Citaonica   => 'Čitaonica',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::Slobodna    => 'badge-success',
            self::Rezervisana => 'badge-warning',
            self::Zaduzena    => 'badge-danger',
            self::Rashodovana => 'badge-dark',
            self::Citaonica   => 'badge-info',
        };
    }
}