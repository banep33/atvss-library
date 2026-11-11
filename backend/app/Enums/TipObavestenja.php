<?php

namespace App\Enums;

/**
 * Šest obaveštenja koja član može da dobije.
 *
 * Vrednosti su iste one koje su i do sada stajale u `notifikacije.tip` kao
 * goli stringovi — prve tri su zatečene, pa stari redovi ostaju ispravni.
 *
 * Enum drži i naslov mejla i boju, zato što ta dva podatka moraju da budu
 * usklađena na tri mesta odjednom: u mejlu, u brojaču u zaglavlju i na
 * spisku obaveštenja. Dok su stajali prepisani po Blade-ovima, dodavanje
 * sedmog tipa značilo je tri izmene i dve prilike da se nešto zaboravi.
 */
enum TipObavestenja: string
{
    case RezervacijaOdobrena     = 'rezervacija_odobrena';
    case RezervacijaOdbijena     = 'rezervacija_odbijena';
    case RezervacijaIstekla      = 'rezervacija_istekla';
    case RezervacijaUskoroIstice = 'rezervacija_uskoro_istice';
    case PozajmicaUskoroIstice   = 'pozajmica_uskoro_istice';
    case PozajmicaIstekla        = 'pozajmica_istekla';

    /**
     * Naslov poruke. Stoji NA DVA MESTA ISTOVREMENO: kao naslov (subject)
     * mejla i kao veliki naslov u samom telu poruke — da čovek koji je otvorio
     * mejl iz spiska vidi isti tekst koji ga je i naveo da klikne.
     */
    public function naslov(): string
    {
        return match ($this) {
            self::RezervacijaOdobrena,
            self::RezervacijaOdbijena     => 'Obaveštenje o statusu vaše rezervacije',
            self::RezervacijaIstekla      => 'Obaveštenje o isteku rezervacije',
            self::RezervacijaUskoroIstice => 'Podsetnik: rok za preuzimanje knjige',
            self::PozajmicaUskoroIstice   => 'Podsetnik: rok za vraćanje knjige',
            self::PozajmicaIstekla        => 'Obaveštenje o isteku roka pozajmice',
        };
    }

    /**
     * Boja obaveštenja: 'uspeh' (zeleno), 'upozorenje' (narandžasto),
     * 'greska' (crveno). Koriste je brojač u zaglavlju, spisak obaveštenja i
     * traka u mejlu.
     *
     * Podela prati ono što član treba da uradi, a ne koliko je vest prijatna:
     *   - zeleno    → gotovo je, knjiga te čeka,
     *   - narandžasto → rok se bliži, imaš vremena da reaguješ,
     *   - crveno    → nema više šta da se čeka (odbijeno) ili je rok prošao.
     */
    public function boja(): string
    {
        return match ($this) {
            self::RezervacijaOdobrena     => 'uspeh',
            self::RezervacijaOdbijena     => 'greska',
            self::RezervacijaIstekla      => 'upozorenje',
            self::RezervacijaUskoroIstice => 'upozorenje',
            self::PozajmicaUskoroIstice   => 'upozorenje',
            self::PozajmicaIstekla        => 'greska',
        };
    }

    /**
     * Tip iz baze u enum, bez pucanja na nepoznatoj vrednosti.
     *
     * Model NAMERNO ne kastuje `tip` u ovaj enum: kastovanje bi na svakom
     * čitanju bacalo izuzetak ako u bazi zatekne vrednost koja ovde ne postoji
     * (ručno upisan red, tip uklonjen u nekoj kasnijoj verziji), i time oborilo
     * ceo spisak obaveštenja umesto jednog reda.
     */
    public static function iz(?string $tip): ?self
    {
        return $tip === null ? null : self::tryFrom($tip);
    }
}
