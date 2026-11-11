<?php

namespace App\Traits;

use App\Enums\KorisnikUloga;

trait HasRoles
{
    public function isAdmin(): bool
    {
        return $this->role === KorisnikUloga::Admin;
    }

    public function isBibliotekar(): bool
    {
        return $this->role === KorisnikUloga::Bibliotekar;
    }

    public function isClan(): bool
    {
        return $this->role === KorisnikUloga::Clan;
    }

    public function isAdminOrBibliotekar(): bool
    {
        return in_array($this->role, [
            KorisnikUloga::Admin,
            KorisnikUloga::Bibliotekar,
        ]);
    }

    public function getRoleName(): string
    {
        return match($this->role) {
            KorisnikUloga::Admin       => 'Admin',
            KorisnikUloga::Bibliotekar => 'Bibliotekar',
            KorisnikUloga::Clan        => 'Član',
        };
    }
}