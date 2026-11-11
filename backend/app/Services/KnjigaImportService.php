<?php

namespace App\Services;

use App\Models\Knjiga;
use App\Models\PrimerakKnjige;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\Zanr;
use App\Services\Ai\RagService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Turanjanin\SerbianTransliterator\Transliterator;


class KnjigaImportService
{
    public function __construct(
        private RagService $rag,
    ) {}

    public function uvezi(string $putanja): array
    {
        // Automatski reindeks po knjizi se pauzira: uvoz radi JEDAN grupni
        // reindeks na kraju, kada knjige vec imaju sve podatke
        Knjiga::$ragSyncPaused = true;

        try {
            return $this->uveziRedove($putanja);
        } finally {
            Knjiga::$ragSyncPaused = false; // vraca se i kada uvoz padne
        }
    }

    private function uveziRedove(string $putanja): array
    {
        $spreadsheet = IOFactory::load($putanja);
        $rows = $spreadsheet->getActiveSheet()->toArray();

        $rezultat = ['knjige' => 0, 'primerci' => 0, 'greske' => []];
        $uveženeKnjige = [];

        foreach (array_slice($rows, 1) as $index => $row) {
            $redBroj = $index + 2;

            if ($this->redJePrazan($row)) continue;

            // Čitaj direktno iz fajla
            $invBrojIzFajla = Transliterator::toLatin(trim($row[0] ?? ''));
            $signatura = \App\Helpers\SignatureHelper::normalize(trim($row[1] ?? ''));
            $autor = Transliterator::toLatin(trim($row[3] ?? ''));
            $naziv = Transliterator::toLatin(trim($row[4] ?? ''));

            Log::info("Red {$redBroj}: čitam → inv: '{$invBrojIzFajla}', autor: '{$autor}', naziv: '{$naziv}'");

            try {
                if ($invBrojIzFajla === '' && $signatura === '') {
                    Log::error("Red {$redBroj}: preskočen — nema inventarskog broja ni signature");
                    $rezultat['greske'][] = "Red {$redBroj}: preskočen — nema inventarskog broja ni signature";
                    continue;
                }

                // IDEMPOTENCIJA PO PRIMERKU: inventarski broj je jedinstven ID
                // fizičkog primerka. Ako već postoji, taj red je već uvezen →
                // preskoči (sprečava dupliranje primeraka pri ponovnom uvozu).
                if ($invBrojIzFajla !== ''
                    && PrimerakKnjige::where('inventarski_broj', $invBrojIzFajla)->exists()) {
                    Log::warning("Red {$redBroj}: inventarski broj '{$invBrojIzFajla}' već postoji — preskočeno");
                    $rezultat['greske'][] = "Red {$redBroj}: inventarski broj \"{$invBrojIzFajla}\" već postoji — preskočeno";
                    continue;
                }

                // GRUPISANJE PO (naziv, autor): redovi sa istim nazivom i autorom su
                // PRIMERCI ISTE knjige (npr. dva primerka istog naslova). Nađi
                // postojeću knjigu ili je kreiraj. Red bez naziva/autora ne može da
                // se grupiše → postaje svoja knjiga (1 red = 1 knjiga + 1 primerak).
                $trenutnaKnjiga = null;
                if ($naziv !== '' && $autor !== '') {
                    $trenutnaKnjiga = Knjiga::where('naziv_knjige', $naziv)
                        ->where('autor', $autor)
                        ->first();
                }

                if ($trenutnaKnjiga === null) {
                    // Excel kolona F i dalje nosi „Mesto, godina" u jednom
                    // polju — ovde se razdvaja u zasebne kolone baze.
                    $izdanje = $this->razdvojiMestoGodinu(
                        Transliterator::toLatin(trim($row[5] ?? ''))
                    );

                    $trenutnaKnjiga = Knjiga::create([
                        'in_broj'                => $invBrojIzFajla,
                        'signatura'              => $signatura,
                        'datum'                  => now(),
                        'autor'                  => $autor ?: 'Nepoznat',
                        'naziv_knjige'           => $naziv ?: 'Bez naziva',
                        'mesto_izdavanja'        => $izdanje['mesto'],
                        'godina_izdavanja'       => $izdanje['godina'],
                        'izdavac'                => Transliterator::toLatin(trim($row[6] ?? '')) ?: null,
                        'nacin_nabavke'          => Transliterator::toLatin(trim($row[7] ?? '')) ?: null,
                        'isbn'                   => Transliterator::toLatin(trim($row[8] ?? '')) ?: null,
                        'slika'                  => 'images/default.png',
                    ]);

                    Log::info("Red {$redBroj}: NOVA knjiga → ID: {$trenutnaKnjiga->id}, inv: '{$invBrojIzFajla}'");

                    // Žanrovi (kolone J/index 9 i K/index 10) — samo za novu knjigu.
                    $zanrKolona  = Transliterator::toLatin(trim($row[9] ?? ''));
                    $zanrKolona2 = Transliterator::toLatin(trim($row[10] ?? ''));

                    if ($zanrKolona2 !== '') {
                        $zanrKolona = $zanrKolona . ',' . $zanrKolona2;
                    }

                    if ($zanrKolona !== '') {
                        $zanrNazivi = array_map('trim', preg_split('/[,\/]/', $zanrKolona));
                        $zanrIds = [];

                        foreach ($zanrNazivi as $zanrNaziv) {
                            if ($zanrNaziv === '') continue;

                            $zanrNaziv = mb_strtoupper(mb_substr($zanrNaziv, 0, 1)) . mb_strtolower(mb_substr($zanrNaziv, 1));

                            $zanr = Zanr::whereRaw('LOWER(naziv) = ?', [mb_strtolower($zanrNaziv, 'UTF-8')])->first();

                            if (!$zanr) {
                                $zanr = Zanr::create(['naziv' => $zanrNaziv]);
                            }

                            $zanrIds[] = $zanr->id;
                        }

                        if (!empty($zanrIds)) {
                            $trenutnaKnjiga->zanrovi()->attach($zanrIds);
                        }
                    }

                    $rezultat['knjige']++;
                    $uveženeKnjige[] = $trenutnaKnjiga;
                } else {
                    Log::info("Red {$redBroj}: dodajem primerak postojećoj knjizi #{$trenutnaKnjiga->id} ('{$naziv}')");
                }

                // UVEK kreiraj PRIMERAK — jedan Excel red = jedan primerak (po
                // inventarskom broju), bilo da je knjiga nova ili postojeća.
                PrimerakKnjige::create([
                    'knjiga_id'        => $trenutnaKnjiga->id,
                    'inventarski_broj' => $invBrojIzFajla,
                    'signatura'        => $signatura,
                    'status'           => 'slobodna',
                    'nacin_nabavke'    => Transliterator::toLatin(trim($row[7] ?? '')) ?: null,
                    'datum_unosa'      => now(),
                ]);

                Log::info("Red {$redBroj}: primerak dodat → inv: '{$invBrojIzFajla}'");

                $rezultat['primerci']++;

            } catch (\Exception $e) {
                Log::error("Red {$redBroj}: greška → " . $e->getMessage());
                $rezultat['greske'][] = "Red {$redBroj}: " . $e->getMessage();
            }
        }

        Log::info("Uvoz završen → knjige: {$rezultat['knjige']}, primerci: {$rezultat['primerci']}, greške: " . count($rezultat['greske']));

        // RAG embedding za novouvezene knjige — JEDAN bulk poziv NAKON uvoza
        // (tada knjige već imaju žanrove). Greška (embed servis nedostupan)
        // ne kvari uvoz, ali se VRAĆA korisniku kao upozorenje — bez toga
        // chatbot tiho ostane bez uvida u nove knjige.
        if (!empty($uveženeKnjige)) {
            try {
                $this->rag->reindexMany(collect($uveženeKnjige));
            } catch (RuntimeException $e) {
                Log::warning('RAG reindeks uvezenih knjiga nije uspeo: ' . $e->getMessage());
                $rezultat['greske'][] = 'UPOZORENJE: uvoz je uspeo, ali indeksiranje za AI '
                    . 'asistenta nije (embed servis nedostupan). Pokreni servis pa izvrši: '
                    . 'php artisan ai:reindex';
            }
        }

        // Keširani redosled „najčitanijih" i „najnovijih" (30 dana) posle
        // uvoza pokazuje na stare/obrisane knjige — mora se osvežiti.
        app(FeaturedKnjigeService::class)->clearCache();

        return $rezultat;
    }

    private function redJePrazan(array $row): bool
    {
        $invBroj   = Transliterator::toLatin(trim($row[0] ?? ''));
        $signatura = Transliterator::toLatin(trim($row[1] ?? ''));

        return $invBroj === '' && $signatura === '';
    }

    /**
     * Razdvaja Excel polje „Mesto, godina" („Beograd, 2023", „Cetinje 1994",
     * samo „Beograd" ili samo „2023") u zasebne vrednosti za kolone
     * mesto_izdavanja i godina_izdavanja. Godina je poslednji četvorocifreni
     * broj 1800–2099; ostatak (bez pratećih zareza/razmaka) je mesto.
     *
     * @return array{mesto: ?string, godina: ?int}
     */
    private function razdvojiMestoGodinu(string $tekst): array
    {
        $tekst = trim($tekst);

        if ($tekst === '') {
            return ['mesto' => null, 'godina' => null];
        }

        $godina = null;
        if (preg_match_all('/\b(1[89]\d{2}|20\d{2})\b/', $tekst, $m)) {
            $godina = (int) end($m[1]);
            $pos    = strrpos($tekst, (string) end($m[1]));
            $tekst  = substr($tekst, 0, $pos) . substr($tekst, $pos + 4);
        }

        $mesto = trim($tekst, " \t\n\r\0\x0B,;-–");

        return ['mesto' => $mesto !== '' ? $mesto : null, 'godina' => $godina];
    }
}