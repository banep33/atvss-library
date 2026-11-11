<?php

namespace App\Enums;

enum ArhivaTip: string
{
    case Zahtev   = 'Zahtev';
    case Odobrena = 'Odobrena';
    case Odbijena = 'Odbijena';
    case Zaduzena = 'Zaduzena';
    case Vracena  = 'Vracena';
}