<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\CleanupIstekleRezervacije;
use App\Http\Controllers\Controller;
use App\Models\Rezervacija;
use App\Models\PrimerakKnjige;
use App\Models\Zaduzivanje;
use App\Models\Arhiva;
use App\Models\User;
use App\Enums\RezervacijaStatus;
use App\Enums\PrimerakStatus;
use App\Services\KnjigaImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;

class AdminController extends Controller
{
    // ============================================
    // BIBLIOTEKAR METODE
    // ============================================

    // GET /admin/rezervacije
    public function rezervacije()
    {
        $user = auth()->user();

        // Pristigli zahtevi hronološki: najstariji → najnoviji (redosled se
        // definiše na nivou upita, po datumu nastanka rezervacije).
        $naCekanju = Rezervacija::where('status', RezervacijaStatus::NaCekanju)
            ->with(['user', 'knjiga', 'knjiga.primerci'])
            ->orderBy('datum_rezervacije', 'asc')
            ->get();

        // Uz odobrene se prikazuju i istekle: kada zakazana komanda prevede
        // rezervaciju u Istekla, primerak je već oslobođen i korisnik obavešten,
        // ali red mora da ostane u tabeli da bibliotekar vidi da je rok istekao
        // i da ga ukloni kantom.
        $odobrene = Rezervacija::whereIn('status', [
                RezervacijaStatus::Odobrena,
                RezervacijaStatus::Istekla,
            ])
            ->with(['user', 'knjiga', 'primerak'])
            ->orderBy('datum_odobrenja', 'desc')
            ->get();

        $odbijene = Rezervacija::where('status', RezervacijaStatus::Odbijena)
            ->with(['user', 'knjiga'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $preuzete = Rezervacija::where('status', RezervacijaStatus::Preuzeta)
            ->where('vracena', false)
            ->with(['user', 'knjiga', 'primerak'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $kasnjenja = Zaduzivanje::with(['user', 'primerak.knjiga'])
            ->whereNull('datum_vracanja')
            ->where('rok_vracanja', '<', now())
            ->whereHas('user')
            ->whereHas('primerak')
            ->orderBy('rok_vracanja', 'asc')
            ->get();

        $arhivaZahtevi  = Arhiva::where('tip', 'Zahtev')
            ->orderBy('datum_akcije', 'desc')->get();
        $arhivaOdobrene = Arhiva::where('tip', 'Odobrena')
            ->orderBy('datum_akcije', 'desc')->get();
        $arhivaOdbijene = Arhiva::where('tip', 'Odbijena')
            ->orderBy('datum_akcije', 'desc')->get();
        $arhivaZaduzene = Arhiva::where('tip', 'Zaduzena')
            ->orderBy('datum_akcije', 'desc')->get();
        $arhivaVracene  = Arhiva::where('tip', 'Vracena')
            ->orderBy('datum_akcije', 'desc')->get();

        // Obaveštenja: rezervacije koje je KORISNIK otkazao, a bibliotekar ih još
        // nije „pročitao" (otkazana_vidjena=false). Prikazuju se u odgovarajućem
        // tabu na mestu rezervacije: ako ima datum_odobrenja → bila je odobrena
        // (tab Odobrene), inače je bila na čekanju (tab Na čekanju).
        $otkazaneNaCekanju = Rezervacija::where('status', RezervacijaStatus::Otkazana)
            ->where('otkazana_vidjena', false)
            ->whereNull('datum_odobrenja')
            ->with(['user', 'knjiga'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $otkazaneOdobrene = Rezervacija::where('status', RezervacijaStatus::Otkazana)
            ->where('otkazana_vidjena', false)
            ->whereNotNull('datum_odobrenja')
            ->with(['user', 'knjiga'])
            ->orderBy('updated_at', 'desc')
            ->get();

        return view('admin.rezervacije', compact(
            'user', 'naCekanju', 'odobrene', 'odbijene',
            'preuzete', 'kasnjenja',
            'arhivaZahtevi', 'arhivaOdobrene', 'arhivaOdbijene',
            'arhivaZaduzene', 'arhivaVracene',
            'otkazaneNaCekanju', 'otkazaneOdobrene'
        ));
    }

    // GET /admin/sledeci-inventarski-broj
    public function sledeciInventarskiBroj(Request $request)
    {
        $type        = $request->get('for', 'knjige');
        $maxKnjige   = (int)(DB::table('knjige')->max(DB::raw('CAST(in_broj AS UNSIGNED)')) ?? 0);
        $maxPrimerci = (int)(DB::table('primerci_knjiga')->max(DB::raw('CAST(inventarski_broj AS INTEGER)')) ?? 0);
        $sledeci     = $type === 'primerci' ? $maxPrimerci + 1 : max($maxKnjige, $maxPrimerci) + 1;

        return response()->json(['broj' => $sledeci]);
    }

    // POST /admin/cleanup-rezervacije
    public function cleanupRezervacije()
    {
        try {
            $rezervisaniPrimerci = PrimerakKnjige::where('status', PrimerakStatus::Rezervisana)->get();
            $ocisceni   = [];
            $preskoceni = [];

            foreach ($rezervisaniPrimerci as $primerak) {
                $aktivna = Rezervacija::where('knjiga_id', $primerak->knjiga_id)
                    ->whereIn('status', [RezervacijaStatus::NaCekanju, RezervacijaStatus::Odobrena])
                    ->first();

                if (!$aktivna) {
                    $primerak->update(['status' => PrimerakStatus::Slobodna]);
                    $ocisceni[] = [
                        'primerak_id'      => $primerak->id,
                        'inventarski_broj' => $primerak->inventarski_broj,
                        'knjiga'           => $primerak->knjiga->naziv_knjige,
                    ];
                } else {
                    $preskoceni[] = [
                        'primerak_id'      => $primerak->id,
                        'inventarski_broj' => $primerak->inventarski_broj,
                        'knjiga'           => $primerak->knjiga->naziv_knjige,
                        'rezervacija_id'   => $aktivna->id,
                    ];
                }
            }

            return response()->json([
                'success'    => true,
                'message'    => 'Očišćeno ' . count($ocisceni) . ' primeraka',
                'ocisceni'   => $ocisceni,
                'preskoceni' => $preskoceni,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Greška: ' . $e->getMessage(),
            ], 500);
        }
    }

    // POST /admin/cleanup-istekle-rezervacije
    // Ručno pokretanje istog posla koji svakodnevno radi zakazana komanda
    // rezervacije:cleanup-istekle — logika je isključivo u toj komandi.
    public function cleanupIstekleRezervacije(CleanupIstekleRezervacije $komanda)
    {
        try {
            $ociscene = $komanda->obradi()->map(fn (Rezervacija $rezervacija) => [
                'rezervacija_id'  => $rezervacija->id,
                'knjiga'          => $rezervacija->knjiga->naziv_knjige,
                'korisnik'        => $rezervacija->user->ime . ' ' . $rezervacija->user->prezime,
                'rok_preuzimanja' => $rezervacija->rok_preuzimanja,
            ])->all();

            return response()->json([
                'success'  => true,
                'message'  => 'Očišćeno ' . count($ociscene) . ' isteklih rezervacija',
                'ociscene' => $ociscene,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Greška: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteAllKnjige()
    {
    try {
        DB::transaction(function () {
            DB::table('notifikacije')->delete();
            DB::table('zaduzivanja')->delete();
            DB::table('rezervacije')->delete();
            DB::table('primerci_knjiga')->delete();
            DB::table('knjiga_zanr')->delete(); // pivot tabela
            DB::table('knjige')->delete();
        });

        // Sirovi SQL delete zaobilazi Knjiga model evente → isprazni i RAG
        // vektorski indeks da ne ostanu vektori obrisanih knjiga.
        app(\App\Services\Ai\VectorStore::class)
            ->reset(\App\Services\Ai\VectorStore::KNJIGE);

        return response()->json([
            'success' => true,
            'message' => 'Sve knjige, primerci, rezervacije, zaduženja i notifikacije su uspešno obrisane.',
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Greška pri brisanju: ' . $e->getMessage(),
        ], 500);
    }
    }

    // GET /admin/knjige/import
    public function importForm()
    {
        $user = auth()->user();
        return view('admin.knjige-import', compact('user'));
    }

    // POST /admin/knjige/import
    public function importStore(Request $request, KnjigaImportService $service)
    {
        $request->validate(['fajl' => 'required|file|mimes:xlsx,xls|max:10240']);

        $putanja = $request->file('fajl')->store('temp');

        try {
            $rezultat = $service->uvezi(Storage::path($putanja));
        } finally {
            Storage::delete($putanja);
        }

        return back()->with('rezultat', $rezultat);
    }

}