<?php

namespace App\Services;

use Illuminate\Support\Collection;
use App\Models\Arhiva;
use App\Models\NajnovijaKnjiga;
use App\Models\Knjiga;
use App\Enums\PrimerakStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Enums\ArhivaTip;



class FeaturedKnjigeService
{
    const MAX_NAJNOVIJE = 10;
    const MAX_NAJCITANIJE = 10;
    const CACHE_TTL = 2592000; // 30 dana u sekundama (mesečno osvežavanje najčitanijih)

 public function getNajcitanije(array $filters = []): Collection
 {
    // Kešira SAMO redosled ID-ova — ovo se menja retko
    $ids = Cache::remember('najcitanije_knjige', self::CACHE_TTL, function () {
        return Arhiva::select('knjiga_id', DB::raw('COUNT(*) as broj_zaduzenja'))
            ->whereNotNull('knjiga_id')->whereIn('knjiga_id', Knjiga::select('id'))
            ->where('tip', ArhivaTip::Zaduzena->value)
            ->groupBy('knjiga_id')
            ->orderByDesc('broj_zaduzenja')
            ->limit(self::MAX_NAJCITANIJE)
            ->pluck('broj_zaduzenja', 'knjiga_id');
    });

    // Dostupnost se uvek čita sveže iz baze
    $query = Knjiga::whereIn('id', $ids->keys())
        ->with('zanrovi')
        ->withCount([
            'primerci as slobodni_primerci_count'    => fn($q) => $q->where('status', PrimerakStatus::Slobodna->value),
            'primerci as rezervisani_primerci_count' => fn($q) => $q->where('status', PrimerakStatus::Rezervisana->value),
        ]);

    $this->applyFilters($query, $filters);

    $knjige = $query->get()
        ->map(fn($k) => array_merge($k->toArray(), [
            'broj_zaduzenja' => $ids[$k->id]
        ]));

    if (($filters['sort'] ?? null) === 'year') {
        $knjige = $knjige->sortBy(
            fn($k) => $k['godina_izdavanja'] ?? 0,
            SORT_REGULAR,
            ($filters['dir'] ?? 'asc') === 'desc'
        );
    } else {
        $knjige = $knjige->sortByDesc('broj_zaduzenja');
    }

    return $knjige->values();
 }

    public function getNajnovije(array $filters = []): Collection
    {
    $ids = Cache::remember('najnovije_knjige', self::CACHE_TTL, function () {
        return NajnovijaKnjiga::latest()
            ->limit(self::MAX_NAJNOVIJE)
            ->pluck('knjiga_id');
    });

    $query = Knjiga::whereIn('id', $ids)
        ->with('zanrovi')
        ->withCount([
            'primerci as slobodni_primerci_count'    => fn($q) => $q->where('status', PrimerakStatus::Slobodna->value),
            'primerci as rezervisani_primerci_count' => fn($q) => $q->where('status', PrimerakStatus::Rezervisana->value),
        ]);

    $this->applyFilters($query, $filters);

    $knjige = $query->get();

    if (($filters['sort'] ?? null) === 'year') {
        $knjige = $knjige->sortBy(
            fn($k) => $k->godina_izdavanja ?? 0,
            SORT_REGULAR,
            ($filters['dir'] ?? 'asc') === 'desc'
        );
    } else {
        $knjige = $knjige->sortByDesc(fn($k) => $ids->search($k->id)); // čuva originalni redosled
    }

    return $knjige->values();
    }

    private function applyFilters($query, array $filters): void
    {
        // Pretraga otporna na pismo i dijakritike — isti normalizovani oblik
        // (latinica, mala slova, bez dijakritika) za termin i za kolonu.
        $needleNaziv = \App\Services\Ai\TextUtils::normalize((string) ($filters['search'] ?? ''));
        $needleAutor = \App\Services\Ai\TextUtils::normalize((string) ($filters['autor'] ?? ''));

        if ($needleNaziv !== '' || $needleAutor !== '') {
            $matchingIds = (clone $query)
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

        if (!empty($filters['zanr'])) {
            $query->whereHas('zanrovi', fn($q) => $q->where('zanrovi.id', $filters['zanr']));
        }
    }

    public function dodajUNajnovije(int $knjigaId): array
    {
        if (NajnovijaKnjiga::count() >= self::MAX_NAJNOVIJE) {
            return [
                'success' => false,
                'message' => 'Maksimalan broj knjiga u sekciji je ' . self::MAX_NAJNOVIJE . '.'
            ];
        }

        if (NajnovijaKnjiga::where('knjiga_id', $knjigaId)->exists()) {
            return [
                'success' => false,
                'message' => 'Knjiga je već u sekciji.'
            ];
        }

        NajnovijaKnjiga::create(['knjiga_id' => $knjigaId]);
        $this->clearCache();

        return ['success' => true, 'message' => 'Knjiga dodana u sekciju.'];
    }

    public function ukloniIzNajnovije(int $knjigaId): array
    {
        NajnovijaKnjiga::where('knjiga_id', $knjigaId)->delete();
        $this->clearCache();

        return ['success' => true, 'message' => 'Knjiga uklonjena iz sekcije.'];
    }

    public function getNajcitanijeIds(): array
    {
        $ids = Cache::remember('najcitanije_knjige', self::CACHE_TTL, function () {
            return Arhiva::select('knjiga_id', DB::raw('COUNT(*) as broj_zaduzenja'))
                ->whereNotNull('knjiga_id')->whereIn('knjiga_id', Knjiga::select('id'))
                ->where('tip', ArhivaTip::Zaduzena->value)
                ->groupBy('knjiga_id')
                ->orderByDesc('broj_zaduzenja')
                ->limit(self::MAX_NAJCITANIJE)
                ->pluck('broj_zaduzenja', 'knjiga_id');
        });

        return $ids->keys()->toArray();
    }

    public function getNajnovijeIds(): array
    {
        $ids = Cache::remember('najnovije_knjige', self::CACHE_TTL, function () {
            return NajnovijaKnjiga::latest()
                ->limit(self::MAX_NAJNOVIJE)
                ->pluck('knjiga_id');
        });

        return $ids->toArray();
    }

    public function getFeaturedIds(): array
    {
    return Cache::remember('featured_ids', self::CACHE_TTL, function () {
        $najnovijeIds = NajnovijaKnjiga::pluck('knjiga_id')->toArray();

        $najcitanijeIds = Arhiva::select('knjiga_id', DB::raw('COUNT(*) as broj_zaduzenja'))
            ->whereNotNull('knjiga_id')->whereIn('knjiga_id', Knjiga::select('id'))
            ->where('tip', ArhivaTip::Zaduzena->value)
            ->groupBy('knjiga_id')
            ->orderByDesc('broj_zaduzenja')
            ->limit(self::MAX_NAJCITANIJE)
            ->pluck('knjiga_id')
            ->toArray();

        return array_unique(array_merge($najnovijeIds, $najcitanijeIds));
    });
    }

    public function clearCache(): void
    {
        Cache::forget('najcitanije_knjige');
        Cache::forget('featured_ids');
        Cache::forget('najnovije_knjige');
    }
}