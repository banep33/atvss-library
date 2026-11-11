<?php

namespace App\Mail;

use App\Enums\TipObavestenja;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mejl sa istom rečenicom koju član dobija i u aplikaciji.
 *
 * NAMERNO NOSI SAMO STRING I ENUM, ne Eloquent modele. Kada poruka ode u red
 * (QUEUE_CONNECTION=database), Laravel je serijalizuje i radnik je izvlači tek
 * kasnije; SerializesModels bi tada model tražio ponovo po id-u, a obaveštenje
 * do tada može da nestane — čitanje obaveštenja u aplikaciji ga BRIŠE
 * (NotifikacijaController::procitano). Ovako je poruka samodovoljna.
 *
 * ShouldQueue: uz podrazumevani QUEUE_CONNECTION=sync mejl odlazi odmah, u
 * istom zahtevu. Kada se pređe na `database` i pusti `php artisan queue:work`,
 * ista klasa počinje da radi u pozadini, bez ijedne izmene koda.
 */
class ObavestenjeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ime,
        public readonly TipObavestenja $tip,
        public readonly string $poruka,
    ) {}

    public function envelope(): Envelope
    {
        // Naslov mejla je ISTI tekst koji stoji i kao veliki naslov u telu
        // poruke — čovek koji otvori mejl iz spiska vidi ono što ga je i
        // navelo da klikne.
        return new Envelope(
            subject: $this->tip->naslov(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.obavestenje',
            // Ista poruka i kao čist tekst: klijent bira šta ume da prikaže,
            // a filteri neželjene pošte blaže ocenjuju poruku sa oba oblika.
            text: 'emails.obavestenje-tekst',
            with: [
                'naslov'           => $this->tip->naslov(),
                'boja'             => $this->tip->boja(),
                'nazivBiblioteke'  => config('obavestenja.naziv_biblioteke'),
                'adresaAplikacije' => rtrim((string) config('app.url'), '/'),
            ],
        );
    }
}
