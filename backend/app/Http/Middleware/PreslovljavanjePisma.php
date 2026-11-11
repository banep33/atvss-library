<?php

namespace App\Http\Middleware;

use App\Support\Pismo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Preslovljava HTML odgovor u ćirilicu kada korisnik ima izabrano to pismo.
 *
 * Ranije je ceo posao radio pismo.js na `DOMContentLoaded`: stranica se
 * iscrta u latinici, pa se prepiše u ćirilicu — vidljiv prelom pri svakom
 * učitavanju. Sada je izbor u kolačiću (dostupan serveru), pa prvo
 * iscrtavanje već ima traženo pismo. JS ostaje za prebacivanje bez
 * osvežavanja i za sadržaj koji stiže iz API-ja.
 *
 * Ne dira: JSON i sve što nije text/html, preuzimanja fajlova, redirect-e,
 * stream-ove (SSE odgovori asistenta), odgovore servera (5xx) i tuđe
 * stranice grešaka (Laravel-ove podrazumevane, na engleskom).
 */
class PreslovljavanjePisma
{
    /** Naziv kolačića — isti ključ koristi i public/js/pismo.js. */
    public const KOLACIC = 'pismo';

    public function __construct(private Pismo $pismo)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $odgovor = $next($request);

        if ($request->cookie(self::KOLACIC) !== 'cyr') {
            return $odgovor;
        }

        if (! $this->jeHtmlStranica($odgovor)) {
            return $odgovor;
        }

        $sadrzaj = $odgovor->getContent();

        if ($sadrzaj === false || $sadrzaj === '') {
            return $odgovor;
        }

        if (! $this->smeDaSePreslovi($odgovor, $sadrzaj)) {
            return $odgovor;
        }

        $odgovor->setContent($this->pismo->preslovljajHtml($sadrzaj));

        return $odgovor;
    }

    private function jeHtmlStranica(Response $odgovor): bool
    {
        if ($odgovor instanceof StreamedResponse || $odgovor->isRedirection()) {
            return false;
        }

        $tip = (string) $odgovor->headers->get('Content-Type', '');

        return str_contains($tip, 'text/html');
    }

    /**
     * Uspešan odgovor (2xx) je uvek naša stranica. Stranice grešaka (4xx)
     * korisnik takođe čita, pa i one treba da budu u izabranom pismu — ali
     * SAMO ako su naše: Laravel-ova podrazumevana „419 | Page Expired" i
     * sličan engleski tekst preslovljen u ćirilicu dao bi „Пагe Еxпиред".
     * Naše stranice se prepoznaju po `data-pismo` na <html>, koji postavljaju
     * layout-i i samostalne strane (prijava, greške).
     *
     * 5xx se ne dira: tamo ume da stigne i Laravel-ov ispis izuzetka.
     */
    private function smeDaSePreslovi(Response $odgovor, string $sadrzaj): bool
    {
        if ($odgovor->isSuccessful()) {
            return true;
        }

        return $odgovor->isClientError() && str_contains($sadrzaj, 'data-pismo=');
    }
}
