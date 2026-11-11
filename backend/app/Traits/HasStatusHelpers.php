<?php

namespace App\Traits;

trait HasStatusHelpers
{
    /**
     * Čitljiv naziv statusa.
     * Zahteva da model ima $this->status koji je backed Enum sa label() metodom.
     */
    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    /**
     * CSS klasa za badge prikaz statusa.
     * Zahteva da model ima $this->status koji je backed Enum sa badgeClass() metodom.
     */
    public function getStatusBadgeClass(): string
    {
        return $this->status->badgeClass();
    }
}