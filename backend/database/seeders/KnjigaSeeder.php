<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\{Knjiga, PrimerakKnjige, Zanr};

class KnjigaSeeder extends Seeder
{
    public function run(): void
    {
        $zanrovi = Zanr::pluck('id', 'naziv');

        $knjige = [
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Uvod u programiranje',
                    'signatura'              => '1',
                    'autor'                  => 'Dr Dragan Urošević',
                    'mesto_izdavanja'        => 'Niš',
                    'godina_izdavanja'       => 2020,
                    'izdavac'                => 'ATVSS',
                    'nacin_nabavke'          => 'Kupovina',
                    'opis'                   => 'Osnove programiranja sa primerima.',
                    'slika'                  => 'images/uvod_u_programiranje.jpg',
                ],
                'zanrovi' => ['Programiranje'],
                'primerak' => [
                    'signatura'     => '1',
                    'status'        => 'zadužena',
                    'lokacija'      => 'Polica A1',
                    'nacin_nabavke' => 'donacija',
                ],
            ],
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Baze podataka',
                    'signatura'              => '2',
                    'autor'                  => 'Petar Petrović',
                    'mesto_izdavanja'        => 'Beograd',
                    'godina_izdavanja'       => 2019,
                    'izdavac'                => 'ETF',
                    'nacin_nabavke'          => 'Kupovina',
                    'isbn'                   => '978-86-000-0000-0',
                    'opis'                   => 'Uvod u relacione baze i SQL.',
                    'slika'                  => 'images/baze_podataka.jpg',
                ],
                'zanrovi' => ['Baze podataka', 'Programiranje'],
                'primerak' => [
                    'signatura'     => '2',
                    'status'        => 'slobodna',
                    'lokacija'      => 'Čitaonica 1',
                    'nacin_nabavke' => 'kupovina',
                    'cena'          => 1500,
                ],
            ],
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Uznemireni ljudi',
                    'signatura'              => '3',
                    'autor'                  => 'Fredrik Bekman',
                    'mesto_izdavanja'        => 'Beograd',
                    'godina_izdavanja'       => 2020,
                    'izdavac'                => 'Laguna',
                    'nacin_nabavke'          => 'Donacija',
                    'opis'                   => 'Roman o ljudima, strahu i razumevanju.',
                    'slika'                  => 'images/uznemireni_ljudi.jpg',
                ],
                'zanrovi' => ['Roman', 'Drama'],
                'primerak' => [
                    'signatura'     => '3',
                    'status'        => 'slobodna',
                    'lokacija'      => 'Polica B2',
                    'nacin_nabavke' => 'donacija',
                ],
            ],
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Moja baka vam se izvinjava',
                    'signatura'              => '4',
                    'autor'                  => 'Fredrik Bekman',
                    'mesto_izdavanja'        => 'Beograd',
                    'godina_izdavanja'       => 2018,
                    'izdavac'                => 'Laguna',
                    'nacin_nabavke'          => 'Donacija',
                    'opis'                   => 'Roman o starijoj luckastoj zeni.',
                    'slika'                  => 'images/moja-baka-vam-se-izvinjava.png',
                ],
                'zanrovi' => ['Roman', 'Humor'],
                'primerak' => [
                    'signatura'     => '4',
                    'status'        => 'rezervisana',
                    'lokacija'      => 'Polica B5',
                    'nacin_nabavke' => 'donacija',
                ],
            ],
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Ženski razgovori',
                    'signatura'              => '5',
                    'autor'                  => 'Dušan Radović',
                    'mesto_izdavanja'        => 'Beograd',
                    'godina_izdavanja'       => 2020,
                    'izdavac'                => 'Laguna',
                    'nacin_nabavke'          => 'Kupovina',
                    'opis'                   => 'Zbirka duhovitih priča iz ugla različitih žena (i muškaraca) o ljubavi, sreći i zajedničkom življenju.',
                    'slika'                  => 'images/zenski_razgovori.jpg',
                ],
                'zanrovi' => ['Humor'],
                'primerak' => [
                    'signatura'     => '5',
                    'status'        => 'slobodna',
                    'lokacija'      => 'Polica B1',
                    'nacin_nabavke' => 'kupovina',
                ],
            ],
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Solunska',
                    'signatura'              => '6',
                    'autor'                  => 'Nele Karajlić',
                    'mesto_izdavanja'        => 'Beograd',
                    'godina_izdavanja'       => 2020,
                    'izdavac'                => 'Laguna',
                    'nacin_nabavke'          => 'Donacija',
                    'opis'                   => 'Priča o jednoj kući, jednom gradu i dva sveta.',
                    'slika'                  => 'images/solunska.png',
                ],
                'zanrovi' => ['Roman'],
                'primerak' => [
                    'signatura'     => '6',
                    'status'        => 'zadužena',
                    'lokacija'      => 'Polica B6',
                    'nacin_nabavke' => 'donacija',
                ],
            ],
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Zemlja snovija',
                    'signatura'              => '7',
                    'autor'                  => 'Branko Miloradović',
                    'mesto_izdavanja'        => 'Beograd',
                    'godina_izdavanja'       => 2020,
                    'izdavac'                => 'Laguna',
                    'nacin_nabavke'          => 'Kupovina',
                    'opis'                   => 'Lekari se bore za život čuvenog arheologa profesora Hadžića.',
                    'slika'                  => 'images/zemlja_snovija.jpg',
                ],
                'zanrovi' => ['Naučna fantastika', 'Drama'],
                'primerak' => [
                    'signatura'     => '7',
                    'status'        => 'slobodna',
                    'lokacija'      => 'Polica B10',
                    'nacin_nabavke' => 'kupovina',
                ],
            ],
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Čovek sa pruge',
                    'signatura'              => '8',
                    'autor'                  => 'Erik Lomaks',
                    'mesto_izdavanja'        => 'Beograd',
                    'godina_izdavanja'       => 2014,
                    'izdavac'                => 'Albion Books',
                    'nacin_nabavke'          => 'Donacija',
                    'opis'                   => '„Čovek sa pruge" je priča o izdanoj nevinosti.',
                    'slika'                  => 'images/covek_sa_pruge.jpg',
                ],
                'zanrovi' => ['Autobiografija'],
                'primerak' => [
                    'signatura'     => '8',
                    'status'        => 'zadužena',
                    'lokacija'      => 'Polica B11',
                    'nacin_nabavke' => 'donacija',
                ],
            ],
            [
                'knjiga' => [
                    'naziv_knjige'           => 'Veštačka inteligencija',
                    'signatura'              => '9',
                    'autor'                  => 'Michael G Smith',
                    'mesto_izdavanja'        => 'Online',
                    'godina_izdavanja'       => 2023,
                    'izdavac'                => 'Tech Books',
                    'nacin_nabavke'          => 'Digitalna licenca',
                    'isbn'                   => '978-1-234567-89-0',
                    'opis'                   => 'Kompletan uvod u AI sa praktičnim primerima.',
                    'slika'                  => 'images/ai_book.jpg',
                    'je_eknjiga'             => true,
                    'eknjiga_link'           => 'http://lib.ysu.am/disciplines_bk/efdd4d1d4c2087fe1cbe03d9ced67f34.pdf',
                ],
                'zanrovi' => ['Programiranje'],
                'primerak' => null, // e-knjiga nema fizički primerak
            ],
        ];

        foreach ($knjige as $i => $data) {
            $k = Knjiga::firstOrCreate(
                ['naziv_knjige' => $data['knjiga']['naziv_knjige']],
                array_merge($data['knjiga'], [
                    'in_broj' => $i + 1,
                    'datum'   => now(),
                ])
            );

            $zanrIds = collect($data['zanrovi'])
                ->map(fn($naziv) => $zanrovi[$naziv] ?? null)
                ->filter()
                ->toArray();

            $k->zanrovi()->syncWithoutDetaching($zanrIds);

            // E-knjige nemaju fizički primerak
            if ($data['primerak'] !== null) {
                PrimerakKnjige::firstOrCreate(
                    ['inventarski_broj' => $i + 1],
                    array_merge($data['primerak'], [
                        'knjiga_id'        => $k->id,
                        'inventarski_broj' => $i + 1,
                        'datum_unosa'      => now(),
                    ])
                );
            }
        }
    }
}