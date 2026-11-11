<?php

namespace App\Services\Ai;

/**
 * TipNamere — primarna namera korisničkog pitanja (IntentClassifier).
 *
 * Namera bira RUTU u AiChatService::retrieveContext (Router sloj), ali
 * konačnu odluku za pojedine tipove donosi router tek posle leksičke
 * pretrage: npr. Statistika važi samo kada nema leksičkih pogodaka
 * (pitanje o konkretnoj knjizi ide normalnim tokom), a KoJeZaduzio samo
 * kada je naslov knjige zaista pogođen u katalogu.
 */
enum TipNamere: string
{
    /** Zahtev za akcijom koja traži nalog (rezervacija, zaduženje, pozajmica). */
    case Akcija = 'akcija';

    /** Datumski opseg nad arhivom („koje knjige su zadužene pre nedelju dana"). */
    case DatumskiOpseg = 'datumski_opseg';

    /** Statistika učestalosti zaduživanja („koliko često se zadužuju knjige"). */
    case Statistika = 'statistika';

    /** Knjige koje nikada nisu zadužene („ima li knjiga koje nisu zaduživane"). */
    case NikadZaduzene = 'nikad_zaduzene';

    /** Najveći odnos broja rezervacija prema broju primeraka. */
    case OdnosRezervacija = 'odnos_rezervacija';

    /** Prosečan broj zaduženja po naslovu (AVG nad celim katalogom). */
    case ProsekZaduzenja = 'prosek_zaduzenja';

    /** Kompletna lista knjiga jednog žanra („izlistaj knjige žanra priče"). */
    case ZanrLista = 'zanr_lista';

    /** Broj knjiga sa rečju u naslovu („koliko knjiga ima reč X u nazivu"). */
    case BrojanjeReci = 'brojanje_reci';

    /** Knjige čiji naslov počinje datim slovom („koliko knjiga počinje na slovo I"). */
    case PocinjeNaSlovo = 'pocinje_na_slovo';

    /** Knjige po GODINI IZDANJA („koje knjige imate objavljene posle 2010"). */
    case GodinaIzdanja = 'godina_izdanja';

    /** Knjige IMENOVANOG izdavača („da li imate knjige izdavača Laguna"). */
    case IzdavacLista = 'izdavac_lista';

    /** Statistika o izdavačima (najviše knjiga, najpopularniji, spisak). */
    case IzdavacStatistika = 'izdavac_statistika';

    /** „Ko je zadužio knjigu X" — poslednje zaduženje iz arhive. */
    case KoJeZaduzio = 'ko_je_zaduzio';

    /** „Ko je X" — pitanje o osobi (postojanje autora se proverava u bazi). */
    case KoJeOsoba = 'ko_je_osoba';

    /** Podrazumevani tok: leksička + semantička pretraga kataloga → model. */
    case Katalog = 'katalog';
}