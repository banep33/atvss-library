<?php

use Illuminate\Support\Facades\Route;
use App\Models\{Knjiga, User, PredlogKnjige, Notifikacija};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\RezervacijaController;
use App\Http\Controllers\ZaduzivanjeController;
use App\Http\Controllers\NotifikacijaController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ClanController;
use App\Http\Controllers\Admin\ZanrController;
use App\Http\Controllers\KnjigaController;

// ============================================
// STORAGE ROUTE - Servira slike iz storage/app/public/
// ============================================
Route::get('/storage/{path}', function ($path) {
    // Regex rute dozvoljava tačke, pa bi ".." omogućio izlazak iz storage
    // foldera (path traversal) — takve putanje se odmah odbijaju
    if (str_contains($path, '..') || !Storage::disk('public')->exists($path)) {
        return response()->file(public_path('images/default.jpg')); // Fallback na default sliku
    }
    
    $file = Storage::disk('public')->get($path);
    $mimeType = Storage::disk('public')->mimeType($path);
    
    return response($file, 200)->header('Content-Type', $mimeType ?? 'application/octet-stream');
})->where('path', '[a-zA-Z0-9_\-\.\/]+');

// ============================================
// JAVNE RUTE (bez logovanja)
// ============================================

// Početna stranica (prikaz knjiga)
Route::get('/', function () {
    $user = auth()->user();
    return view('knjige.index', compact('user'));
});

// Login stranica
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
// Grubi štit od flooda; PRAVO ograničavanje pokušaja (email+IP, pamćenje kroz
// refresh) radi LoginController — zato je ovde granica šira, da middleware
// ne "pojede" zahtev pre kontrolera i time zaobiđe trajno odbrojavanje
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:30,1');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');


// Prikaz pojedinačne knjige
Route::get('/knjige/{id}', function ($id) {
    $knjiga = Knjiga::with(['zanrovi'])->findOrFail($id);
    $user = auth()->user();

    // Knjige istog autora
    $autorKnjige = Knjiga::where('autor', $knjiga->autor)
        ->where('id', '!=', $knjiga->id)
        ->limit(10)
        ->get();

    return view('knjige.show', compact('knjiga', 'autorKnjige', 'user'));
});

// Info sekcija — opšte informacije o biblioteci; javna, dostupna i gostu
// (view koristi $user samo unutar @if($user), pa null prolazi bezbedno).
Route::get('/info', function () {
    $user = auth()->user();
    $predlozi = PredlogKnjige::with('user')->latest()->paginate(10);
    return view('info.index', compact('user', 'predlozi'));
});

// ============================================
// RUTE ZA ULOGOVANE KORISNIKE
// ============================================

Route::middleware(['auth'])->group(function () {

    Route::get('/notifikacije/moja', [NotifikacijaController::class, 'moje'])
    ->name('notifikacije.index');

    // ============================================
    // PROFIL RUTE
    // ============================================

    Route::get('/profil',          [ProfilController::class, 'index'])  ->name('profil.index');
    Route::post('/profil/update',  [ProfilController::class, 'update']) ->name('profil.update');
    // Throttle: bez njega bi neko sa ukradenom sesijom mogao neograničeno
    // da pogađa staru lozinku (forma je traži pre promene)
    Route::post('/profil/lozinka', [ProfilController::class, 'lozinka'])->name('profil.lozinka')
        ->middleware('throttle:5,1');

    // zaduzene knjige clana
    Route::get('/zaduzene', [ZaduzivanjeController::class, 'mojeZaduzene'])->name('zaduzene.index');

    // bibliotekar - inventarna knjiga
    Route::get('/admin/inventarna-knjiga', [KnjigaController::class, 'inventarnaKnjiga']);

});

// bibliotekar rute
Route::middleware(['bibliotekar'])->group(function () {
    Route::get('/admin/rezervacije',            [AdminController::class, 'rezervacije']);
    Route::get('/admin/clanovi',                [ClanController::class, 'index']);
    Route::get('/admin/zanrovi',                [ZanrController::class, 'index']);
    Route::get('/admin/zanrovi/{id}/knjige',    [ZanrController::class, 'knjige'])->name('zanrovi.knjige');
    Route::get('/admin/knjige/import',          [AdminController::class, 'importForm']);
    Route::post('/admin/knjige/import',         [AdminController::class, 'importStore']);
});

