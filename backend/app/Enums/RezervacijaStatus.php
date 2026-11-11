<?php

namespace App\Enums;

enum RezervacijaStatus: string
{
    case NaCekanju = 'na_cekanju';
    case Odobrena  = 'odobrena';
    case Odbijena  = 'odbijena';
    case Preuzeta  = 'preuzeta';
    case Otkazana  = 'otkazana';
    case Istekla   = 'istekla';
    case Vracena   = 'vracena';

    public function label(): string
    {
        return match($this) {
            self::NaCekanju => 'Na čekanju',
            self::Odobrena  => 'Odobrena',
            self::Odbijena  => 'Odbijena',
            self::Preuzeta  => 'Preuzeta',
            self::Otkazana  => 'Otkazana',
            self::Istekla   => 'Istekla',
            self::Vracena   => 'Vraćena',
        };
    }

    public function badgeClass(): string
    {
        return match($this) {
            self::NaCekanju => 'badge-warning',
            self::Odobrena  => 'badge-success',
            self::Odbijena  => 'badge-danger',
            self::Preuzeta  => 'badge-info',
            self::Otkazana  => 'badge-secondary',
            self::Istekla   => 'badge-dark',
            self::Vracena   => 'badge-primary',
        };
    }
}