<?php

namespace App\Console\Commands;

use App\Enums\TipObavestenja;
use App\Mail\ObavestenjeMail;
use App\Support\PorukeObavestenja;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Provera podešavanja e-pošte, bez diranja rezervacija.
 *
 * Postoji zato što se greške u SMTP podešavanju inače vide tek kada neka
 * prava rezervacija bude odobrena — a tamo se, po projektu, izuzetak guta i
 * samo upisuje u dnevnik (NotifikacijaService::posaljiMejl). Ova komanda radi
 * suprotno: pušta grešku do ekrana, sa punom porukom servera.
 *
 *   php artisan obavestenja:proba pera@example.com
 *   php artisan obavestenja:proba pera@example.com --nalog=outlook
 */
class ProbaObavestenja extends Command
{
    protected $signature = 'obavestenja:proba
                            {email : Adresa na koju se šalje probna poruka}
                            {--nalog= : Nalog iz config/mail.php (gmail, outlook, smtp, log...); podrazumevano MAIL_MAILER}
                            {--tip=rezervacija_odobrena : Koje obaveštenje da prikaže (vidi App\Enums\TipObavestenja)}
                            {--sve : Pošalji svih šest obaveštenja, jedno za drugim}';

    protected $description = 'Šalje probno obaveštenje e-poštom i prijavljuje tačan razlog ako ne prođe';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $nalog = $this->option('nalog') ?: config('mail.default');

        // Sva šest obaveštenja izgledaju različito (naslov, boja trake), pa se
        // svako može pogledati bez čekanja da se u aplikaciji desi baš taj događaj.
        if ($this->option('sve')) {
            $tipovi = TipObavestenja::cases();
        } else {
            $tip = TipObavestenja::iz((string) $this->option('tip'));

            if (!$tip) {
                $this->error('Nepoznat tip obaveštenja: ' . $this->option('tip'));
                $this->line('Dostupni: ' . implode(', ', array_column(TipObavestenja::cases(), 'value')));
                return self::FAILURE;
            }

            $tipovi = [$tip];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Adresa „{$email}“ nije ispravna.");
            return self::FAILURE;
        }

        if (!array_key_exists($nalog, (array) config('mail.mailers'))) {
            $this->error("Nalog „{$nalog}“ ne postoji u config/mail.php.");
            $this->line('Dostupni: ' . implode(', ', array_keys((array) config('mail.mailers'))));
            return self::FAILURE;
        }

        $this->line("Nalog:      <options=bold>{$nalog}</>");
        $this->line('Pošiljalac: ' . config('mail.from.address') . ' (' . config('mail.from.name') . ')');
        $this->line("Primalac:   {$email}");
        $this->line('Poruka(e):  ' . count($tipovi));
        $this->newLine();

        // Prekidač iz config/obavestenja.php se ovde NE gleda: komanda služi
        // upravo tome da se slanje proveri PRE nego što se prekidač uključi.
        if (!config('obavestenja.mejl')) {
            $this->warn('Napomena: OBAVESTENJA_MEJL je isključen, pa prave rezervacije još ne šalju mejl.');
        }

        try {
            foreach ($tipovi as $tip) {
                // sendNow(), a ne send(): ObavestenjeMail je ShouldQueue, pa bi
                // ga send() samo stavio u red i komanda bi javila uspeh ne
                // dodirnuvši SMTP. Ovde nas zanima baš da li server prihvata
                // poruku, sada.
                Mail::mailer($nalog)->to($email)->sendNow(new ObavestenjeMail(
                    ime:    'Probni Korisnik',
                    tip:    $tip,
                    poruka: $this->primerPoruke($tip),
                ));

                $this->line("  <fg=green>✓</> {$tip->value} — „{$tip->naslov()}“");
            }
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Slanje nije uspelo: ' . $e->getMessage());
            $this->newLine();
            $this->line('Najčešći uzroci:');
            $this->line('  • Gmail: lozinka mora biti „App password", a ne lozinka naloga.');
            $this->line('  • Outlook/Microsoft 365: SMTP AUTH mora biti dozvoljen u zakupu.');
            $this->line('  • Pogrešan port (587 za STARTTLS, 465 za TLS) ili blokiran izlaz na mreži.');
            $this->line('  • Zastareo keš podešavanja — pokreni `php artisan config:clear`.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(count($tipovi) === 1
            ? 'Poruka je predata serveru za slanje.'
            : count($tipovi) . ' poruka je predato serveru za slanje.');

        if ($nalog === 'log') {
            $this->line('Nalog „log" ne šalje ništa napolje — poruka je u storage/logs/laravel.log.');
        }

        return self::SUCCESS;
    }

    /**
     * Tekst kakav član stvarno dobija, sa izmišljenim ali smislenim podacima.
     *
     * Namerno ne šalje uopšteno „ovo je probna poruka": provera treba da
     * pokaže i kako rečenica izgleda kad se sastavi — gde padaju navodnici,
     * datumi i rok — a ne samo da li SMTP prolazi.
     */
    private function primerPoruke(TipObavestenja $tip): string
    {
        $knjiga    = 'Na Drini ćuprija';
        $poslato   = now()->subDays(4);
        $odobreno  = now()->subDays(4);
        $zaduzeno  = now()->subDays(13);
        $sutra     = now()->addDay();

        return match ($tip) {
            TipObavestenja::RezervacijaOdobrena => PorukeObavestenja::rezervacijaOdobrena(
                $knjiga, $poslato, \App\Services\RezervacijaService::DANA_ZA_PREUZIMANJE,
            ),
            TipObavestenja::RezervacijaOdbijena     => PorukeObavestenja::rezervacijaOdbijena($knjiga, $poslato),
            TipObavestenja::RezervacijaIstekla      => PorukeObavestenja::rezervacijaIstekla($knjiga, $odobreno),
            TipObavestenja::RezervacijaUskoroIstice => PorukeObavestenja::rezervacijaUskoroIstice($knjiga, $poslato, $sutra),
            TipObavestenja::PozajmicaUskoroIstice   => PorukeObavestenja::pozajmicaUskoroIstice($knjiga, $zaduzeno, $sutra),
            TipObavestenja::PozajmicaIstekla        => PorukeObavestenja::pozajmicaIstekla($knjiga, $zaduzeno, now()),
        };
    }
}
