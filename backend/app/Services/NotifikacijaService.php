<?php

namespace App\Services;

use App\Enums\TipObavestenja;
use App\Mail\ObavestenjeMail;
use App\Models\Notifikacija;
use App\Models\Rezervacija;
use App\Models\User;
use App\Models\Zaduzivanje;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Jedno mesto kroz koje prolazi SVAKO obaveštenje članu.
 *
 * Obaveštenje uvek ide na dva kanala odjednom:
 *   1. u aplikaciju — red u tabeli `notifikacije` (brojač u zaglavlju),
 *   2. na e-poštu — ista rečenica, na adresu iz `users.email`.
 *
 * Kanali su spojeni ovde, a ne kod pozivalaca: RezervacijaService i zakazane
 * komande zovu samo metode ispod i ne znaju da mejl uopšte postoji. To je i
 * jedini razlog zašto su dva kanala uvek usklađena — nema puta kojim
 * obaveštenje može da stigne u aplikaciju a da ne krene i na mejl.
 */
class NotifikacijaService
{
    // ═══════════════════════════════════════════════════════════════════
    // REZERVACIJE
    // ═══════════════════════════════════════════════════════════════════

    public function posalji(Rezervacija $rezervacija, TipObavestenja $tip, string $poruka): void
    {
        Notifikacija::create([
            'user_id'        => $rezervacija->user_id,
            'rezervacija_id' => $rezervacija->id,
            'tip'            => $tip->value,
            'poruka'         => $poruka,
            'procitano'      => false,
        ]);

        $this->posaljiMejl($rezervacija->user, $tip, $poruka);
    }

    /**
     * Isto kao posalji(), ali najviše jednom po paru (rezervacija, tip).
     *
     * Za događaje koji se mogu „otkriti" više puta — istek rezervacije
     * proverava i zakazana komanda i otvaranje admin stranice, a podsetnik bi
     * se ponovio pri svakom ručnom pokretanju komande.
     */
    public function posaljiJednom(Rezervacija $rezervacija, TipObavestenja $tip, string $poruka): void
    {
        $notifikacija = Notifikacija::firstOrCreate(
            [
                'rezervacija_id' => $rezervacija->id,
                'tip'            => $tip->value,
            ],
            [
                'user_id'   => $rezervacija->user_id,
                'poruka'    => $poruka,
                'procitano' => false,
            ]
        );

        // Pravilo „najviše jednom" mora da važi i za mejl — inače bi u
        // aplikaciji stajalo jedno obaveštenje, a u sandučetu pet istih.
        if ($notifikacija->wasRecentlyCreated) {
            $this->posaljiMejl($rezervacija->user, $tip, $poruka);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // POZAJMICE (ZADUŽENJA)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Obaveštenje o zaduženoj knjizi, najviše jednom po paru (zaduženje, tip).
     *
     * Zaduženje ne mora da ima rezervaciju iza sebe (bibliotekar sme da zaduži
     * knjigu direktno), pa se veže preko `zaduzivanje_id`. Bez sopstvenog
     * ključa bi se svi takvi redovi „slepili" na rezervacija_id = NULL i
     * `firstOrCreate` bi drugom članu vratio tuđe obaveštenje.
     */
    public function posaljiZaZaduzivanje(Zaduzivanje $zaduzivanje, TipObavestenja $tip, string $poruka): void
    {
        $notifikacija = Notifikacija::firstOrCreate(
            [
                'zaduzivanje_id' => $zaduzivanje->id,
                'tip'            => $tip->value,
            ],
            [
                'user_id'   => $zaduzivanje->user_id,
                'poruka'    => $poruka,
                'procitano' => false,
            ]
        );

        if ($notifikacija->wasRecentlyCreated) {
            $this->posaljiMejl($zaduzivanje->user, $tip, $poruka);
        }
    }

    // ═══════════════════════════════════════════════════════════════════

    /**
     * Ista rečenica na e-poštu člana.
     *
     * NIKAD NE BACA IZUZETAK. Poziva se iz transakcije (RezervacijaService
     * ::odobri i ::odbij rade u DB::transaction): da mejl pukne — pao server
     * za slanje, istekla lozinka, nema mreže — izuzetak bi srušio transakciju
     * i poništio odobravanje rezervacije. Rezervacija je posao biblioteke,
     * mejl je pogodnost; pogodnost ne sme da obori posao. Greška se zato samo
     * upisuje u dnevnik, isto kao kod RAG indeksa u Knjiga::booted().
     */
    private function posaljiMejl(?User $korisnik, TipObavestenja $tip, string $poruka): void
    {
        if (!config('obavestenja.mejl')) {
            return;
        }

        $adresa = $korisnik?->email;

        if (!$adresa) {
            return;
        }

        try {
            Mail::to($adresa)->send(new ObavestenjeMail(
                ime:    trim($korisnik->ime . ' ' . $korisnik->prezime),
                tip:    $tip,
                poruka: $poruka,
            ));
        } catch (\Throwable $e) {
            Log::warning('[Obaveštenja] Slanje mejla nije uspelo', [
                'tip'   => $tip->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
