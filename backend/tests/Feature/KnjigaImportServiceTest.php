<?php

namespace Tests\Feature;

use App\Models\Knjiga;
use App\Models\PrimerakKnjige;
use App\Services\Ai\RagService;
use App\Services\KnjigaImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Uvoz iz Excel-a: redovi sa istim (naziv, autor) su PRIMERCI iste knjige.
 *
 * Grupisanje po naziv+autor (odluka korisnika): isti naslov+autor → jedna Knjiga
 * sa više Primeraka (po inventarskom broju). Red bez naziva/autora = svoja knjiga.
 * Inventarski broj je jedinstven ID primerka → ponovni uvoz ne duplira primerke.
 */
class KnjigaImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Pravi privremeni .xlsx sa zadatim redovima (bez header reda na ulazu). */
    private function napraviExcel(array $redovi): string
    {
        $ss    = new Spreadsheet();
        $sheet = $ss->getActiveSheet();

        $data = array_merge(
            [['Inv', 'Sig', '', 'Autor', 'Naziv', 'Mesto', 'Izdavac', 'Nabavka', 'ISBN', 'Zanr', 'Zanr2']],
            $redovi
        );
        $sheet->fromArray($data, null, 'A1');

        $path = sys_get_temp_dir() . '/' . uniqid('imp_') . '.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    /** @test */
    public function isti_naziv_i_autor_postaju_primerci_iste_knjige(): void
    {
        $rag = Mockery::mock(RagService::class);
        $rag->shouldReceive('reindexMany')->andReturn(0);

        $path = $this->napraviExcel([
            // dva primerka iste knjige (inv 94 i 220)
            ['94',  '94',  '', 'Ivo Andric', 'Unutrasnja strana sveta', 'Nis 2020', 'Prosveta', 'Kupovina', '111', 'Roman', ''],
            ['220', '94',  '', 'Ivo Andric', 'Unutrasnja strana sveta', 'Nis 2020', 'Prosveta', 'Kupovina', '111', 'Roman', ''],
            // druga knjiga
            ['300', '300', '', 'Pera Peric', 'Programiranje',           'Nis 2021', 'CET',      'Kupovina', '222', 'Programiranje', ''],
        ]);

        $rezultat = (new KnjigaImportService($rag))->uvezi($path);

        $this->assertSame(2, $rezultat['knjige']);   // 2 različite knjige
        $this->assertSame(3, $rezultat['primerci']); // 3 reda = 3 primerka
        $this->assertSame(2, Knjiga::count());
        $this->assertSame(3, PrimerakKnjige::count());

        // Knjiga sa dva primerka.
        $knjiga = Knjiga::where('naziv_knjige', 'Unutrasnja strana sveta')->first();
        $this->assertNotNull($knjiga);
        $this->assertSame(2, $knjiga->primerci()->count());

        @unlink($path);
    }

    /** @test */
    public function uvoz_razdvaja_mesto_i_godinu_u_zasebne_kolone(): void
    {
        $rag = Mockery::mock(RagService::class);
        $rag->shouldReceive('reindexMany')->andReturn(0);

        $path = $this->napraviExcel([
            // „Mesto, godina" sa zarezom
            ['1', '1', '', 'Autor A', 'Knjiga A', 'Beograd, 2015', 'Izd', 'Kupovina', '', 'Roman', ''],
            // „Mesto godina" bez zareza
            ['2', '2', '', 'Autor B', 'Knjiga B', 'Nis 2020',      'Izd', 'Kupovina', '', 'Roman', ''],
            // samo mesto, bez godine
            ['3', '3', '', 'Autor C', 'Knjiga C', 'Cetinje',       'Izd', 'Kupovina', '', 'Roman', ''],
        ]);

        (new KnjigaImportService($rag))->uvezi($path);

        $a = Knjiga::where('naziv_knjige', 'Knjiga A')->first();
        $this->assertSame('Beograd', $a->mesto_izdavanja);
        $this->assertSame(2015, $a->godina_izdavanja);

        $b = Knjiga::where('naziv_knjige', 'Knjiga B')->first();
        $this->assertSame('Nis', $b->mesto_izdavanja);
        $this->assertSame(2020, $b->godina_izdavanja);

        $c = Knjiga::where('naziv_knjige', 'Knjiga C')->first();
        $this->assertSame('Cetinje', $c->mesto_izdavanja);
        $this->assertNull($c->godina_izdavanja);

        @unlink($path);
    }

    /** @test */
    public function ponovni_uvoz_ne_duplira_primerke(): void
    {
        $rag = Mockery::mock(RagService::class);
        $rag->shouldReceive('reindexMany')->andReturn(0);

        $path = $this->napraviExcel([
            ['94',  '94',  '', 'Ivo Andric', 'Unutrasnja strana sveta', 'Nis 2020', 'Prosveta', 'Kupovina', '111', 'Roman', ''],
            ['300', '300', '', 'Pera Peric', 'Programiranje',           'Nis 2021', 'CET',      'Kupovina', '222', 'Programiranje', ''],
        ]);

        $service = new KnjigaImportService($rag);
        $service->uvezi($path);
        $rezultat2 = $service->uvezi($path); // isti fajl ponovo

        $this->assertSame(0, $rezultat2['knjige']);
        $this->assertSame(0, $rezultat2['primerci']);
        $this->assertSame(2, Knjiga::count());
        $this->assertSame(2, PrimerakKnjige::count());

        @unlink($path);
    }
}
