<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\{Knjiga, PrimerakKnjige, Zanr, Rezervacija, User, Zaduzivanje};
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\KnjigaController;
use App\Http\Controllers\RezervacijaController;
use App\Http\Controllers\PrimerakController;
use App\Http\Controllers\ZaduzivanjeController;
use App\Http\Controllers\NotifikacijaController;
use App\Http\Controllers\Admin\ClanController;
use App\Http\Controllers\Admin\ZanrController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AiChatController;
use App\Http\Middleware\EnsureEvalToken;

// ============================================
// JAVNE RUTE (bez autentifikacije)
// ============================================

Route::get('/knjige', [KnjigaController::class, 'index']);
Route::get('/knjige/{id}', [KnjigaController::class, 'show']);
Route::get('/zanrovi', [ZanrController::class, 'indexApi']);
// Knjige jednog žanra — koristi modal na stranici knjige. Javna je, kao i
// /knjige, i vraća strogo manje od nje (id, naslov, autor).
Route::get('/zanrovi/{id}/knjige', [ZanrController::class, 'knjigeApi']);

Route::get('/najcitanije-knjige', [KnjigaController::class, 'najcitanije']);
Route::get('/najnovije-knjige', [KnjigaController::class, 'najnovije']);

// ============================================
// ULOGOVANI KORISNICI (član, bibliotekar, admin)
// ============================================

Route::middleware(['auth'])->group(function () {

    Route::post('/rezervacije', [RezervacijaController::class, 'store']);
    Route::delete('/rezervacije/{id}/otkazi', [RezervacijaController::class, 'otkazi']);

    // E-knjige — link se daje samo ulogovanim korisnicima.
    // Kod mora biti broj kartice ULOGOVANOG korisnika (hash_equals = poređenje
    // otporno na timing napad), a throttle sprečava pogađanje brojeva kartica
    Route::post('/eknjige/verify', function (Request $request) {
        $data = $request->validate([
            'knjiga_id' => 'required|exists:knjige,id',
            'kod'       => 'required|string|max:50',
        ]);

        $user = $request->user();

        if (!$user || !hash_equals((string) $user->broj_clanske_karte, $data['kod'])) {
            return response()->json(['success' => false, 'message' => 'Neispravan kod'], 403);
        }

        $knjiga = Knjiga::findOrFail($data['knjiga_id']);

        return response()->json(['success' => true, 'link' => $knjiga->eknjiga_link]);
    })->middleware('throttle:10,1');

    Route::patch('/notifikacije/{id}/procitano', [NotifikacijaController::class, 'procitano']);

    Route::get('/moj-status-knjige/{knjigaId}', [RezervacijaController::class, 'mojStatusKnjige']);


    // clan proverava svoje zaduzenje/rezervaciju
    /*Route::get('/moj-status', function (Request $request) {
        $userId = auth()->id();
        
        $imaZaduzenje = \App\Models\Zaduzivanje::where('user_id', $userId)
            ->whereNull('datum_vracanja')
            ->exists();
            
        $imaRezervaciju = \App\Models\Rezervacija::where('user_id', $userId)
            ->whereIn('status', [
                \App\Enums\RezervacijaStatus::NaCekanju,
                \App\Enums\RezervacijaStatus::Odobrena,
                
            ])
            ->exists();
            
        return response()->json([
            'ima_aktivno_zaduzenje'   => $imaZaduzenje,
            'ima_aktivnu_rezervaciju' => $imaRezervaciju,
        ]);
    });*/
});

// ============================================
// BIBLIOTEKAR I ADMIN
// ============================================

Route::middleware(['bibliotekar'])->group(function () {

    // Knjige
    // Izdvajanje u "najnovije" je administratorska radnja — običan član
    // ne sme da menja šta se ističe na početnoj strani
    Route::post('/admin/najnovije-knjige/{id}', [KnjigaController::class, 'dodajUNajnovije']);
    Route::delete('/admin/najnovije-knjige/{id}', [KnjigaController::class, 'ukloniIzNajnovije']);

    Route::post('/knjige', [KnjigaController::class, 'store']);
    Route::match(['patch', 'post'], '/knjige/{id}', [KnjigaController::class, 'update']);
    Route::delete('/knjige/{id}', [KnjigaController::class, 'destroy']);

    // Žanrovi
    Route::post('/zanrovi', [ZanrController::class, 'store']);
    Route::delete('/zanrovi/{id}', [ZanrController::class, 'destroy']);

    // Primerci
    Route::get('/primerci', [PrimerakController::class, 'index']);
    Route::post('/primerci', [PrimerakController::class, 'store']);
    Route::post('/primerci/bulk-add', [PrimerakController::class, 'bulkAdd']);
    Route::patch('/primerci/{id}', [PrimerakController::class, 'update']);
    Route::delete('/primerci/{id}', [PrimerakController::class, 'destroy']);

    // Zadužavanja
    Route::get('/zaduzivanja', [ZaduzivanjeController::class, 'index']);
    Route::post('/zaduzivanja', [ZaduzivanjeController::class, 'store']);
    Route::patch('/zaduzivanja/{id}/vrati', [ZaduzivanjeController::class, 'vrati']);

    // Rezervacije — upravljanje od strane bibliotekara
    Route::get('/rezervacije', [RezervacijaController::class, 'index']);
    Route::patch('/rezervacije/{id}/odobri', [RezervacijaController::class, 'odobri']);
    Route::patch('/rezervacije/{id}/odbij', [RezervacijaController::class, 'odbij']);
    Route::patch('/rezervacije/{id}/zaduzi', [RezervacijaController::class, 'zaduzi']);
    Route::patch('/rezervacije/{id}/vrati', [RezervacijaController::class, 'vrati']);
    Route::patch('/rezervacije/{id}/otkazivanje-vidjeno', [RezervacijaController::class, 'oznaciOtkazivanjeVidjeno']);
    Route::delete('/rezervacije/{id}', [RezervacijaController::class, 'destroy']);

    // Korisnici
    Route::get('/korisnici', function (Request $request) {
        $tip = $request->get('tip');
        $query = User::query();
        if ($tip === 'clan') {
            $query->where('role', 'clan');
        } elseif ($tip === 'admin') {
            $query->where('role', 'admin');
        }
        return response()->json([
            'korisnici' => $query->select('id', 'ime', 'prezime', 'broj_clanske_karte', 'tip_clana')->get()
        ]);
    });

    // Članovi
    Route::post('/clanovi', [ClanController::class, 'store']);
    Route::patch('/clanovi/{id}', [ClanController::class, 'update']);
    Route::delete('/clanovi/{id}', [ClanController::class, 'destroy']);

    // Admin pomoćne rute
    Route::get('/admin/sledeci-inventarski-broj', [AdminController::class, 'sledeciInventarskiBroj']);
    Route::post('/admin/cleanup-rezervacije', [AdminController::class, 'cleanupRezervacije']);
    Route::post('/admin/cleanup-istekle-rezervacije', [AdminController::class, 'cleanupIstekleRezervacije']);
});

// ============================================
// SAMO ADMIN
// ============================================

Route::middleware(['admin'])->group(function () {
    Route::get('/admin/debug-brojevi', [AdminController::class, 'debugBrojevi']);
    Route::get('/admin/debug-intl', function () {          
        return response()->json([
            'intl_enabled'    => extension_loaded('intl'),
            'transliterator'  => class_exists('Transliterator'),
        ]);
    });

    Route::match(['DELETE', 'POST'], '/admin/delete-all-knjige', [AdminController::class, 'deleteAllKnjige']);
});


// backend/routes/api.php — DODATI na kraj postojećeg fajla
// (Ne zamenjuje ceo fajl, samo dodaje AI rute)



/*
|--------------------------------------------------------------------------
| AI Chat Routes — Faza 1
|--------------------------------------------------------------------------
|
| auth            → Laravelov session guard (proverava da li je korisnik ulogovan)
| throttle:10,1   → max 10 zahteva po minuti po korisniku
|                   jedan Ollama poziv troši ~3-8s CPU — štitimo server
|
*/
// Rute za ČLANA (zaštićene) — standardne
Route::middleware(['auth', 'throttle:10,1'])
    ->prefix('ai')
    ->group(function () {
        Route::post('/chat', [AiChatController::class, 'chat'])->name('ai.chat');
    });

// ← IZMENJENO: stream ruta koristi api.sse grupu
Route::middleware(['api.sse', 'auth', 'throttle:10,1'])
    ->prefix('ai')
    ->group(function () {
        Route::post('/chat/stream', [AiChatController::class, 'stream'])->name('ai.chat.stream');
    });

// Rute za GOSTA — standardne
Route::middleware(['throttle:5,1'])
    ->prefix('ai')
    ->group(function () {
        Route::post('/guest/chat', [AiChatController::class, 'guestChat'])->name('ai.guest.chat');
    });

// ← IZMENJENO: guest stream ruta koristi api.sse grupu
Route::middleware(['api.sse', 'throttle:5,1'])
    ->prefix('ai')
    ->group(function () {
        Route::post('/guest/chat/stream', [AiChatController::class, 'guestStream'])->name('ai.guest.chat.stream');
    });

/*
|--------------------------------------------------------------------------
| EVAL ruta (Faza D) — SAMO za lokalno merenje (golden/held-out)
|--------------------------------------------------------------------------
| Isti pipeline kao guest, ali BEZ throttle-a — da harness pusti ceo set brzo.
| Registruje se ISKLJUČIVO kada je AI_EVAL_ENABLED=true i token podešen, pa u
| produkciji ruta ne postoji. Zaštita: EnsureEvalToken (X-Eval-Token,
| hash_equals) + CSRF (nasleđen iz „api" grupe).
*/
if (config('ai.eval_enabled') && config('ai.eval_token') !== '') {
    Route::middleware([EnsureEvalToken::class])
        ->prefix('ai')
        ->group(function () {
            Route::post('/eval/chat', [AiChatController::class, 'evalChat'])->name('ai.eval.chat');
        });
}