<?php

namespace App\Http\Controllers;

use App\Models\PrimerakKnjige;
use App\Models\Knjiga;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrimerakController extends Controller
{
    // GET /primerci - lista primeraka
    public function index(Request $request)
    {
        $knjiga_id = $request->get('knjiga_id');

        $query = PrimerakKnjige::query();

        if ($knjiga_id) {
            $query->where('knjiga_id', $knjiga_id);
        }

        return response()->json([
            'primerci' => $query->with(['knjiga', 'aktivnoZaduzivanje.clan'])->get()
        ]);
    }

    // POST /primerci - dodaj jedan primerak
    public function store(Request $request)
    {
        $data = $request->validate([
            'knjiga_id'        => 'required|exists:knjige,id',
            'inventarski_broj' => 'required|string|unique:primerci_knjiga,inventarski_broj',
        ]);

        $primerak = PrimerakKnjige::create([
            'knjiga_id'        => $data['knjiga_id'],
            'inventarski_broj' => $data['inventarski_broj'],
            'status'           => 'slobodna',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Primerak {$primerak->inventarski_broj} je dodat.",
            'data'    => $primerak,
        ], 201);
    }

    // POST /primerci/bulk-add - bulk dodavanje primeraka
    public function bulkAdd(Request $request)
    {
        $data = $request->validate([
            'knjiga_id'      => 'required|exists:knjige,id',
            'broj_primeraka' => 'required|integer|min:1|max:20',
        ]);

        $knjiga = Knjiga::findOrFail($data['knjiga_id']);

        $maxPrimerci = (int)(DB::table('primerci_knjiga')
            ->max(DB::raw('CAST(inventarski_broj AS INTEGER)')) ?? 0);

        $dodati = [];

        for ($i = 1; $i <= $data['broj_primeraka']; $i++) {
            $nextInv = $maxPrimerci + $i;

            $primerak = PrimerakKnjige::create([
                'knjiga_id'        => $knjiga->id,
                'inventarski_broj' => (string)$nextInv,
                'status'           => 'slobodna',
            ]);

            $dodati[] = $primerak;
        }

        return response()->json([
            'success' => true,
            'message' => "Uspešno dodato {$data['broj_primeraka']} primeraka",
            'data'    => $dodati,
        ], 201);
    }

    // PATCH /primerci/{id} - ažuriraj status primerka
    public function update(Request $request, $id)
    {
        $primerak = PrimerakKnjige::findOrFail($id);

        $data = $request->validate([
            'status' => 'required|in:slobodna,rezervisana,zaduzena',
        ]);

        $primerak->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Status primerka ažuriran',
            'data'    => $primerak,
        ]);
    }

    // DELETE /primerci/{id} - obriši primerak
    public function destroy($id)
    {
        $primerak = PrimerakKnjige::findOrFail($id);

        if ($primerak->status->value !== 'slobodna') {
           return response()->json([
           'success' => false,
           'message' => 'Ne možete obrisati primerak koji je rezervisan ili zadužen',
           ], 422);
      }

        $inventarski = $primerak->inventarski_broj;   // čita se PRE brisanja
        $primerak->delete();

        return response()->json([
            'success' => true,
            'message' => "Primerak {$inventarski} je obrisan.",
        ]);
    }
}