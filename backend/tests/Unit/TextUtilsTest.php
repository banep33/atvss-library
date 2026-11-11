<?php

namespace Tests\Unit;

use App\Services\Ai\TextUtils;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TextUtils::normalize — PHP ogledalo funkcije normalize() iz
 * rag_service/main.py: latinica, mala slova, bez dijakritika.
 * Leksička pretraga (KatalogPretraga) poredi upravo ovaj oblik.
 */
class TextUtilsTest extends TestCase
{
    #[Test]
    public function normalize_transliterise_cirilicu_u_latinicu(): void
    {
        $this->assertSame(
            'da li imate knjige o programiranju?',
            TextUtils::normalize('Да ли имате књиге о програмирању?')
        );
    }

    #[Test]
    public function normalize_skida_dijakritike_i_mala_slova(): void
    {
        $this->assertSame('zaduzenje dj cosic', TextUtils::normalize('Zaduženje Đ Ćosić'));
    }

    #[Test]
    public function normalize_sazima_razmake(): void
    {
        $this->assertSame('a b c', TextUtils::normalize("  a\t b \n c  "));
    }

    #[Test]
    public function fiksne_poruke_odgovaraju_specifikaciji(): void
    {
        $this->assertSame('Nisam razumeo poruku', TextUtils::NOT_UNDERSTOOD);
        $this->assertSame('Nisam vas razumeo, da li možete da ponovite', TextUtils::NOT_RECOGNIZED);
    }

    #[Test]
    public function ocisti_linkove_zadrzava_validne_a_nevalidne_svodi_na_tekst(): void
    {
        $tekst = 'Imamo [Koreni](/knjige/3), a i [Izmišljena](/knjige/99) je tu.';

        $this->assertSame(
            'Imamo [Koreni](/knjige/3), a i Izmišljena je tu.',
            TextUtils::ocistiLinkove($tekst, [3 => 'Koreni', 7 => 'Druga'])
        );
    }

    #[Test]
    public function ocisti_linkove_ispravlja_naslov_na_kanonski_iz_baze(): void
    {
        // Model ume da prepriča naslov — link ka dozvoljenoj knjizi uvek
        // dobija tačan naslov iz baze.
        $this->assertSame(
            'Imamo [Dramske paralele](/knjige/15).',
            TextUtils::ocistiLinkove('Imamo [Istorija drame](/knjige/15).', [15 => 'Dramske paralele'])
        );
    }

    #[Test]
    public function stream_filter_validira_link_isecen_preko_vise_tokena(): void
    {
        // Link stiže isečen kroz tokene — kao iz Ollama streama.
        $tokeni = ['Imamo [Kor', 'eni](/knj', 'ige/3) i [Lažna](/knjige/', '99) ovde.'];

        $izlaz = implode('', iterator_to_array(
            TextUtils::filtrirajLinkoveStream($tokeni, [3 => 'Koreni']), preserve_keys: false
        ));

        $this->assertSame('Imamo [Koreni](/knjige/3) i Lažna ovde.', $izlaz);
    }

    #[Test]
    public function ekaviziraj_prevodi_ijekavicu_u_ekavicu_i_cuva_veliko_slovo(): void
    {
        $this->assertSame(
            'Gde se nalazi biblioteka? Uvek radi u vreme ispita.',
            TextUtils::ekaviziraj('Gdje se nalazi knjižnica? Uvijek radi u vrijeme ispita.')
        );
    }

    #[Test]
    public function ekavizacija_ne_dira_naslov_u_linku(): void
    {
        $this->assertSame(
            'Preporučujem [Lijepa riječ](/knjige/4) (Pera Perić) — lepa knjiga.',
            TextUtils::ekavizirajTekst('Preporučujem [Lijepa riječ](/knjige/4) (Pera Perić) — lijepa knjiga.')
        );
    }

    #[Test]
    public function ekavizacija_streama_radi_i_kada_je_rec_presecena_tokenima(): void
    {
        $tokeni = ['Uvi', 'jek možete doći, ', 'gd', 'je god bili.'];

        $izlaz = implode('', iterator_to_array(
            TextUtils::ekavizirajStream($tokeni), preserve_keys: false
        ));

        $this->assertSame('Uvek možete doći, gde god bili.', $izlaz);
    }

    #[Test]
    public function stream_filter_pusta_uglastu_zagradu_koja_nije_link(): void
    {
        $tokeni = ['spisak [1] i [oznaka] bez linka', ' kraj'];

        $izlaz = implode('', iterator_to_array(
            TextUtils::filtrirajLinkoveStream($tokeni, []), preserve_keys: false
        ));

        $this->assertSame('spisak [1] i [oznaka] bez linka kraj', $izlaz);
    }
}
