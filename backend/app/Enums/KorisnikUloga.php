<?php

namespace App\Enums;

enum KorisnikUloga: string
{
    case Admin       = 'admin';
    case Bibliotekar = 'bibliotekar';
    case Clan        = 'clan';
}