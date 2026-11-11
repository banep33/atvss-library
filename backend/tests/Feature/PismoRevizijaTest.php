<?php

namespace Tests\Feature;

use App\Enums\PrimerakStatus;
use App\Enums\RezervacijaStatus;
use App\Enums\TipObavestenja;
use App\Models\Knjiga;
use App\Models\Notifikacija;
use App\Models\PrimerakKnjige;
use App\Models\Rezervacija;
use App\Models\User;
use App\Models\Zanr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Revizija preslovljavanja: prolazi kroz SVE stranice u ćirilici i traži
 * tekst koji je ostao u latinici (ili je pogrešno preslovljen).
 */
class PismoRevizijaTest extends TestCase
{
    use RefreshDatabase;

    /** Latinica koja SME da ostane: oznake, ekstenzije, broj kartice. */
    private const DOZVOLJENO = [
        'ISBN', 'ISSN', 'ISO', 'DOI', 'COBISS', 'N/A', 'PDF', 'CSV', 'HTML', 'CSS', 'URL',
    ];

    private function korisnik(string $role, ?string $karta = null): User
    {
        return User::create([
            'ime' => 'Petar', 'prezime' => 'Petrović',
            'email' => $role . '@example.com',
            'password' => Hash::make('tajna123'),
            'role' => $role, 'telefon' => '+381600000000', 'adresa' => 'Niš',
            'broj_clanske_karte' => $karta,
        ]);
    }

    private function pripremiPodatke(User $clan): array
    {
        $zanr = Zanr::create(['naziv' => 'Roman']);

        $knjiga = Knjiga::create([
            'in_broj' => '1', 'signatura' => '821.163', 'datum' => '2026-01-01',
            'autor' => 'Ivo Andrić', 'naziv_knjige' => 'Na Drini ćuprija',
            'mesto_izdavanja' => 'Beograd', 'godina_izdavanja' => 1945,
            'izdavac' => 'Prosveta', 'nacin_nabavke' => 'Kupovina',
            'isbn' => '978-86-07-01234-5', 'opis' => 'Roman o mostu.',
        ]);
        $knjiga->zanrovi()->attach($zanr->id);

        $primerak = PrimerakKnjige::create([
            'knjiga_id' => $knjiga->id, 'inventarski_broj' => '1',
            'signatura' => '821.163', 'status' => PrimerakStatus::Rezervisana->value,
        ]);

        $rezervacija = Rezervacija::create([
            'user_id' => $clan->id, 'knjiga_id' => $knjiga->id,
            'primerak_id' => $primerak->id,
            'status' => RezervacijaStatus::Odobrena,
            'datum_rezervacije' => now()->subDays(2),
            'datum_odobrenja' => now()->subDay(),
            'rok_preuzimanja' => now()->addDays(4),
        ]);

        Notifikacija::create([
            'user_id' => $clan->id, 'rezervacija_id' => $rezervacija->id,
            'tip' => TipObavestenja::RezervacijaOdobrena->value,
            'poruka' => 'Obaveštavamo vas da je rezervacija odobrena.',
            'procitano' => false,
        ]);

        return ['knjiga' => $knjiga, 'zanr' => $zanr];
    }

    /**
     * Vidljiv tekst stranice — bez markup-a, skripti, stilova i podstabala
     * koja se namerno ne preslovljavaju.
     */
    private function vidljivTekst(string $html): string
    {
        // Elementi čiji sadržaj po pravilima OSTAJE u latinici.
        foreach (['script', 'style', 'code', 'pre', 'noscript', 'textarea'] as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', ' ', $html) ?? $html;
        }

        // Podstabla sa data-no-tr (jednostavno: element i njegov sadržaj).
        $html = preg_replace('#<(\w+)[^>]*\sdata-no-tr\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<[^>]*\sdata-no-tr\b[^>]*>#is', ' ', $html) ?? $html;

        // Komentari, pa markup.
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $html = strip_tags($html);

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Reči u latinici koje su ostale nepreslovljene. */
    private function zaostalaLatinica(string $tekst): array
    {
        $nalazi = [];

        /* Klasa hvata i „@" i „:" da e-adresa i URL izađu kao JEDAN token —
           inače bi se „pera@example.com" raspalo na „pera" i „example.com",
           pa bi provera ispod izgubila jedini znak po kom ih prepoznaje. */
        // Vodeća tačka je deo tokena da bi „(.xlsx)" izašlo kao „.xlsx" —
        // bez nje ostaje golo „xlsx", koje više ne izgleda kao ekstenzija.
        preg_match_all('/\.?[A-Za-zČĆŽŠĐčćžšđ][A-Za-zČĆŽŠĐčćžšđ0-9._@:\/-]*/u', $tekst, $m);

        foreach ($m[0] as $rec) {
            // Naziv fajla se proverava PRE skidanja tačke: „.xlsx" bez nje
            // više ne izgleda kao ekstenzija.
            if (preg_match('/\.(xlsx|xls|csv|pdf|docx|doc|pptx|ppt|txt|rtf|zip|rar|json|xml|html|htm|md|png|jpg|jpeg|gif|webp|svg|ico)$/i', $rec)) {
                continue;
            }

            // E-adresa ili URL.
            if (str_contains($rec, '@') || preg_match('#https?://#i', $rec)) {
                continue;
            }

            $ocisceno = trim($rec, './-_:');

            if (mb_strlen($ocisceno) < 2) {
                continue;
            }

            // Dozvoljene oznake.
            if (in_array(mb_strtoupper($ocisceno), self::DOZVOLJENO, true)) {
                continue;
            }

            // Broj članske karte — po zahtevu ostaje u latinici.
            if (preg_match('/^CLAN-\d+$/i', $ocisceno)) {
                continue;
            }

            $nalazi[$ocisceno] = ($nalazi[$ocisceno] ?? 0) + 1;
        }

        return $nalazi;
    }

    /** @return array<string, array{status:int, latinica:array, clan_kartica:bool}> */
    private function pregledajStranice(): array
    {
        $clan         = $this->korisnik('clan', 'CLAN-001');
        $bibliotekar  = $this->korisnik('bibliotekar');
        $podaci       = $this->pripremiPodatke($clan);

        $stranice = [
            ['gost',        null,         '/'],
            ['gost',        null,         '/login'],
            ['gost',        null,         '/info'],
            ['gost',        null,         '/knjige/' . $podaci['knjiga']->id],
            ['clan',        $clan,        '/'],
            ['clan',        $clan,        '/profil'],
            ['clan',        $clan,        '/notifikacije/moja'],
            ['clan',        $clan,        '/zaduzene'],
            ['clan',        $clan,        '/knjige/' . $podaci['knjiga']->id],
            ['clan',        $clan,        '/admin/inventarna-knjiga'],
            ['bibliotekar', $bibliotekar, '/admin/rezervacije'],
            ['bibliotekar', $bibliotekar, '/admin/clanovi'],
            ['bibliotekar', $bibliotekar, '/admin/zanrovi'],
            ['bibliotekar', $bibliotekar, '/admin/zanrovi/' . $podaci['zanr']->id . '/knjige'],
            ['bibliotekar', $bibliotekar, '/admin/knjige/import'],
            ['bibliotekar', $bibliotekar, '/admin/inventarna-knjiga'],
            ['bibliotekar', $bibliotekar, '/profil'],
        ];

        $rezultat = [];

        foreach ($stranice as [$uloga, $korisnik, $url]) {
            /* Sesija se prazni pre svakog zahteva: AuthenticateSession pamti
               heš lozinke prijavljenog korisnika i odjavljuje ga čim se u
               istoj sesiji pojavi drugi — bez ovoga bi prvi zahtev posle
               promene uloge završio na prijavi (viđeno: /admin/rezervacije). */
            $this->flushSession();

            // withUnencryptedCookie, ne withCookie: u testovima Laravel
            // `withCookie` šalje vrednost kao šifrovanu, pa je EncryptCookies
            // odbaci i middleware vidi kao da kolačića nema.
            $zahtev = $this->withUnencryptedCookie('pismo', 'cyr');

            if ($korisnik) {
                $zahtev = $zahtev->actingAs($korisnik);
            }

            $odgovor = $zahtev->get($url);
            $html    = $odgovor->getContent() ?: '';
            $tekst   = $this->vidljivTekst($html);

            $rezultat["{$uloga} {$url}"] = [
                'status'       => $odgovor->status(),
                'latinica'     => $this->zaostalaLatinica($tekst),
                'clan_kartica' => str_contains($html, 'ЦЛАН-'),
            ];
        }

        return $rezultat;
    }

    #[Test]
    public function nijedna_stranica_ne_ostavlja_tekst_u_latinici(): void
    {
        $problemi = [];

        foreach ($this->pregledajStranice() as $stranica => $p) {
            $this->assertSame(200, $p['status'], "Stranica ne radi: {$stranica}");

            if ($p['latinica']) {
                $problemi[] = $stranica . ': ' . implode(', ', array_keys($p['latinica']));
            }
        }

        $this->assertSame([], $problemi, "Tekst je ostao u latinici:\n" . implode("\n", $problemi));
    }

    #[Test]
    public function broj_clanske_karte_ostaje_u_latinici(): void
    {
        /* Izričit zahtev: CLAN-001 je identifikator koji član pokazuje na
           pultu i kuca pri preuzimanju e-knjige. „ЦЛАН-001" se ne bi poklapao
           ni sa bazom ni sa samom karticom. */
        foreach ($this->pregledajStranice() as $stranica => $p) {
            $this->assertFalse(
                $p['clan_kartica'],
                "Broj članske karte je preslovljen u ćirilicu na: {$stranica}"
            );
        }
    }
}
