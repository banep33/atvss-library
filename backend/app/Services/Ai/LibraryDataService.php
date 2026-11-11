<?php

namespace App\Services\Ai;

use App\Enums\ArhivaTip;
use App\Enums\PrimerakStatus;
use App\Enums\RezervacijaStatus;
use App\Models\Arhiva;
use App\Models\Knjiga;
use App\Models\PrimerakKnjige;
use App\Models\Rezervacija;
use App\Models\Zaduzivanje;
use App\Models\Zanr;
use App\Services\FeaturedKnjigeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * LibraryDataService — SLOJ 4a pipeline-a: JEDINO mesto na kome AI sloj
 * priča sa bazom (COUNT, GROUP BY, ORDER BY, filter po datumu, hidratacija).
 *
 * Ovde su KONSOLIDOVANI upiti koji su ranije bili razbacani po AiChatService
 * (agregatiKataloga, listaNajnovijih, listaNajcitanijih, clanKontekst,
 * srodniZanr, knjigeIzZanra), StrukturniUpiti (arhiva, žanrovi) i RagService
 * (hidriraj) — upiti su PRESELJENI, ne menjani.
 *
 * Pravilo čistoće: handleri (StrukturniUpiti, semantički tok) i Router
 * (AiChatService) ne izvršavaju SQL/ORM mimo ove klase; agregacije i
 * brojanja se NIKADA ne rade nad retrievanim tekstom, već ovde, nad bazom.
 *
 * Svi upiti su SVEŽI (bez keširanja) — bib-pravila 6.2: dostupnost i
 * statistika odražavaju trenutno stanje baze.
 */
class LibraryDataService
{
    public function __construct(
        private readonly FeaturedKnjigeService $featured,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // KATALOG
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sve knjige sa žanrovima za leksički rečnik (KatalogPretraga) —
     * jedan upit po HTTP zahtevu (keš drži pozivalac).
     *
     * @return Collection<int, Knjiga>
     */
    public function sveKnjigeSaZanrovima(): Collection
    {
        return Knjiga::with('zanrovi:id,naziv')->get(['id', 'naziv_knjige', 'autor']);
    }

    /**
     * Agregatno stanje kataloga (bib-pravila.md tačka 6) — sveži COUNT-ovi
     * pri svakom pitanju, bez keširanja. Uključuje i kompletan spisak žanrova
     * sa brojem knjiga — model tako zna TAČAN broj i imena žanrova i ne može
     * da ih izmišlja iz opšteg znanja.
     *
     * @return array{knjiga: int, primeraka: int, slobodnih: int, nedostupnih: int,
     *               zanrovi: array<int, array{naziv: string, broj_knjiga: int}>}
     */
    public function agregatiKataloga(): array
    {
        $primeraka = PrimerakKnjige::count();
        $slobodnih = PrimerakKnjige::where('status', PrimerakStatus::Slobodna->value)->count();

        $zanrovi = Zanr::withCount('knjige')
            ->orderBy('naziv')
            ->get(['id', 'naziv'])
            ->map(fn (Zanr $z) => ['naziv' => $z->naziv, 'broj_knjiga' => (int) $z->knjige_count])
            ->all();

        return [
            'knjiga'      => Knjiga::count(),
            'primeraka'   => $primeraka,
            'slobodnih'   => $slobodnih,
            'nedostupnih' => $primeraka - $slobodnih,
            'zanrovi'     => $zanrovi,
        ];
    }

    /**
     * Hidrira parove {id => score} u knjige sa žanrovima i AKTUELNOM
     * dostupnošću (broj slobodnih primeraka, bez keširanja) — koriste je
     * i leksički i semantički pogoci, pa je dostupnost uvek sveža.
     *
     * @param  array<int, float>  $scoresById  redosled ključeva = redosled relevantnosti
     * @return Collection<int, array{knjiga: Knjiga, score: float}>
     */
    public function hidriraj(array $scoresById): Collection
    {
        if (empty($scoresById)) {
            return collect();
        }

        $ids = array_keys($scoresById);

        $knjige = Knjiga::with('zanrovi')
            ->withCount([
                'primerci as slobodni_primerci_count' => fn ($q) =>
                    $q->where('status', PrimerakStatus::Slobodna->value),
                'primerci as ukupno_primeraka_count',
                // Za pitanja „koliko puta je zaduživana / koliko se traži"
                'arhivaZaduzenja as broj_zaduzenja_count',
                'rezervacije as broj_rezervacija_count',
            ])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // Redosledom relevantnosti iz pretrage (ne redosledom iz baze).
        return collect($ids)
            ->map(fn (int $id) => [
                'knjiga' => $knjige->get($id),
                'score'  => $scoresById[$id],
            ])
            ->filter(fn (array $r) => $r['knjiga'] !== null)
            ->values();
    }

    /**
     * Broj knjiga sa unetim opisom — dok je 0, ČISTO tematsko pitanje dobija
     * pošten fiksni odgovor (tema se čita iz opisa; bez ijednog opisa bi
     * semantika servirala žanr/sličnost naslova kao „temu" — Nalaz 1).
     */
    public function brojKnjigaSaOpisom(): int
    {
        return Knjiga::whereNotNull('opis')->where('opis', '!=', '')->count();
    }

    public function nadjiKnjigu(int $id): ?Knjiga
    {
        return Knjiga::find($id);
    }

    /**
     * SVEŽE stanje jedne knjige za fiksne odgovore o dostupnosti i listi
     * čekanja: broj slobodnih/ukupnih primeraka + broj AKTIVNIH rezervacija
     * (na čekanju ili odobrene) — bez keširanja, u trenutku pitanja.
     *
     * @return array{knjiga: Knjiga, ukupno: int, slobodno: int, rezervacije: int}|null
     */
    public function stanjeKnjige(int $knjigaId): ?array
    {
        $aktivne = [RezervacijaStatus::NaCekanju->value, RezervacijaStatus::Odobrena->value];

        $knjiga = Knjiga::withCount([
            'primerci as ukupno_primeraka_count',
            'primerci as slobodni_primerci_count' => fn ($q) =>
                $q->where('status', PrimerakStatus::Slobodna->value),
            'rezervacije as aktivne_rezervacije_count' => fn ($q) => $q->whereIn('status', $aktivne),
        ])->find($knjigaId);

        if ($knjiga === null) {
            return null;
        }

        return [
            'knjiga'      => $knjiga,
            'ukupno'      => (int) $knjiga->ukupno_primeraka_count,
            'slobodno'    => (int) $knjiga->slobodni_primerci_count,
            'rezervacije' => (int) $knjiga->aktivne_rezervacije_count,
        ];
    }

    /**
     * Od prosleđenih ID-jeva vraća samo one knjige koje IMAJU unet opis —
     * tematska pitanja se odgovaraju isključivo iz opisa (kandidat bez
     * opisa, npr. knjiga NASLOVA „Priče" za temu priče, nije tematski dokaz).
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function idsSaOpisom(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return Knjiga::whereIn('id', $ids)
            ->whereNotNull('opis')
            ->where('opis', '!=', '')
            ->pluck('id')
            ->all();
    }

    /**
     * Knjige po ID-jevima, azbučnim redom — za nabrajanje u fiksnim
     * odgovorima (broj knjiga sa rečju u naslovu).
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Knjiga>
     */
    public function knjigeAzbucno(array $ids): Collection
    {
        return Knjiga::whereIn('id', $ids)
            ->orderBy('naziv_knjige')
            ->get(['id', 'naziv_knjige', 'autor']);
    }

    /**
     * ID-jevi knjiga čija GODINA IZDANJA upada u [od, do] (uključivo; null =
     * bez granice) + broj knjiga BEZ upisane godine (za pošten odgovor —
     * bez te napomene bi mali broj delovao kao ceo fond).
     *
     * Godina je zasebna kolona `godina_izdavanja` (razdvojena od mesta
     * izdavanja), pa je filtriranje običan SQL uslov.
     *
     * @return array{ids: array<int, int>, bez_godine: int}
     */
    public function idsUOpseguGodina(?int $od, ?int $do): array
    {
        $ids = Knjiga::whereNotNull('godina_izdavanja')
            ->when($od !== null, fn ($q) => $q->where('godina_izdavanja', '>=', $od))
            ->when($do !== null, fn ($q) => $q->where('godina_izdavanja', '<=', $do))
            ->pluck('id')
            ->all();

        return [
            'ids'        => $ids,
            'bez_godine' => Knjiga::whereNull('godina_izdavanja')->count(),
        ];
    }

    /**
     * GODINA sa najviše knjiga u katalogu (COUNT po godina_izdavanja) +
     * njen broj i ukupan broj knjiga bez upisane godine — za „u kojoj godini
     * imate najviše izdatih knjiga" (kandidati_ucenje_2 #34 p10).
     *
     * @return array{godina: int, broj: int, bez_godine: int}|null
     */
    public function godinaSaNajviseKnjiga(): ?array
    {
        $red = Knjiga::whereNotNull('godina_izdavanja')
            ->groupBy('godina_izdavanja')
            ->selectRaw('godina_izdavanja, COUNT(*) as broj')
            ->orderByDesc('broj')
            ->orderByDesc('godina_izdavanja')
            ->first();

        if ($red === null) {
            return null;
        }

        return [
            'godina'     => (int) $red->godina_izdavanja,
            'broj'       => (int) $red->broj,
            'bez_godine' => Knjiga::whereNull('godina_izdavanja')->count(),
        ];
    }

    /**
     * NAJSTARIJA (najmanja godina) ili NAJNOVIJA (najveća godina) knjiga u
     * katalogu — za „koja je najstarija/najnovija knjiga" (kandidati_ucenje_2
     * #34 p8, #34 p9). Knjige bez upisane godine se ne računaju.
     */
    public function ekstremPoGodini(bool $najstarija): ?Knjiga
    {
        return Knjiga::whereNotNull('godina_izdavanja')
            ->orderBy('godina_izdavanja', $najstarija ? 'asc' : 'desc')
            ->orderBy('naziv_knjige')
            ->first(['id', 'naziv_knjige', 'autor', 'godina_izdavanja']);
    }

    /**
     * SVE knjige sa upisanom godinom i autorom, poređane po godini (za ekstrem
     * filtriran po poreklu pisca — „najnovija knjiga domaćeg/stranog autora").
     *
     * @return Collection<int, Knjiga>
     */
    public function knjigeSaGodinom(bool $najstarija): Collection
    {
        return Knjiga::whereNotNull('godina_izdavanja')
            ->whereNotNull('autor')->where('autor', '!=', '')
            ->orderBy('godina_izdavanja', $najstarija ? 'asc' : 'desc')
            ->orderBy('naziv_knjige')
            ->get(['id', 'naziv_knjige', 'autor', 'godina_izdavanja']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTORI — statistika broja naslova (model se ne poziva)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Autori sa brojem RAZLIČITIH naslova u katalogu, opadajuće po broju (pa
     * azbučno) — osnov za „autor sa najviše knjiga", „autori sa više od jedne
     * knjige" i „autori sa samo jednom knjigom" (kandidati_ucenje_2 #34 p2/p3,
     * #35 p7/p10). Knjige bez upisanog autora se ne računaju.
     *
     * @return Collection<int, object{autor: string, broj: int}>
     */
    public function autoriPoBrojuNaslova(): Collection
    {
        return Knjiga::whereNotNull('autor')
            ->where('autor', '!=', '')
            ->groupBy('autor')
            ->selectRaw('autor, COUNT(*) as broj')
            ->orderByDesc('broj')
            ->orderBy('autor')
            ->get();
    }

    /**
     * Različiti AUTORI čije PREZIME počinje datim slovom (set17 p20) — vraća
     * imena autora azbučno. Prezime = poslednja prava reč imena (inicijali i
     * rep „i dr." se preskaču).
     *
     * @return Collection<int, string>
     */
    public function autoriPrezimeNaSlovo(string $slovo): Collection
    {
        $slovo = TextUtils::normalize($slovo);

        if ($slovo === '') {
            return collect();
        }

        return $this->autoriPoBrojuNaslova()
            ->map(fn ($r) => (string) $r->autor)
            ->filter(function (string $autor) use ($slovo) {
                $prezime = $this->prezimeAutora($autor);

                return $prezime !== '' && str_starts_with(TextUtils::normalize($prezime), $slovo);
            })
            ->unique()
            ->sort()
            ->values();
    }

    /** Prezime iz punog imena autora — poslednja prava reč (bez inicijala / „i dr."). */
    private function prezimeAutora(string $autor): string
    {
        $autor = trim((string) preg_replace('/\s+/u', ' ', $autor));
        $autor = (string) preg_replace('/\s+i\s+dr\.?$|\s+i\s+drug\w*$/iu', '', $autor);

        $reci = array_values(array_filter(
            preg_split('/\s+/u', $autor) ?: [],
            fn (string $r) => mb_strlen(rtrim($r, '.')) > 1
        ));

        return $reci === [] ? '' : (string) end($reci);
    }

    /**
     * Prvi pronađen PAR knjiga ISTOG autora objavljenih ISTE godine — za
     * analitičko „postoje li dve knjige istog autora iz iste godine"
     * (kandidati_ucenje_3 #36 p15). Null kada takvog para nema.
     *
     * @return array{autor: string, godina: int, knjige: Collection<int, Knjiga>}|null
     */
    public function dveKnjigeIstiAutorIstaGodina(): ?array
    {
        $knjige = Knjiga::whereNotNull('autor')->where('autor', '!=', '')
            ->whereNotNull('godina_izdavanja')
            ->get(['id', 'naziv_knjige', 'autor', 'godina_izdavanja']);

        $grupe = [];
        foreach ($knjige as $k) {
            $grupe[$k->autor . '|' . $k->godina_izdavanja][] = $k;
        }

        foreach ($grupe as $kljuc => $lista) {
            if (count($lista) >= 2) {
                [$autor, $godina] = explode('|', $kljuc, 2);
                return [
                    'autor'  => $autor,
                    'godina' => (int) $godina,
                    'knjige' => collect($lista)->take(2)->values(),
                ];
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MESTO IZDAVANJA — filtriranje kataloga po gradu (model se ne poziva)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Knjige sa upisanim mestom izdavanja — za fuzzy pretragu imenovanog
     * grada (nadjiMesto). Fond je mali, poređenje radi PHP.
     *
     * @return Collection<int, Knjiga>  samo id i mesto_izdavanja
     */
    public function knjigeSaMestom(): Collection
    {
        return Knjiga::whereNotNull('mesto_izdavanja')
            ->where('mesto_izdavanja', '!=', '')
            ->get(['id', 'mesto_izdavanja']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IZDAVAČI
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Knjige sa upisanim izdavačem — za fuzzy pretragu imenovanog izdavača
     * (StrukturniUpiti::nadjiIzdavaca). Fond je mali, poređenje radi PHP.
     *
     * @return Collection<int, Knjiga>  samo id i izdavac
     */
    public function knjigeSaIzdavacem(): Collection
    {
        return Knjiga::whereNotNull('izdavac')->where('izdavac', '!=', '')->get(['id', 'izdavac']);
    }

    /**
     * Izdavači sa brojem knjiga u katalogu, opadajuće po broju (pa azbučno)
     * — za statistiku „od kog izdavača su najviše knjige" i spisak izdavača.
     *
     * @return Collection<int, Knjiga>  redovi sa atributima izdavac i broj
     */
    public function izdavaciSaBrojemKnjiga(): Collection
    {
        return Knjiga::whereNotNull('izdavac')
            ->where('izdavac', '!=', '')
            ->groupBy('izdavac')
            ->selectRaw('izdavac, COUNT(*) as broj')
            ->orderByDesc('broj')
            ->orderBy('izdavac')
            ->get();
    }

    /** Broj knjiga BEZ upisanog izdavača — za poštenu napomenu uz spisak. */
    public function brojKnjigaBezIzdavaca(): int
    {
        return Knjiga::where(fn ($q) => $q->whereNull('izdavac')->orWhere('izdavac', ''))->count();
    }

    /**
     * Knjige sa TAČNO datim izdavačem (osim izuzete), azbučno — za „koje
     * knjige imaju istog izdavača kao X" (kandidati_ucenje_2 #34 p18/p21/p22).
     * Poređenje po tačnom upisu (izdavač knjige-reference je već kanonski).
     *
     * @return Collection<int, Knjiga>
     */
    public function knjigeIstogIzdavaca(string $izdavac, int $izuzmiId): Collection
    {
        return Knjiga::where('izdavac', $izdavac)
            ->where('id', '!=', $izuzmiId)
            ->orderBy('naziv_knjige')
            ->get(['id', 'naziv_knjige', 'autor']);
    }

    /**
     * Knjige sa TAČNO datom godinom izdanja (osim izuzete), azbučno — za
     * „koje knjige imaju istu godinu izdanja kao X" (kandidati_ucenje_2 #34
     * p23, #35 p24).
     *
     * @return Collection<int, Knjiga>
     */
    public function knjigeIsteGodine(int $godina, int $izuzmiId): Collection
    {
        return Knjiga::where('godina_izdavanja', $godina)
            ->where('id', '!=', $izuzmiId)
            ->orderBy('naziv_knjige')
            ->get(['id', 'naziv_knjige', 'autor']);
    }

    /**
     * NAJPOPULARNIJI izdavač po ukupnom broju zaduživanja (SUM nad arhivom,
     * grupisano po polju izdavac knjiga koje još postoje u katalogu) —
     * raspodela se računa u PHP-u, kao i za žanrove (fond je mali).
     *
     * @return array{izdavac: string, zaduzenja: int}|null  null bez zaduženja
     */
    public function najpopularnijiIzdavac(): ?array
    {
        $poKnjizi = Arhiva::where('tip', ArhivaTip::Zaduzena->value)
            ->whereNotNull('knjiga_id')
            ->groupBy('knjiga_id')
            ->selectRaw('knjiga_id, COUNT(*) as ukupno')
            ->pluck('ukupno', 'knjiga_id');

        if ($poKnjizi->isEmpty()) {
            return null;
        }

        $knjige = Knjiga::whereIn('id', $poKnjizi->keys()->all())
            ->whereNotNull('izdavac')
            ->where('izdavac', '!=', '')
            ->get(['id', 'izdavac']);

        $poIzdavacu = [];
        foreach ($knjige as $k) {
            $poIzdavacu[$k->izdavac] = ($poIzdavacu[$k->izdavac] ?? 0) + (int) $poKnjizi[$k->id];
        }

        if (empty($poIzdavacu)) {
            return null;
        }

        arsort($poIzdavacu);

        $prvi = array_key_first($poIzdavacu);

        return ['izdavac' => $prvi, 'zaduzenja' => $poIzdavacu[$prvi]];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ŽANROVI
    // ─────────────────────────────────────────────────────────────────────────

    /** @return Collection<int, Zanr> */
    public function sviZanrovi(): Collection
    {
        return Zanr::all();
    }

    /**
     * Nazivi žanrova koji imaju bar jednu knjigu, azbučno — za pošten fiksni
     * odgovor o žanru koga u fondu nema („da li vas interesuje neki drugi
     * žanr?" ima smisla samo uz spisak onoga što stvarno nudimo).
     *
     * @return Collection<int, string>
     */
    public function zanroviSaKnjigama(): Collection
    {
        return Zanr::has('knjige')->orderBy('naziv')->pluck('naziv');
    }

    /**
     * SVE knjige datog žanra (polje žanr, ne tema!) azbučnim redom — za
     * kompletnu listu žanra.
     *
     * @return Collection<int, Knjiga>
     */
    public function knjigeZanra(Zanr $zanr): Collection
    {
        return $zanr->knjige()
            ->orderBy('naziv_knjige')
            ->get(['knjige.id', 'naziv_knjige', 'autor']);
    }

    /**
     * Autori sa brojem RAZLIČITIH naslova U DATOM ŽANRU, opadajuće po broju (pa
     * azbučno) — za „koji autor ima najviše knjiga iz žanra Z" (kandidati_ucenje_5,
     * korpa A). Knjige bez upisanog autora se ne računaju.
     *
     * @return Collection<int, object{autor: string, broj: int}>
     */
    public function autoriPoBrojuNaslovaUZanru(Zanr $zanr): Collection
    {
        return Knjiga::query()
            ->join('knjiga_zanr', 'knjiga_zanr.knjiga_id', '=', 'knjige.id')
            ->where('knjiga_zanr.zanr_id', $zanr->id)
            ->whereNotNull('autor')
            ->where('autor', '!=', '')
            ->groupBy('autor')
            ->selectRaw('autor, COUNT(*) as broj')
            ->orderByDesc('broj')
            ->orderBy('autor')
            ->get();
    }

    /**
     * Autor čije knjige pokrivaju NAJVIŠE RAZLIČITIH ŽANROVA (bar dva) — za
     * analitičko „postoji li autor koji piše iz više žanrova" (kandidati_ucenje_5,
     * korpa A). Raspodela se računa u PHP-u (fond je mali). Null kada nijedan
     * autor nema knjige iz bar dva žanra.
     *
     * @return array{autor: string, broj: int, zanrovi: Collection<int, string>}|null
     */
    public function autorSaNajviseZanrova(): ?array
    {
        $redovi = Knjiga::query()
            ->join('knjiga_zanr', 'knjiga_zanr.knjiga_id', '=', 'knjige.id')
            ->join('zanrovi', 'zanrovi.id', '=', 'knjiga_zanr.zanr_id')
            ->whereNotNull('autor')
            ->where('autor', '!=', '')
            ->get(['knjige.autor as autor', 'zanrovi.naziv as zanr']);

        $poAutoru = [];
        foreach ($redovi as $r) {
            $poAutoru[$r->autor][$r->zanr] = true;
        }

        $kandidati = [];
        foreach ($poAutoru as $autor => $zanrSet) {
            if (count($zanrSet) >= 2) {
                $kandidati[] = ['autor' => $autor, 'broj' => count($zanrSet), 'zanrovi' => array_keys($zanrSet)];
            }
        }

        if (empty($kandidati)) {
            return null;
        }

        usort($kandidati, fn ($a, $b) => $b['broj'] <=> $a['broj'] ?: strcmp($a['autor'], $b['autor']));

        $prvi = $kandidati[0];

        return [
            'autor'   => $prvi['autor'],
            'broj'    => $prvi['broj'],
            'zanrovi' => collect($prvi['zanrovi'])->sort()->values(),
        ];
    }

    /**
     * Prvih $max knjiga datog žanra, hidrirano sa svežom dostupnošću —
     * za preporuke srodnog žanra.
     */
    public function knjigeIzZanra(Zanr $zanr, int $max): Collection
    {
        $ids = $zanr->knjige()->orderBy('knjige.id')->limit($max)->pluck('knjige.id')->all();

        return $this->hidriraj(array_fill_keys($ids, 0.0));
    }

    /**
     * NAJČITANIJE knjige DATOG ŽANRA (bar jedno zaduženje u arhivi),
     * opadajuće po broju zaduženja — za determinističku žanrovsku preporuku
     * („preporuči mi neki roman"). Fond je mali, filter/sort radi PHP.
     *
     * @return Collection<int, Knjiga>
     */
    /**
     * SVE knjige sa BAR JEDNIM zaduženjem, opadajuće po broju zaduženja (pa
     * azbučno) — za „najčitanija knjiga domaćeg/stranog autora" (Potez 2 p13),
     * gde se filtriranje po poreklu pisca radi u AiChatService.
     *
     * @return Collection<int, Knjiga>  sa atributom broj_zaduzenja_count
     */
    public function knjigeSaZaduzenjima(): Collection
    {
        return Knjiga::withCount('arhivaZaduzenja as broj_zaduzenja_count')
            ->get(['id', 'naziv_knjige', 'autor'])
            ->filter(fn (Knjiga $k) => (int) $k->broj_zaduzenja_count > 0)
            ->sortBy([['broj_zaduzenja_count', 'desc'], ['naziv_knjige', 'asc']])
            ->values();
    }

    /**
     * SVE knjige datog žanra, hidrirane sa svežom dostupnošću (slobodni
     * primerci) — za „ima li slobodnih [žanr] za pozajmljivanje" (Potez 2).
     *
     * @return Collection<int, array{knjiga: Knjiga, score: float}>
     */
    public function knjigeZanraDostupnost(Zanr $zanr): Collection
    {
        $ids = $zanr->knjige()->pluck('knjige.id')->all();

        return empty($ids) ? collect() : $this->hidriraj(array_fill_keys($ids, 0.0));
    }

    public function najcitanijeKnjigeZanra(Zanr $zanr, int $limit): Collection
    {
        return $zanr->knjige()
            ->withCount('arhivaZaduzenja as broj_zaduzenja_count')
            ->get(['knjige.id', 'naziv_knjige', 'autor'])
            ->filter(fn (Knjiga $k) => (int) $k->broj_zaduzenja_count > 0)
            ->sortBy([['broj_zaduzenja_count', 'desc'], ['naziv_knjige', 'asc']])
            ->take($limit)
            ->values();
    }

    /**
     * Nedavno dodate knjige DATOG ŽANRA — iz KURIRANE sekcije „Najnovije"
     * sa sajta (NajnovijaKnjiga), sužene na žanr: ista lista koju korisnik
     * vidi na početnoj strani (kandidati_ucenje #28 p9, #33 p11: preporuka
     * je nudila knjige po najvećem ID-u koje u sekciji uopšte nisu).
     * Žanr bez ijedne knjige u sekciji → prazna lista (preporuku tada čini
     * samo statistika čitanosti, odnosno rezervni tok pozivaoca).
     *
     * @return Collection<int, Knjiga>
     */
    public function najnovijeKnjigeZanra(Zanr $zanr, int $limit): Collection
    {
        $sekcijaIds = $this->featured->getNajnovijeIds();

        if (empty($sekcijaIds)) {
            return collect();
        }

        $redosled = array_flip($sekcijaIds);

        return $zanr->knjige()
            ->whereIn('knjige.id', $sekcijaIds)
            ->get(['knjige.id', 'naziv_knjige', 'autor'])
            ->sortBy(fn (Knjiga $k) => $redosled[$k->id] ?? PHP_INT_MAX)
            ->take($limit)
            ->values();
    }

    /**
     * Broj ZADUŽENJA po knjizi za date ID-jeve (arhiva, tip Zaduzena) —
     * [id => broj], samo knjige sa bar jednim zaduženjem. Za rangiranje
     * „koja od tih je najbolja" (kandidati_ucenje #33 p24b).
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function zaduzenjaPoKnjigama(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return Arhiva::whereIn('knjiga_id', $ids)
            ->where('tip', ArhivaTip::Zaduzena->value)
            ->groupBy('knjiga_id')
            ->selectRaw('knjiga_id, COUNT(*) as broj')
            ->pluck('broj', 'knjiga_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Najsrodniji POSTOJEĆI žanr za upit — preko embedding sličnosti, bez
     * ikakve ručne mape žanrova: žanrovi semantički najbližih knjiga
     * „glasaju" otežano score-om knjige, pobeđuje žanr sa najviše glasova.
     *
     * @param  array<int, array{id: int, score: float}>  $sirovi
     */
    public function srodniZanr(array $sirovi): ?Zanr
    {
        if (empty($sirovi)) {
            return null;
        }

        $scores = collect($sirovi)->pluck('score', 'id')->all();

        $knjige = Knjiga::with('zanrovi')
            ->whereIn('id', array_keys($scores))
            ->get();

        $glasovi = [];
        foreach ($knjige as $knjiga) {
            foreach ($knjiga->zanrovi as $zanr) {
                $glasovi[$zanr->id] = ($glasovi[$zanr->id] ?? 0.0) + (float) $scores[$knjiga->id];
            }
        }

        if (empty($glasovi)) {
            return null;
        }

        arsort($glasovi);

        return Zanr::find(array_key_first($glasovi));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LISTE ZA PROMPT — najnovije / najčitanije
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Najnovije knjige u obliku za prompt: prvo kurirana sekcija sa sajta;
     * ako je posle uvoza/brisanja prazna, rezervno najskorije dodate knjige
     * u katalog (sveže, bez keša).
     */
    public function listaNajnovijih(): Collection
    {
        $lista = $this->featured->getNajnovije()
            ->take(5)
            ->map(fn (Knjiga $k) => [
                'id'       => $k->id,
                'naziv'    => $k->naziv_knjige,
                'autor'    => $k->autor,
                'slobodno' => (int) ($k->slobodni_primerci_count ?? 0),
            ])
            ->values();

        if ($lista->isNotEmpty()) {
            return $lista;
        }

        $ids = Knjiga::orderByDesc('id')->limit(5)->pluck('id')->all();

        return $this->uPromptObliku($this->hidriraj(array_fill_keys($ids, 0.0)));
    }

    /**
     * Najčitanije/najtraženije knjige u obliku za prompt — UVEK svež upit
     * (bez 30-dnevnog keša FeaturedKnjigeService — bib-pravila 6.2), i samo
     * knjige koje još postoje (arhiva ume da pokazuje na obrisane).
     * Ako istorije zaduženja nema, rezervni pokazatelj tražnje su rezervacije;
     * ako ni njih nema, vraća praznu listu — prompt tada kaže da statistike
     * još nema (model ne sme da izmišlja „najtraženiju" knjigu).
     */
    public function listaNajcitanijih(): Collection
    {
        $ids = Arhiva::whereNotNull('knjiga_id')
            ->where('tip', ArhivaTip::Zaduzena->value)
            ->whereIn('knjiga_id', Knjiga::select('id'))
            ->groupBy('knjiga_id')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(5)
            ->pluck('knjiga_id')
            ->all();

        if (empty($ids)) {
            $ids = Rezervacija::whereNotNull('knjiga_id')
                ->whereIn('knjiga_id', Knjiga::select('id'))
                ->groupBy('knjiga_id')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(5)
                ->pluck('knjiga_id')
                ->all();
        }

        return $this->uPromptObliku($this->hidriraj(array_fill_keys($ids, 0.0)));
    }

    /**
     * Hidrirane pogotke svodi na oblik koji sekcije prompta očekuju.
     *
     * @param  Collection<int, array{knjiga: Knjiga, score: float}>  $hits
     */
    private function uPromptObliku(Collection $hits): Collection
    {
        return $hits->map(fn (array $h) => [
            'id'             => $h['knjiga']->id,
            'naziv'          => $h['knjiga']->naziv_knjige,
            // Sažmi razmake u autoru (dva koautora ume da budu u dva reda) da
            // se prelom ne pojavi usred rečenice odgovora.
            'autor'          => trim((string) preg_replace('/\s+/u', ' ', (string) ($h['knjiga']->autor ?? ''))),
            'slobodno'       => (int) ($h['knjiga']->slobodni_primerci_count ?? 0),
            'broj_zaduzenja' => (int) ($h['knjiga']->broj_zaduzenja_count ?? 0),
        ])->values();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ARHIVA — istorija zaduženja
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sva zaduženja iz arhive od datog trenutka do danas, najskorija prva.
     *
     * @return Collection<int, Arhiva>
     */
    public function zaduzenjaOd(Carbon $od): Collection
    {
        return Arhiva::where('tip', ArhivaTip::Zaduzena->value)
            ->where('datum_akcije', '>=', $od)
            ->orderByDesc('datum_akcije')
            ->get(['knjiga_id', 'naziv_knjige', 'autor', 'datum_akcije']);
    }

    /**
     * ID-jevi knjiga (od prosleđenih) koje JOŠ postoje u katalogu — arhiva
     * ume da pokazuje na obrisane knjige, koje ostaju običan tekst bez linka.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public function postojeceKnjigeIds(array $ids): array
    {
        return Knjiga::whereIn('id', $ids)->pluck('id')->all();
    }

    /**
     * Ukupan broj zaduženja i granice perioda iz arhive — za statistiku
     * učestalosti. Null kada zaduženja još nema.
     *
     * @return array{ukupno: int, prvo: Carbon, poslednje: Carbon}|null
     */
    public function statistikaZaduzenja(): ?array
    {
        $zaduzenja = Arhiva::where('tip', ArhivaTip::Zaduzena->value);

        $ukupno = (clone $zaduzenja)->count();

        if ($ukupno === 0) {
            return null;
        }

        return [
            'ukupno'    => $ukupno,
            'prvo'      => Carbon::parse((clone $zaduzenja)->min('datum_akcije')),
            'poslednje' => Carbon::parse((clone $zaduzenja)->max('datum_akcije')),
        ];
    }

    /** Poslednje zaduženje date knjige iz arhive (nepoverljiv podatak). */
    public function poslednjeZaduzenje(int $knjigaId): ?Arhiva
    {
        return Arhiva::where('tip', ArhivaTip::Zaduzena->value)
            ->where('knjiga_id', $knjigaId)
            ->orderByDesc('datum_akcije')
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AGREGACIONE STATISTIKE — COUNT / SUM / AVG / MIN / MAX nad bazom
    // (analitička pitanja dobijaju FIKSNE odgovore iz ovih upita, ne od modela)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Knjige koje NIKADA nisu zadužene — COUNT zaduženja u arhivi = 0
     * (arhiva beleži svako zaduženje u trenutku nastanka, pa „nema zapisa"
     * znači „nikada zaduživana"). Azbučnim redom, za nabrajanje.
     *
     * @return Collection<int, Knjiga>
     */
    public function knjigeNikadZaduzene(): Collection
    {
        return Knjiga::whereDoesntHave('arhivaZaduzenja')
            ->orderBy('naziv_knjige')
            ->get(['id', 'naziv_knjige', 'autor']);
    }

    /**
     * Agregat zaduženja PO AUTORU (korpa C): za svakog autora broj knjiga,
     * ukupno zaduženja (SUM) i broj knjiga bez ijednog zaduženja. Osnov za
     * prosek/„sve"/„nijedna"/najviše-nezaduženih. Knjige bez autora se ne broje.
     *
     * @return Collection<int, object{ime: string, knjiga: int, zaduzenja: int, bez: int}>
     */
    public function zaduzenjaPoAutoru(): Collection
    {
        $knjige = Knjiga::whereNotNull('autor')->where('autor', '!=', '')
            ->withCount('arhivaZaduzenja')
            ->get(['id', 'autor']);

        return $this->grupisaniZaduzenja($knjige, fn (Knjiga $k) => [(string) $k->autor]);
    }

    /**
     * Agregat zaduženja PO ŽANRU (korpa D) — knjiga može biti u više žanrova,
     * pa se broji u svaki. Isti oblik kao zaduzenjaPoAutoru.
     *
     * @return Collection<int, object{ime: string, knjiga: int, zaduzenja: int, bez: int}>
     */
    public function zaduzenjaPoZanru(): Collection
    {
        $knjige = Knjiga::with('zanrovi:id,naziv')
            ->withCount('arhivaZaduzenja')
            ->get(['id']);

        return $this->grupisaniZaduzenja($knjige, fn (Knjiga $k) => $k->zanrovi->pluck('naziv')->all());
    }

    /**
     * Zajednički motor grupne agregacije zaduženja: za svaku grupu (ključeve
     * daje $kljucevi) sabira broj knjiga, ukupna zaduženja i broj knjiga bez
     * zaduženja. Broj zaduženja po knjizi je već učitan withCount-om.
     *
     * @param  Collection<int, Knjiga>  $knjige
     * @param  callable(Knjiga): array<int, string>  $kljucevi
     * @return Collection<int, object{ime: string, knjiga: int, zaduzenja: int, bez: int}>
     */
    private function grupisaniZaduzenja(Collection $knjige, callable $kljucevi): Collection
    {
        $grupe = [];

        foreach ($knjige as $k) {
            $c = (int) $k->arhiva_zaduzenja_count;

            foreach ($kljucevi($k) as $ime) {
                if (!isset($grupe[$ime])) {
                    $grupe[$ime] = ['ime' => $ime, 'knjiga' => 0, 'zaduzenja' => 0, 'bez' => 0];
                }
                $grupe[$ime]['knjiga']++;
                $grupe[$ime]['zaduzenja'] += $c;
                if ($c === 0) {
                    $grupe[$ime]['bez']++;
                }
            }
        }

        return collect(array_values($grupe))->map(fn ($g) => (object) $g);
    }

    /**
     * Broj knjiga i UKUPAN broj zaduženja (SUM po arhivi) za dati žanr —
     * za poređenje popularnosti žanrova.
     *
     * @return array{knjiga: int, zaduzenja: int}
     */
    public function zaduzenjaZanra(Zanr $zanr): array
    {
        $ids = $zanr->knjige()->pluck('knjige.id')->all();

        $zaduzenja = empty($ids) ? 0 : Arhiva::where('tip', ArhivaTip::Zaduzena->value)
            ->whereIn('knjiga_id', $ids)
            ->count();

        return ['knjiga' => count($ids), 'zaduzenja' => $zaduzenja];
    }

    /**
     * Autor sa NAJVIŠE ukupnih zaduženja (MAX po SUM-u) — grupisano po polju
     * autor nad arhivom, samo za knjige koje još postoje u katalogu.
     *
     * @return array{autor: string, zaduzenja: int}|null  null kada zaduženja nema
     */
    public function najcitanijiAutor(): ?array
    {
        $red = Arhiva::where('tip', ArhivaTip::Zaduzena->value)
            ->whereIn('knjiga_id', Knjiga::select('id'))
            ->whereNotNull('autor')
            ->where('autor', '!=', '')
            ->groupBy('autor')
            ->selectRaw('autor, COUNT(*) as ukupno')
            ->orderByDesc('ukupno')
            ->first();

        return $red ? ['autor' => (string) $red->autor, 'zaduzenja' => (int) $red->ukupno] : null;
    }

    /**
     * Žanr sa NAJVIŠE ukupnih zaduženja — zaduženja po knjizi (COUNT) se
     * sabiraju po žanrovima knjige (SUM); fond je mali pa se raspodela po
     * žanrovima računa u PHP-u.
     *
     * @return array{naziv: string, zaduzenja: int}|null  null kada zaduženja nema
     */
    public function najcitanijiZanr(): ?array
    {
        $poKnjizi = Arhiva::where('tip', ArhivaTip::Zaduzena->value)
            ->whereNotNull('knjiga_id')
            ->groupBy('knjiga_id')
            ->selectRaw('knjiga_id, COUNT(*) as ukupno')
            ->pluck('ukupno', 'knjiga_id');

        if ($poKnjizi->isEmpty()) {
            return null;
        }

        $poZanru = [];
        foreach (Zanr::with('knjige:knjige.id')->get() as $zanr) {
            $suma = 0;
            foreach ($zanr->knjige as $knjiga) {
                $suma += (int) ($poKnjizi[$knjiga->id] ?? 0);
            }
            if ($suma > 0) {
                $poZanru[$zanr->naziv] = $suma;
            }
        }

        if (empty($poZanru)) {
            return null;
        }

        arsort($poZanru);

        return ['naziv' => array_key_first($poZanru), 'zaduzenja' => $poZanru[array_key_first($poZanru)]];
    }

    /**
     * Knjiga sa NAJVEĆIM ODNOSOM broja rezervacija prema broju primeraka
     * (MAX nad COUNT/COUNT) — računa se u PHP-u nad withCount agregatima.
     *
     * @return array{knjiga: Knjiga, rezervacije: int, primeraka: int, odnos: float}|null
     *         null kada nijedna knjiga nema ni rezervaciju ni primerak
     */
    public function najveciOdnosRezervacijaPrimeraka(): ?array
    {
        $najbolja = null;

        $knjige = Knjiga::withCount([
            'rezervacije as broj_rezervacija_count',
            'primerci as ukupno_primeraka_count',
        ])->get(['id', 'naziv_knjige', 'autor']);

        foreach ($knjige as $k) {
            $rezervacije = (int) $k->broj_rezervacija_count;
            $primeraka   = (int) $k->ukupno_primeraka_count;

            if ($rezervacije === 0 || $primeraka === 0) {
                continue;
            }

            $odnos = $rezervacije / $primeraka;

            if ($najbolja === null || $odnos > $najbolja['odnos']) {
                $najbolja = [
                    'knjiga'      => $k,
                    'rezervacije' => $rezervacije,
                    'primeraka'   => $primeraka,
                    'odnos'       => $odnos,
                ];
            }
        }

        return $najbolja;
    }

    /**
     * PROSEČAN broj zaduženja po naslovu — AVG: ukupan broj zaduženja u
     * arhivi (SUM) podeljen brojem naslova u katalogu (COUNT).
     *
     * @return array{ukupno: int, naslova: int, prosek: float}|null  null bez naslova
     */
    public function prosekZaduzenjaPoNaslovu(): ?array
    {
        $naslova = Knjiga::count();

        if ($naslova === 0) {
            return null;
        }

        $ukupno = Arhiva::where('tip', ArhivaTip::Zaduzena->value)
            ->whereIn('knjiga_id', Knjiga::select('id'))
            ->count();

        return ['ukupno' => $ukupno, 'naslova' => $naslova, 'prosek' => $ukupno / $naslova];
    }

    /**
     * Najtraženije knjige SA BROJEVIMA, za fiksne strukturne odgovore —
     * COUNT po knjizi, opadajuće, samo knjige sa bar jednim događajem:
     *  - metrika 'zaduzenja'   → istorija zaduženja iz arhive,
     *  - metrika 'rezervacije' → AKTIVNE rezervacije (na čekanju/odobrene) —
     *    „najviše rezervisane u ovom trenutku";
     *  - $samoDostupne → samo knjige sa bar jednim slobodnim primerkom
     *    („popularne knjige dostupne odmah").
     *
     * @return Collection<int, Knjiga>  sa *_count atributima
     */
    public function najcitanijeSaBrojem(int $limit, string $metrika = 'zaduzenja', bool $samoDostupne = false): Collection
    {
        $aktivne = [RezervacijaStatus::NaCekanju->value, RezervacijaStatus::Odobrena->value];

        $kolona = $metrika === 'rezervacije' ? 'aktivne_rezervacije_count' : 'broj_zaduzenja_count';

        // Filter i sortiranje po withCount agregatu rade se u PHP-u: SQLite
        // ne dozvoljava HAVING nad upitom bez GROUP BY, a fond je mali.
        return Knjiga::withCount([
            'arhivaZaduzenja as broj_zaduzenja_count',
            'rezervacije as aktivne_rezervacije_count' => fn ($q) => $q->whereIn('status', $aktivne),
            'primerci as slobodni_primerci_count' => fn ($q) =>
                $q->where('status', PrimerakStatus::Slobodna->value),
        ])
            ->get(['id', 'naziv_knjige', 'autor'])
            ->filter(fn (Knjiga $k) => (int) $k->{$kolona} > 0
                && (!$samoDostupne || (int) $k->slobodni_primerci_count > 0))
            ->sortBy([[$kolona, 'desc'], ['naziv_knjige', 'asc']])
            ->take($limit)
            ->values();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ČLAN — stanje naloga
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Stanje naloga ulogovanog člana (bib-pravila.md tačka 6: rezervacije i
     * zaduženja) — aktivna rezervacija i aktivno zaduženje sa rokovima.
     *
     * @return array{rezervacija: ?array, zaduzenje: ?array}
     */
    public function clanKontekst(int $userId): array
    {
        $rezervacija = Rezervacija::with('knjiga:id,naziv_knjige')
            ->where('user_id', $userId)
            ->whereIn('status', [RezervacijaStatus::NaCekanju->value, RezervacijaStatus::Odobrena->value])
            ->latest('id')
            ->first();

        $zaduzenje = Zaduzivanje::with('primerak.knjiga:id,naziv_knjige')
            ->where('user_id', $userId)
            ->whereNull('datum_vracanja')
            ->latest('id')
            ->first();

        return [
            'rezervacija' => $rezervacija ? [
                'knjiga'          => $rezervacija->knjiga?->naziv_knjige ?? '',
                'status'          => $rezervacija->status->label(),
                'rok_preuzimanja' => $rezervacija->rok_preuzimanja?->format('d.m.Y.'),
            ] : null,
            'zaduzenje' => $zaduzenje ? [
                'knjiga'       => $zaduzenje->primerak?->knjiga?->naziv_knjige ?? '',
                'rok_vracanja' => $zaduzenje->rok_vracanja?->format('d.m.Y.'),
            ] : null,
        ];
    }
}