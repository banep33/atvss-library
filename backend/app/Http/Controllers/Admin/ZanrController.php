<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Zanr, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZanrController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $zanrovi = Zanr::withCount('knjige')->get()
            ->sortBy(fn (Zanr $zanr) => self::abecedniKljuc($zanr->naziv), SORT_STRING)
            ->values();

        return view('admin.zanrovi', compact('user', 'zanrovi'));
    }

    /**
     * Ključ za abecedno ređanje po srpskoj latinici.
     *
     * I SQLite i PHP-ov podrazumevani sort porede bajtove, pa bi č, ć, š, ž i đ
     * završili iza z. Zato svako slovo dobija svoj redni broj u abecedi, pa
     * poređenje dobijenih niski daje ispravan redosled. Digrafi (dž, lj, nj) se
     * namerno ne izdvajaju kao zasebna slova — za nazive žanrova je razlika
     * zanemarljiva, a izdvajanje bi tražilo bitno složeniji parser.
     */
    private static function abecedniKljuc(?string $tekst): string
    {
        static $mapa = null;

        if ($mapa === null) {
            $abeceda = ['a', 'b', 'c', 'č', 'ć', 'd', 'đ', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l',
                        'm', 'n', 'o', 'p', 'q', 'r', 's', 'š', 't', 'u', 'v', 'w', 'x', 'y', 'z', 'ž'];
            $mapa = [];
            foreach ($abeceda as $i => $slovo) {
                $mapa[$slovo] = sprintf('%02d', $i + 1);
            }
        }

        $znakovi = preg_split('//u', mb_strtolower((string) $tekst, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kljuc = '';
        foreach ($znakovi as $znak) {
            // Sve što nije slovo (razmak, cifra, interpunkcija) ide ispred slova.
            $kljuc .= $mapa[$znak] ?? '00';
        }

        return $kljuc;
    }

    public function indexApi()
    {
        return Zanr::orderBy('naziv')->get();
    }

    /**
     * Knjige jednog žanra za modal na stranici knjige — namerno samo ono što
     * modal prikazuje (naslov + autor) i link ka detaljima. Odvojeno od
     * KnjigaController@index da spisak ne zavisi od filtera te rute
     * (npr. izostavljanja istaknutih knjiga bez `include_featured`).
     */
    public function knjigeApi($id)
    {
        $zanr = Zanr::findOrFail($id);

        $knjige = $zanr->knjige()
            ->orderBy('naziv_knjige')
            ->get(['knjige.id', 'knjige.naziv_knjige', 'knjige.autor'])
            ->makeHidden('pivot');

        return response()->json([
            'zanr'   => $zanr->naziv,
            'knjige' => $knjige,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['naziv' => 'required|unique:zanrovi|max:100']);
        $zanr = Zanr::create($data);

        return response()->json([
            'success' => true,
            'message' => "Žanr „{$zanr->naziv}“ je dodat.",
            'data'    => $zanr,
        ], 201);
    }

    public function destroy($id)
    {
        $zanr = Zanr::findOrFail($id);
        $naziv = $zanr->naziv;   // čita se PRE brisanja
        $zanr->delete();

        return response()->json([
            'success' => true,
            'message' => "Žanr „{$naziv}“ je obrisan.",
        ]);
    }

    public function knjige($id)
    {
        $user = auth()->user();
        $zanr = DB::table('zanrovi')->where('id', $id)->first();
        $knjige = DB::table('knjige')
            ->join('knjiga_zanr', 'knjige.id', '=', 'knjiga_zanr.knjiga_id')
            ->join('primerci_knjiga', 'knjige.id', '=', 'primerci_knjiga.knjiga_id')
            ->where('knjiga_zanr.zanr_id', $id)
            ->select(
                'primerci_knjiga.inventarski_broj',
                'primerci_knjiga.signatura',
                'primerci_knjiga.datum_unosa',
                'knjige.autor',
                'knjige.naziv_knjige',
                'knjige.mesto_izdavanja',
                'knjige.godina_izdavanja',
                'knjige.izdavac',
                'primerci_knjiga.nacin_nabavke',
                'knjige.isbn'
            )
            ->get();

        return view('admin.zanrovi-knjige', compact('zanr', 'knjige', 'user'));
    }
}