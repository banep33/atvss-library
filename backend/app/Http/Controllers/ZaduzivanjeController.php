<?php

namespace App\Http\Controllers;

use App\Models\{Zaduzivanje, PrimerakKnjige, User};
use App\Services\ZaduzivanjeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZaduzivanjeController extends Controller
{
    public function __construct(
        private ZaduzivanjeService $zaduzivanjeService
    ) {}

    // GET /api/zaduzivanja
    public function index(Request $request)
    {
        $clan_id   = $request->get('clan_id');
        $knjiga_id = $request->get('knjiga_id');

        $query = Zaduzivanje::query()->whereNull('datum_vracanja');

        if ($clan_id) {
            $query->where('user_id', $clan_id);
        }

        if ($knjiga_id) {
            $query->whereHas('primerak', fn($q) => $q->where('knjiga_id', $knjiga_id));
        }

        return response()->json([
            'zaduzivanja' => $query->with(['user', 'primerak.knjiga'])->get()
        ]);
    }

    // POST /api/zaduzivanja
    public function store(Request $request)
    {
        $data = $request->validate([
            'clan_id'      => 'required|exists:users,id',
            'primerak_id'  => 'required|exists:primerci_knjiga,id',
            'rok_vracanja' => 'required|date|after:today',
        ]);

        try {

            // Proveri da li korisnik vec ima aktivno zaduzenje
            $aktivnoZaduzenje = Zaduzivanje::where('user_id', $data['clan_id'])
               ->whereNull('datum_vracanja')
               ->first();

            if ($aktivnoZaduzenje) {
               return response()->json([
                   'success' => false,
                   'message' => 'Korisnik već ima zaduženu knjigu. Mora je vratiti pre novog zaduženja.',
                ], 422);
           }

            $this->zaduzivanjeService->zaduzi(
                $data['clan_id'],
                $data['primerak_id'],
                $data['rok_vracanja']
            );

            // Naziv knjige i ime člana ulaze u poruku, da bibliotekar u toast-u
            // vidi ŠTA je zadužio i KOME.
            $primerak = \App\Models\PrimerakKnjige::with('knjiga')->find($data['primerak_id']);
            $clan     = \App\Models\User::find($data['clan_id']);

            return response()->json([
                'success' => true,
                'message' => "Knjiga „{$primerak?->knjiga?->naziv_knjige}“ je zadužena na ime "
                             . "{$clan?->ime} {$clan?->prezime}.",
            ], 201);

        } catch (\Exception $e) {
            Log::error('Greška pri direktnom zaduženju:', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // PATCH /zaduzivanja/{id}/vrati
    public function vrati($id)
    {
        try {
            $zaduzivanje = Zaduzivanje::with(['user', 'primerak.knjiga'])->findOrFail($id);
            $naziv = $zaduzivanje->primerak?->knjiga?->naziv_knjige;   // pre vraćanja
            $this->zaduzivanjeService->vrati($zaduzivanje);

            return response()->json([
                'success' => true,
                'message' => $naziv
                    ? "Knjiga „{$naziv}“ je vraćena."
                    : 'Knjiga je vraćena.',
            ]);

        } catch (\Exception $e) {
            Log::error('Greška pri vraćanju zaduženja:', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    // zaduzene knjige clana
    public function mojeZaduzene()
    {
    $user = auth()->user();

    $zaduzivanje = Zaduzivanje::with(['primerak.knjiga'])
        ->where('user_id', $user->id)
        ->whereNull('datum_vracanja')
        ->latest()
        ->first();

    return view('knjige.zaduzene', compact('user', 'zaduzivanje'));
    }

}