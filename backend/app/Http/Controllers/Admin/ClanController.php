<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ClanController extends Controller
{

    /**
     * Srpske poruke za pravila van PasswordPolicy. Bez njih Laravel vraća
     * ugrađene engleske ("The email field is required."), jer aplikacija nema
     * srpski jezički paket — a te poruke idu pravo u toast bibliotekaru.
     */
    private const PORUKE = [
        'ime.required'      => 'Polje Ime je obavezno.',
        'ime.max'           => 'Ime je predugačko.',
        'prezime.required'  => 'Polje Prezime je obavezno.',
        'prezime.max'       => 'Prezime je predugačko.',
        'email.required'    => 'Polje Email je obavezno.',
        'email.email'       => 'E-pošta nije u ispravnom formatu.',
        'email.unique'      => 'Ta e-adresa je već u upotrebi.',
        'password.required' => 'Polje Lozinka je obavezno.',
        'telefon.max'       => 'Broj telefona je predugačak.',
        'adresa.max'        => 'Adresa je predugačka.',
    ];

    /**
     * Pravila za broj telefona — ogledalo provere u public/js/notifications.js
     * (funkcija greskaTelefona). Klijentska provera je udobnost, ova je ta
     * koja stvarno važi: zahtev može da stigne i mimo forme.
     *
     * Priznata su dva zapisa: „+381 61 234 567" (najviše 13 znakova) i
     * „061 234 567" (najmanje 10). Razmaci, crtice, kose crte i zagrade su
     * prored i ne broje se; slova nisu dozvoljena.
     */
    private static function pravilaTelefona(): array
    {
        return ['nullable', 'string', 'max:20', function (string $atribut, mixed $vrednost, \Closure $greska): void {
            $unos = trim((string) $vrednost);

            if ($unos === '') {
                return;
            }

            if (preg_match('/\p{L}/u', $unos)) {
                $greska('Broj telefona ne sme da sadrži slova.');
                return;
            }

            $broj = preg_replace('/[\s\-\/().]/u', '', $unos);

            if (! preg_match('/^\+?\d+$/', $broj)) {
                $greska('Broj telefona sme da sadrži samo cifre, uz „+“ na početku.');
                return;
            }

            if (str_starts_with($broj, '+')) {
                if (mb_strlen($broj) > 13) {
                    $greska('Broj telefona u formatu +381… sme da ima najviše 13 znakova.');
                }
                return;
            }

            if (mb_strlen($broj) < 10) {
                $greska('Broj telefona u formatu 06… mora imati najmanje 10 znakova.');
            }
        }];
    }

    public function index()
    {
        $user = auth()->user();
        $clanovi = User::where('role', 'clan')->latest()->paginate(20);
        return view('admin.clanovi', compact('user', 'clanovi'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'ime'     => 'required|string|max:100',
            'prezime' => 'required|string|max:100',
            'email'   => 'required|email|unique:users',
            'password' => PasswordPolicy::rules(),
            'telefon' => self::pravilaTelefona(),
            'adresa'  => 'nullable|string|max:255',
        ], array_merge(PasswordPolicy::messages('password'), self::PORUKE));

        $data['password'] = Hash::make($data['password']);
        $data['role'] = 'clan';

        /* Broj kartice = NAJVEĆI postojeći + 1, a ne broj redova. `count() + 1`
           je pucao posle brisanja člana: kolona je unique, pa bi novi član
           dobio broj koji već postoji (CLAN-001..003, obriše se 002 →
           count = 2 → sledeći opet 003 → UNIQUE constraint failed).

           Cifre se čitaju iz zatečenog zapisa, pa format sme da bude i
           „CLAN-0003" (raniji, četvorocifreni oblik) — sledeći posle njega je
           „CLAN-004". Računa se u PHP-u, a ne SQL-om (CAST … AS INTEGER), da
           ne zavisi od dijalekta baze; broj članova je mali. */
        $poslednji = User::where('role', 'clan')
            ->whereNotNull('broj_clanske_karte')
            ->pluck('broj_clanske_karte')
            ->map(fn (string $karta) => (int) preg_replace('/\D/', '', $karta))
            ->max() ?? 0;

        // Tri cifre (CLAN-001); preko 999 str_pad ne seče nego pusti CLAN-1000.
        $data['broj_clanske_karte'] = 'CLAN-' . str_pad((string) ($poslednji + 1), 3, '0', STR_PAD_LEFT);

        $user = User::create($data);

        return response()->json([
            'success' => true,
            'message' => "Član {$user->ime} {$user->prezime} je dodat.",
            'data'    => $user,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Bibliotekar sme da menja SAMO članove — promena emaila/lozinke
        // admina ili drugog bibliotekara bila bi preuzimanje tog naloga
        if (!$user->isClan()) {
            return response()->json(['success' => false, 'message' => 'Možete menjati samo članove.'], 403);
        }

        $data = $request->validate([
            'ime'      => 'required|string|max:100',
            'prezime'  => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email,' . $id,
            'telefon'  => self::pravilaTelefona(),
            'password' => PasswordPolicy::rules(required: false),
        ], array_merge(PasswordPolicy::messages('password'), self::PORUKE));

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => "Podaci člana {$user->ime} {$user->prezime} su sačuvani.",
            'data'    => $user,
        ]);
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // role je kastovan u KorisnikUloga enum, pa poređenje sa stringom
        // 'admin' nikad ne bi bilo tačno — zato provera ide preko isClan().
        // Brisati se smeju SAMO članovi (ne admin, ne drugi bibliotekar)
        if (!$user->isClan()) {
            return response()->json(['success' => false, 'message' => 'Možete obrisati samo članove.'], 422);
        }

        $user->delete();

        return response()->json(['success' => true, 'message' => 'Član obrisan']);
    }
}