<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Rezervacija;
use App\Models\Zaduzivanje;
use App\Models\PredlogKnjige;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfilController extends Controller
{
    /**
     * Prikazuje profil ulogovanog korisnika.
     * Različit sadržaj za člana, bibliotekara i admina.
     */
    public function index()
    {
        $user = auth()->user();

        $aktivneRezervacije = collect();
        $istorijaRezervacija = collect();
        $statistike = null;

        if ($user->isClan()) {
            $aktivneRezervacije = Rezervacija::where('user_id', $user->id)
                ->whereIn('status', ['na_cekanju', 'odobrena'])
                ->with('knjiga')
                ->orderBy('datum_rezervacije', 'desc')
                ->get();

            $istorijaRezervacija = Rezervacija::where('user_id', $user->id)
                ->whereIn('status', ['preuzeta', 'odbijena', 'otkazana', 'istekla'])
                ->orWhere(function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                          ->where('vracena', true);
                })
                ->with('knjiga')
                ->orderBy('updated_at', 'desc')
                ->get();
        }

        if ($user->isAdmin() || $user->isBibliotekar()) {
            $statistike = [
                'aktivne_rezervacije' => Rezervacija::whereIn('status', ['na_cekanju', 'odobrena'])->count(),
                'zaduzene_knjige'     => Rezervacija::where('status', 'preuzeta')->where('vracena', false)->count(),
                'kasnjenja'           => Zaduzivanje::whereNull('datum_vracanja')->where('rok_vracanja', '<', now())->count(),
                'ukupno_clanova'      => User::where('role', 'clan')->count(),
            ];
        }

        return view('profil.index', compact('user', 'aktivneRezervacije', 'istorijaRezervacija', 'statistike'));
    }

    /**
     * Ažurira osnovne podatke korisnika (ime, prezime, email).
     */
    public function update(Request $request)
    {
        $user = auth()->user();

        $data = $request->validate([
            'ime'     => 'required|string|max:100',
            'prezime' => 'required|string|max:100',
            'email'   => 'required|email|unique:users,email,' . $user->id,
        ], [
            'ime.required'     => 'Polje Ime je obavezno.',
            'prezime.required' => 'Polje Prezime je obavezno.',
            'email.required'   => 'Polje Email je obavezno.',
            'email.email'      => 'E-pošta nije u ispravnom formatu.',
            'email.unique'     => 'Ta e-adresa je već u upotrebi.',
            'ime.max'          => 'Ime je predugačko.',
            'prezime.max'      => 'Prezime je predugačko.',
        ]);

        $user->update($data);

        return back()->with('success', 'Podaci uspešno ažurirani.');
    }

    /**
     * Menja lozinku korisnika.
     */
    public function lozinka(Request $request)
    {
        $user = auth()->user();

        /* Poruke su srpske i za pravila van PasswordPolicy — bez njih Laravel
           vraća ugrađene engleske ("The ... field is required."), jer aplikacija
           nema srpski jezički paket. */
        $request->validate([
            'stara_lozinka' => 'required',
            'nova_lozinka'  => array_merge(PasswordPolicy::rules(), ['confirmed']),
        ], array_merge(PasswordPolicy::messages('nova_lozinka'), [
            'stara_lozinka.required' => 'Unesite staru lozinku.',
            'nova_lozinka.required'  => 'Unesite novu lozinku.',
            'nova_lozinka.confirmed' => 'Potvrda lozinke se ne poklapa sa novom lozinkom.',
        ]));

        if (!Hash::check($request->stara_lozinka, $user->password)) {
            return back()->with('error', 'Stara lozinka nije ispravna.');
        }

        // Nova lozinka ne sme biti ista kao stara
        if (Hash::check($request->nova_lozinka, $user->password)) {
            return back()->with('error', 'Nova lozinka mora biti različita od stare.');
        }

        $user->update(['password' => Hash::make($request->nova_lozinka)]);

        // Odjavljuje sve OSTALE uređaje/sesije ovog naloga (tekuća sesija
        // ostaje) — ukradena sesija umire čim vlasnik promeni lozinku
        Auth::logoutOtherDevices($request->nova_lozinka);

        return back()->with('success', 'Lozinka uspešno promenjena.');
    }
}