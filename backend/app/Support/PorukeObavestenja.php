<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Tekstovi svih šest obaveštenja, na jednom mestu.
 *
 * Ovo je REČENICA KOJA SE UPISUJE U BAZU (`notifikacije.poruka`) i koju član
 * vidi na spisku obaveštenja u aplikaciji. Mejl uzima isti taj tekst i samo ga
 * uokviri obraćanjem („Poštovani/a …") i pozdravom („Srdačan pozdrav,") — zato
 * ovde nema ni jednog ni drugog: u aplikaciji bi to bio suvišan uvod nad
 * porukom od dve rečenice.
 *
 * Do sada su rečenice stajale razbacane po servisu i komandama, sastavljane
 * spajanjem stringova na mestu upotrebe. Sa šest tipova to je značilo šest
 * mesta na kojima se traži zarez koji fali.
 */
final class PorukeObavestenja
{
    /** Datum u obliku uobičajenom za dopise: 05.09.2026. */
    private static function datum(?CarbonInterface $datum): string
    {
        return $datum?->format('d.m.Y.') ?? 'nepoznatog datuma';
    }

    /** Naslov knjige u srpskim navodnicima. */
    private static function knjiga(?string $naziv): string
    {
        return '„' . ($naziv ?? 'nepoznata knjiga') . '“';
    }

    public static function rezervacijaOdobrena(
        ?string $knjiga,
        ?CarbonInterface $datumZahteva,
        int $danaZaPreuzimanje,
    ): string {
        return 'Obaveštavamo vas da je rezervacija za knjigu ' . self::knjiga($knjiga)
            . ' koju ste poslali dana ' . self::datum($datumZahteva) . ' odobrena. '
            . 'Rok za preuzimanje knjige u biblioteci je ' . $danaZaPreuzimanje . ' dana. '
            . 'Ukoliko knjigu ne preuzmete u predviđenom roku, vaša rezervacija ističe.';
    }

    public static function rezervacijaOdbijena(?string $knjiga, ?CarbonInterface $datumZahteva): string
    {
        return 'Obaveštavamo vas da je rezervacija za knjigu ' . self::knjiga($knjiga)
            . ' koju ste poslali dana ' . self::datum($datumZahteva) . ' odbijena.';
    }

    public static function rezervacijaIstekla(?string $knjiga, ?CarbonInterface $datumOdobrenja): string
    {
        return 'Obaveštavamo vas da je rezervacija za knjigu ' . self::knjiga($knjiga)
            . ' koja je odobrena dana ' . self::datum($datumOdobrenja) . ' istekla.';
    }

    public static function rezervacijaUskoroIstice(
        ?string $knjiga,
        ?CarbonInterface $datumRezervacije,
        ?CarbonInterface $rokPreuzimanja,
    ): string {
        return 'Ovim putem vas obaveštavamo da rok za preuzimanje knjige ' . self::knjiga($knjiga)
            . ' koju ste rezervisali dana ' . self::datum($datumRezervacije)
            . ' ističe sutra, ' . self::datum($rokPreuzimanja);
    }

    public static function pozajmicaUskoroIstice(
        ?string $knjiga,
        ?CarbonInterface $datumIzdavanja,
        ?CarbonInterface $rokVracanja,
    ): string {
        return 'Ovim putem vas obaveštavamo da rok pozajmice knjige ' . self::knjiga($knjiga)
            . ' koju ste zadužili dana ' . self::datum($datumIzdavanja)
            . ' ističe sutra, ' . self::datum($rokVracanja);
    }

    /**
     * Rok pozajmice je prošao, a knjiga nije vraćena.
     *
     * „danas" se kaže samo kad je rok zaista istekao danas. Zakazana komanda
     * hvata i starije nevraćene knjige — ako server nije radio nekoliko dana —
     * pa bi tvrdo „danas" u tim porukama bilo netačno.
     */
    public static function pozajmicaIstekla(
        ?string $knjiga,
        ?CarbonInterface $datumIzdavanja,
        ?CarbonInterface $rokVracanja = null,
    ): string {
        $kada = ($rokVracanja !== null && $rokVracanja->isToday())
            ? 'je istekao danas'
            : 'je istekao dana ' . self::datum($rokVracanja);

        return 'Rok pozajmice knjige ' . self::knjiga($knjiga)
            . ' koju ste zadužili dana ' . self::datum($datumIzdavanja) . ' ' . $kada . '. '
            . 'Molimo vas da knjigu vratite u biblioteku u što kraćem periodu, hvala unapred!';
    }
}
