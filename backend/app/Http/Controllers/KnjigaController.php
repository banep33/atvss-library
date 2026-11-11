<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use App\Models\PrimerakKnjige;
use App\Models\Knjiga;
use App\Models\User;
use App\Helpers\SignatureHelper;
use Illuminate\Http\Request;
use App\Enums\PrimerakStatus;
use App\Services\FeaturedKnjigeService;

class KnjigaController extends Controller
{

    public function __construct(
        private FeaturedKnjigeService $featuredService,
    ) {}

    // GET /knjige - lista knjiga sa pretragom i paginacijom
    public function index(Request $request)
{
    $search          = $request->get('search', '');
    $autor           = $request->get('autor', '');
    $zanr            = $request->get('zanr', '');
    $sort            = $request->get('sort');
    $dir             = $request->get('dir', 'asc');
    $includeFeatured = $request->boolean('include_featured');

    if (!in_array($dir, ['asc', 'desc'])) {
        $dir = 'asc';
    }

    $query = Knjiga::query()
        ->with('zanrovi')
        ->withCount([
            'primerci',
            // ISPRAVLJENO — Enum umesto magic strings, ispravljen typo
            'primerci as slobodni_primerci_count'    => fn($q) => $q->where('status', PrimerakStatus::Slobodna->value),
            'primerci as zaduzeni_primerci_count'    => fn($q) => $q->where('status', PrimerakStatus::Zaduzena->value),
            'primerci as rezervisani_primerci_count' => fn($q) => $q->where('status', PrimerakStatus::Rezervisana->value),
        ]);

    // Isključi featured knjige, osim kada se traže jedinstveni rezultati pretrage/sortiranja
    $featuredIds = $this->featuredService->getFeaturedIds();
    if (!$includeFeatured && !empty($featuredIds)) {
        $query->whereNotIn('id', $featuredIds);
    }

    // Pretraga otporna na pismo (latinica/ćirilica) i dijakritike:
    // upoređujemo NORMALIZOVANE oblike (TextUtils::normalize → latinica,
    // mala slova, bez dijakritika: š→s, đ→dj, ž→z, č/ć→c) i za termin i za
    // kolonu. Katalog je mali (stotine knjiga), pa poklapanje računamo u PHP-u
    // i sužavamo upit preko whereIn — sortiranje i paginacija ostaju u SQL-u.
    if ($search !== '' || $autor !== '') {
        $needleNaziv = \App\Services\Ai\TextUtils::normalize((string) $search);
        $needleAutor = \App\Services\Ai\TextUtils::normalize((string) $autor);

        $matchingIds = Knjiga::query()
            ->select('id', 'naziv_knjige', 'autor')
            ->get()
            ->filter(function ($k) use ($needleNaziv, $needleAutor) {
                $okNaziv = $needleNaziv === ''
                    || str_contains(\App\Services\Ai\TextUtils::normalize((string) $k->naziv_knjige), $needleNaziv);
                $okAutor = $needleAutor === ''
                    || str_contains(\App\Services\Ai\TextUtils::normalize((string) $k->autor), $needleAutor);
                return $okNaziv && $okAutor;
            })
            ->pluck('id')
            ->all();

        $query->whereIn('id', $matchingIds);
    }

    if ($zanr) {
        $query->whereHas('zanrovi', fn($q) => $q->where('zanrovi.id', $zanr));
    }

    if ($sort === 'year') {
        // Knjige bez upisane godine uvek na kraju, bez obzira na smer.
        $query->orderByRaw('godina_izdavanja IS NULL')
              ->orderBy('godina_izdavanja', $dir);
    }

    // per_page: podrazumevano 12 (glavni grid). Modali (npr. „Upravljaj najnovijim
    // knjigama") traže veliki broj da bi pretražili CEO katalog — bez ovoga je
    // paginate uvek vraćao samo 12, pa pretraga u modalu nije videla ostale knjige
    // (naročito uočljivo posle uvoza iz Excel-a). Ograničeno na 5000 da se ne preoptereti.
    $perPage = (int) $request->get('per_page', 12);
    $perPage = max(1, min($perPage, 5000));

    $result = $query->paginate($perPage);

    if ($includeFeatured) {
        $najcitanijeIds = $this->featuredService->getNajcitanijeIds();
        $najnovijeIds   = $this->featuredService->getNajnovijeIds();

        $result->getCollection()->transform(function ($knjiga) use ($najcitanijeIds, $najnovijeIds) {
            $knjiga->je_najcitanija = in_array($knjiga->id, $najcitanijeIds);
            $knjiga->je_najnovija   = in_array($knjiga->id, $najnovijeIds);
            return $knjiga;
        });
    }

    return $result;
}

    // GET /knjige/{id} - detalji jedne knjige
    public function show($id)
    {
        $knjiga = Knjiga::with(['primerci', 'zanrovi'])->findOrFail($id);

        return response()->json([
            'id'                      => $knjiga->id,
            'in_broj'                 => $knjiga->in_broj,
            'signatura'               => $knjiga->signatura,
            'datum'                   => $knjiga->datum,
            'naziv_knjige'            => $knjiga->naziv_knjige,
            'autor'                   => $knjiga->autor,
            'mesto_izdavanja'         => $knjiga->mesto_izdavanja,
            'godina_izdavanja'        => $knjiga->godina_izdavanja,
            'izdavac'                 => $knjiga->izdavac,
            'nacin_nabavke'           => $knjiga->nacin_nabavke,
            'isbn'                    => $knjiga->isbn,
            'opis'                    => $knjiga->opis,
            'slika'                   => $knjiga->slika,
            'je_eknjiga'              => $knjiga->je_eknjiga,
            'eknjiga_link'            => $knjiga->eknjiga_link,
            'primerci'                => $knjiga->primerci->map(fn($p) => [
                'id'                => $p->id,
                'inventarski_broj'  => $p->inventarski_broj,
                'status'            => $p->status,
            ]),
            'zanrovi'               => $knjiga->zanrovi->map(fn($z) => [  
            'id'    => $z->id,
            'naziv' => $z->naziv,
        ]),
            
        ]);
    }

    // POST /knjige - dodaj novu knjigu
    public function store(Request $request)
    {
        $data = $request->validate([
            'in_broj'                => 'required|string|max:50',
            'signatura'              => 'required|string|max:50',
            'datum'                  => 'required|date',
            'autor'                  => 'required|string|max:255',
            'naziv_knjige'           => 'required|string|max:255',
            'mesto_izdavanja'        => 'required|string|max:100',
            'godina_izdavanja'       => 'required|integer|between:1800,2100',
            'izdavac'                => 'required|string|max:255',
            'nacin_nabavke'          => 'required|string|max:100',
            'isbn'                   => 'nullable|string|max:20',
            'opis'                   => 'nullable|string',
            // Bez SVG-a: SVG može da nosi <script> (stored XSS)
            'slika'                  => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:2048',
            'je_eknjiga'             => 'nullable|boolean',
            'eknjiga_link'           => 'nullable|url',
            'zanrovi'                => 'nullable|array',
            'broj_primeraka' => 'nullable|integer|min:1|max:50',
        ]);

        if ($request->hasFile('slika')) {
            $file = $request->file('slika');
            // Ime fajla se NIKAD ne uzima od klijenta: fajl sa imenom
            // "x.php" (a validnim slikovnim sadržajem) izvršio bi se kao
            // PHP na produkcijskom serveru. Ekstenzija se izvodi iz
            // stvarnog sadržaja fajla, ime je nasumično
            $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $file->guessExtension();
            $file->move(public_path('images'), $filename);
            $data['slika'] = 'images/' . $filename;
        }

        $data['signatura'] = SignatureHelper::normalize($data['signatura'] ?? '');

        $knjiga = Knjiga::create($data); // RAG indeks se ažurira automatski (Knjiga::booted)

        if (isset($data['zanrovi'])) {
            $knjiga->zanrovi()->attach($data['zanrovi']);
            $knjiga->touch(); // pivot ne okida model evente → touch reindeksira sa žanrovima
        }

        // DODAJ OVO
        if (!($data['je_eknjiga'] ?? false)) {
        $brojPrimeraka = (int) $request->input('broj_primeraka', 1);
        $brojPrimeraka = max(1, min(50, $brojPrimeraka));

        $maxInv = (int)(DB::table('primerci_knjiga')
            ->max(DB::raw('CAST(inventarski_broj AS INTEGER)')) ?? 0);
    
        for ($i = 0; $i < $brojPrimeraka; $i++) {
        PrimerakKnjige::create([
            'knjiga_id'        => $knjiga->id,
            'inventarski_broj' => $knjiga->in_broj . ($brojPrimeraka > 1 ? '-' . ($i + 1) : ''),
            'signatura'        => $knjiga->signatura,
            'status'           => 'slobodna',
            'nacin_nabavke'    => $knjiga->nacin_nabavke,
            'datum_unosa'      => now(),
        ]);
        }
        }

        return response()->json([
            'success' => true,
            'message' => "Knjiga „{$knjiga->naziv_knjige}“ je dodata.",
            'data'    => $knjiga,
        ], 201);
    }

    // PATCH /knjige/{id} - ažuriraj knjigu
    public function update(Request $request, $id)
    {
        $knjiga = Knjiga::findOrFail($id);

        $data = $request->validate([
            'in_broj'                => 'sometimes|string|max:50',
            'signatura'              => 'sometimes|string|max:50',
            'datum'                  => 'sometimes|date',
            'autor'                  => 'sometimes|string|max:255',
            'naziv_knjige'           => 'sometimes|string|max:255',
            'mesto_izdavanja'        => 'sometimes|string|max:100',
            'godina_izdavanja'       => 'sometimes|integer|between:1800,2100',
            'izdavac'                => 'sometimes|string|max:255',
            'nacin_nabavke'          => 'sometimes|string|max:100',
            'isbn'                   => 'nullable|string|max:20',
            'opis'                   => 'nullable|string',
            // Bez SVG-a: SVG može da nosi <script> (stored XSS)
            'slika'                  => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:2048',
            'je_eknjiga'             => 'nullable|boolean',
            'eknjiga_link'           => 'nullable|url',
            'zanrovi'                => 'nullable|array',
        ]);

        if ($request->hasFile('slika')) {
            if ($knjiga->slika && file_exists(public_path($knjiga->slika))) {
                unlink(public_path($knjiga->slika));
            }
            $file = $request->file('slika');
            // Isto kao u store(): ime generiše server, nikad klijent
            $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $file->guessExtension();
            $file->move(public_path('images'), $filename);
            $data['slika'] = 'images/' . $filename;
        }

        if (isset($data['signatura'])) {
            $data['signatura'] = SignatureHelper::normalize($data['signatura']);
        }

        $knjiga->update($data); // RAG indeks se ažurira automatski (Knjiga::booted)

        if (isset($data['zanrovi'])) {
            $knjiga->zanrovi()->sync($data['zanrovi']);
            $knjiga->touch(); // pivot ne okida model evente → touch reindeksira sa žanrovima
        }

        return response()->json([
            'success' => true,
            'message' => "Knjiga „{$knjiga->naziv_knjige}“ je sačuvana.",
            'data'    => $knjiga->load('zanrovi'),
        ]);
    }

    // DELETE /knjige/{id} - obriši knjigu
    public function destroy($id)
    {
        $knjiga = Knjiga::findOrFail($id);

        // Brisanje knjige povlači i sve njene primerke (strani ključ
        // primerci_knjiga.knjiga_id je ON DELETE CASCADE), pa sam broj
        // primeraka nije prepreka — u suprotnom nijedna zavedena knjiga ne bi
        // mogla da se obriše, jer svaka ima bar jedan primerak.
        //
        // Prepreka je SAMO primerak koji je trenutno kod nekoga: brisanjem bi
        // nestao zapis o zaduženju (zaduzivanja.primerak_id je takođe
        // kaskadno), pa se knjiga koja je izdata ili rezervisana ne dira dok
        // se ne vrati.
        $zauzeti = $knjiga->primerci()
            ->whereIn('status', [
                PrimerakStatus::Zaduzena->value,
                PrimerakStatus::Rezervisana->value,
            ])
            ->count();

        if ($zauzeti > 0) {
            return response()->json([
                'success' => false,
                'message' => $zauzeti === 1
                    ? 'Ne možete obrisati knjigu dok je jedan primerak zadužen ili rezervisan.'
                    : "Ne možete obrisati knjigu dok je {$zauzeti} primeraka zaduženo ili rezervisano.",
            ], 422);
        }

        if ($knjiga->slika && file_exists(public_path($knjiga->slika))) {
            unlink(public_path($knjiga->slika));
        }

        $naziv = $knjiga->naziv_knjige;   // čita se PRE brisanja
        $knjiga->delete(); // RAG indeks se ažurira automatski (Knjiga::booted)

        return response()->json([
            'success' => true,
            'message' => "Knjiga „{$naziv}“ je obrisana.",
        ]);
    }


    public function najcitanije(Request $request)
    {
        $filters = $request->only(['search', 'autor', 'zanr', 'sort', 'dir']);
        return response()->json($this->featuredService->getNajcitanije($filters));
    }

    public function najnovije(Request $request)
    {
        $filters = $request->only(['search', 'autor', 'zanr', 'sort', 'dir']);
        return response()->json($this->featuredService->getNajnovije($filters));
    }

    public function dodajUNajnovije($id)
    {
        $result = $this->featuredService->dodajUNajnovije((int) $id);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function ukloniIzNajnovije($id)
    {
        $result = $this->featuredService->ukloniIzNajnovije((int) $id);
        return response()->json($result);
    }

    public function inventarnaKnjiga()
    {
        // Inventarna knjiga je 1:1 sa Excel-om: JEDAN RED = JEDAN PRIMERAK (po
        // inventarskom broju). Pošto se pri uvozu knjige grupišu (više primeraka
        // iste knjige), ovde se listaju PRIMERCI, ne knjige — inače bi redova bilo
        // manje nego u fajlu. Redosled po id-u = redosled uvoza (= redosled u Excel-u).
        $primerci = \App\Models\PrimerakKnjige::with('knjiga.zanrovi')->orderBy('id')->get();
        $user = auth()->user();
        return view('admin.inventarna-knjiga', compact('primerci', 'user'));
    }
}