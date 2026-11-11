<?php

namespace App\Http\Controllers;

use App\Models\{Rezervacija, Zaduzivanje, User};
use App\Services\RezervacijaService;
use App\Enums\RezervacijaStatus;
use App\Enums\PrimerakStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RezervacijaController extends Controller
{
    public function __construct(
        private RezervacijaService $rezervacijaService
    ) {}

    // POST /rezervacije
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'   => 'required|exists:users,id',
            'knjiga_id' => 'required|exists:knjige,id',
        ]);

        try {
            $rezervacija = $this->rezervacijaService->kreiraj(
                $data['user_id'],
                $data['knjiga_id']
            );

            return response()->json([
                'success' => true,
                'message' => 'Rezervacija poslata. Čekajte odobrenje bibliotekara.',
                'data'    => $rezervacija,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // GET /rezervacije
    public function index(Request $request)
    {
        $knjiga_id = $request->get('knjiga_id');

        $query = Rezervacija::query()
            ->whereIn('status', [RezervacijaStatus::NaCekanju, RezervacijaStatus::Odobrena]);

        if ($knjiga_id) {
            $query->where('knjiga_id', $knjiga_id);
        }

        return response()->json([
            'rezervacije' => $query->with('user')->get()
        ]);
    }

    // PATCH /rezervacije/{id}/odobri
    public function odobri($id)
    {
        try {
            $rezervacija = Rezervacija::with(['knjiga', 'user'])->findOrFail($id);
            $this->rezervacijaService->odobri($rezervacija);

            return response()->json([
                'success' => true,
                'message' => "Rezervacija knjige „{$rezervacija->knjiga?->naziv_knjige}“ "
                             . "za {$rezervacija->user?->ime} {$rezervacija->user?->prezime} je odobrena.",
            ]);

        } catch (\Exception $e) {
            Log::error('Greška pri odobravanju:', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // PATCH /rezervacije/{id}/odbij
    public function odbij($id)
    {
        try {
            $rezervacija = Rezervacija::with(['knjiga', 'user'])->findOrFail($id);
            $this->rezervacijaService->odbij($rezervacija);

            return response()->json([
                'success' => true,
                'message' => "Rezervacija knjige „{$rezervacija->knjiga?->naziv_knjige}“ "
                             . "je odbijena i korisnik je obavešten.",
            ]);

        } catch (\Exception $e) {
            Log::error('Greška pri odbijanju rezervacije:', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // PATCH /rezervacije/{id}/zaduzi
    public function zaduzi(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'dana_zaduzenja' => 'nullable|integer|min:1|max:90',
            ]);
            $dana = $validated['dana_zaduzenja'] ?? 14;

            $rezervacija = Rezervacija::with('knjiga')->findOrFail($id);

            if ($rezervacija->status === RezervacijaStatus::Preuzeta) {
                return response()->json(['success' => false, 'message' => 'Knjiga je već zadužena']);
            }
            if ($rezervacija->status === RezervacijaStatus::Odbijena) {
                return response()->json(['success' => false, 'message' => 'Rezervacija je odbijena']);
            }

            $primerak = $rezervacija->knjiga->primerci()
                ->whereIn('status', [PrimerakStatus::Slobodna, PrimerakStatus::Rezervisana])
                ->whereDoesntHave('aktivnoZaduzivanje')
                ->first();

            if (!$primerak) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nema slobodnih primeraka za ovu knjigu',
                ]);
            }

            $this->rezervacijaService->zaduzi($rezervacija, $primerak, $dana);

            return response()->json(['success' => true, 'message' => 'Knjiga uspešno zadužena']);

        } catch (\Exception $e) {
            Log::error('Greška pri zaduženju:', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return response()->json(['success' => false, 'message' => 'Greška: ' . $e->getMessage()]);
        }
    }

    // PATCH /rezervacije/{id}/vrati
    public function vrati($id)
    {
        try {
            $rezervacija = Rezervacija::findOrFail($id);

            if ($rezervacija->vracena) {
                return response()->json(['success' => false, 'message' => 'Knjiga je već vraćena']);
            }

            if (!$rezervacija->primerak_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rezervacija nema dodeljen primerak. Molimo kontaktirajte administratora.',
                ]);
            }

            $zaduzivanje = Zaduzivanje::where('primerak_id', $rezervacija->primerak_id)
                ->where('user_id', $rezervacija->user_id)
                ->whereNull('datum_vracanja')
                ->latest()
                ->first();

            $this->rezervacijaService->vrati($rezervacija, $zaduzivanje);

            Log::info('Knjiga uspešno vraćena:', [
                'rezervacija_id' => $id,
                'primerak_id'    => $rezervacija->primerak_id
            ]);

            return response()->json(['success' => true, 'message' => 'Knjiga uspešno vraćena']);

        } catch (\Exception $e) {
            Log::error('Greška pri vraćanju:', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Greška: ' . $e->getMessage()]);
        }
    }

    // DELETE /rezervacije/{id}/otkazi
    public function otkazi($id)
    {
        $user = auth()->user();
        $rezervacija = Rezervacija::findOrFail($id);

        if ($rezervacija->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Nemate dozvolu za otkazivanje ove rezervacije.',
            ], 403);
        }

        // Korisnik može da otkaže rezervaciju SAMO dok je na čekanju ili odobrena
        // (ne i ako je odbijena, preuzeta, istekla, vraćena ili već otkazana).
        if (!in_array($rezervacija->status, [
            RezervacijaStatus::NaCekanju,
            RezervacijaStatus::Odobrena,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Rezervaciju možete otkazati samo dok je na čekanju ili odobrena.',
            ], 400);
        }

        DB::transaction(function () use ($rezervacija) {
        if ($rezervacija->status === RezervacijaStatus::Odobrena) {
            $rezervacija->primerak?->update(['status' => PrimerakStatus::Slobodna]);
        }
        $rezervacija->update(['status' => RezervacijaStatus::Otkazana]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Rezervacija je uspešno otkazana.',
        ]);
    }

    // PATCH /rezervacije/{id}/otkazivanje-vidjeno
    // Bibliotekar/admin „pročita" obaveštenje da je korisnik otkazao rezervaciju
    // (obaveštenje se prikazuje u admin tabu Na čekanju/Odobrene dok je vidjena=false).
    // Ruta je u admin/bibliotekar zaštićenoj grupi.
    public function oznaciOtkazivanjeVidjeno($id)
    {
        $rezervacija = Rezervacija::findOrFail($id);
        $rezervacija->update(['otkazana_vidjena' => true]);

        return response()->json(['success' => true]);
    }

    // DELETE /rezervacije/{id}
    // Bibliotekar uklanja red iz tabele (odbijena ili istekla rezervacija).
    // Zapis se BRIŠE, a ne prepisuje u status Otkazana — inače bi istekla
    // rezervacija u istoriji i asistentu izgledala kao da ju je korisnik
    // otkazao. Trag ostaje u arhivi (arhiva.rezervacija_id je onDelete:set null,
    // uz denormalizovana polja) i u obaveštenju koje je korisnik već dobio.
    public function destroy($id)
    {
        $rezervacija = Rezervacija::findOrFail($id);

        // Brisanje je dozvoljeno samo nad rezervacijama koje su završile svoj
        // tok. Odobrena prolazi samo ako joj je rok preuzimanja istekao, a
        // dnevna komanda još nije odradila prolaz.
        $zavrsena = in_array($rezervacija->status, [
            RezervacijaStatus::Odbijena,
            RezervacijaStatus::Istekla,
            RezervacijaStatus::Otkazana,
        ], true);

        $istekaoRok = $rezervacija->status === RezervacijaStatus::Odobrena
            && $rezervacija->rok_preuzimanja
            && $rezervacija->rok_preuzimanja->isPast();

        if (!$zavrsena && !$istekaoRok) {
            return response()->json([
                'success' => false,
                'message' => 'Moguće je obrisati samo odbijenu, isteklu ili otkazanu rezervaciju.',
            ], 400);
        }

        DB::transaction(function () use ($rezervacija, $istekaoRok) {
            // Kada je rok istekao a komanda još nije odradila prolaz, primerak
            // je i dalje zaključan za ovu rezervaciju i bez ovoga bi zauvek
            // ostao „rezervisan". Isto pravilo koje primenjuje i otkazi().
            if ($istekaoRok) {
                $rezervacija->primerak?->update(['status' => PrimerakStatus::Slobodna]);
            }

            $rezervacija->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Rezervacija obrisana',
        ]);
    }

    public function mojStatusKnjige($knjigaId)
    {
    $user = auth()->user();

    $rezervacija = Rezervacija::where('user_id', $user->id)
        ->where('knjiga_id', $knjigaId)
        ->whereIn('status', [RezervacijaStatus::NaCekanju, RezervacijaStatus::Odobrena])
        ->first();

    return response()->json([
        'status_ove_rezervacije'  => $rezervacija?->status instanceof \BackedEnum
                                        ? $rezervacija->status->value
                                        : ($rezervacija->status ?? ''),
        'rezervacija_id'          => $rezervacija?->id,
        'rezervisao_ovu_knjigu'   => (bool) $rezervacija,
        'zaduzio_ovu_knjigu'      => Zaduzivanje::where('user_id', $user->id)
                                        ->whereNull('datum_vracanja')
                                        ->whereHas('primerak', fn($q) => $q->where('knjiga_id', $knjigaId))
                                        ->exists(),
        'ima_aktivno_zaduzenje'   => Zaduzivanje::where('user_id', $user->id)
                                        ->whereNull('datum_vracanja')
                                        ->exists(),
        'ima_aktivnu_rezervaciju' => Rezervacija::where('user_id', $user->id)
                                        ->where('status', RezervacijaStatus::Odobrena)
                                        ->exists(),
        'ima_na_cekanju'          => Rezervacija::where('user_id', $user->id)
                                        ->where('status', RezervacijaStatus::NaCekanju)
                                        ->exists(),
    ]);
    }
}