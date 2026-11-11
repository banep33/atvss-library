<?php

namespace App\Support;

/**
 * Jedinstvena politika lozinki za celu aplikaciju (registracija člana,
 * izmena člana, promena sopstvene lozinke). Drži pravila i srpske poruke
 * na jednom mestu da se ne bi razišla po kontrolerima.
 *
 * Minimum: 8 karaktera, bar jedno slovo i bar jedna cifra.
 */
class PasswordPolicy
{
    /** Regex: bar jedno slovo i bar jedna cifra, bilo kojim redom. */
    private const SLOVO_I_CIFRA = '/^(?=.*[A-Za-z])(?=.*\d).+$/';

    /**
     * Laravel validaciona pravila.
     *
     * @param bool $required  false = polje sme da bude prazno (npr. izmena
     *                        člana bez promene lozinke)
     */
    public static function rules(bool $required = true): array
    {
        $pravila = ['string', 'min:8', 'max:255', 'regex:' . self::SLOVO_I_CIFRA];
        array_unshift($pravila, $required ? 'required' : 'nullable');

        return $pravila;
    }

    /**
     * Srpske poruke o grešci za dato ime polja.
     */
    public static function messages(string $field): array
    {
        return [
            "{$field}.min"    => 'Lozinka mora imati najmanje 8 karaktera.',
            "{$field}.max"    => 'Lozinka je predugačka.',
            "{$field}.regex"  => 'Lozinka mora sadržati bar jedno slovo i bar jednu cifru.',
        ];
    }
}
