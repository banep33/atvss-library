# ANALIZA: Sistem Primeraka i Zadužavanja

## 📊 Trenutna Arhitektura

### Strukturne Tabele:
```
Knjige (naziv, autor, opis, slika...)
  ├─ Primerci Knjiga (inventarski_broj, status: slobodna/zadužena/rezervisana/rashodovana)
  │   └─ Zadužavanja (clan_id, primerak_id, datum_izdavanja, rok_vracanja, datum_vracanja)
  └─ Rezervacije (clan_id, knjiga_id, status, prioritet...)
```

### Status Primerka (enum):
- `slobodna` - Dostupna za pozajmljivanje
- `zadužena` - Pozajmljena klijentu
- `rezervisana` - Rezervisana
- `rashodovana` - Uništena/Neupotrebljiva
- `čitaonica` - Na čitanju u biblioteci

---

## 🔍 PROBLEM: Zašto su knjige "Nedostupne"?

### Razlog:
```javascript
// U knjige-index.js:
const dostupna = knjiga.slobodni_primerci > 0 || knjiga.je_eknjiga;
```

Aplikacija broji `slobodni_primerci` - koliko primeraka ima status `slobodna`.
**Ako nema primeraka → `slobodni_primerci = 0` → Knjiga je "Nedostupna"**

---

## ✅ REŠENJE: Kako Dodati Primerak Knjige

### Opcija 1: Dodati API Rutu (PREPORUČENO)
**Lokacija:** `routes/api.php`

```php
// POST /api/primerci - Admin dodaje primerak knjige
Route::post('/primerci', function (Request $request) {
    $data = $request->validate([
        'knjiga_id' => 'required|exists:knjige,id',
        'inventarski_broj' => 'required|string|unique:primerci_knjiga',
        'signatura' => 'nullable|string',
        'lokacija' => 'nullable|string',
        'nacin_nabavke' => 'nullable|string',
        'cena' => 'nullable|numeric',
        'status' => 'required|in:slobodna,zadužena,rezervisana,rashodovana,čitaonica'
    ]);

    $data['datum_unosa'] = now();
    $primerak = PrimerakKnjige::create($data);

    return response()->json([
        'success' => true,
        'message' => 'Primerak uspešno dodat',
        'data' => $primerak
    ], 201);
})->middleware('admin');

// GET /api/primerci?knjiga_id={id} - Prikaži sve primeark za neku knjugu
Route::get('/primerci', function (Request $request) {
    $knihiga_id = $request->get('knjiga_id');
    $query = PrimerakKnjige::query();

    if ($knjiga_id) {
        $query->where('knjiga_id', $knjiga_id);
    }

    return $query->with('knjiga', 'aktivnoZaduzivanje')->get();
});

// DELETE /api/primerci/{id} - Admin briše primerak
Route::delete('/primerci/{id}', function ($id) {
    $primerak = PrimerakKnjige::findOrFail($id);
    
    // Proveri da li je zadužena
    if ($primerak->status === 'zadužena') {
        return response()->json([
            'success' => false,
            'message' => 'Ne možete obrisati primerak koji je zadužen!'
        ], 400);
    }

    $primerak->delete();

    return response()->json([
        'success' => true,
        'message' => 'Primerak obrisan'
    ]);
})->middleware('admin');
```

---

## 🎯 SISTEM ZADUŽAVANJA - ANALIZA

### Kako Radi (Teoretski):
1. Clan ima aktivnu `Rezervaciju` za `Knjiga` (status: čekanje)
2. Bibliotekara pronalazi `PrimerakKnjige` sa `status: slobodna`
3. Bibliotekara kreira `Zaduzivanje`:
   - `clan_id` → Korisnik
   - `primerak_id` → Konkretan primerak
   - `datum_izdavanja` → Trenutni datum
   - `rok_vracanja` → Datum kad treba vratiti (npr. +14 dana)
4. `PrimerakKnjige.status` promeni na `zadužena`
5. Clan preuzima knjigu
6. Clan vraća > `Zaduzivanje.datum_vracanja` se popuni > `status` se vrati na `slobodna`

### Problem:
**Nema UI-ja/API-ja za upravljanje zadužavanjima!** 🚨

---

## 🔴 NEDOSTAJUĆE FUNKCIONALNOSTI

### Za Primerake:
- [ ] Admin interface za dodavanje primeraka
- [ ] API rute za CRUD primeraka
- [ ] Prikaz primeraka na stranici detaljnog pregleda knjige
- [ ] Mogućnost za ažuriranje statusa primerka

### Za Zadužavanja:
- [ ] API za kreiranje zadužavanja (bibliotekara + clan + primerak)
- [ ] API za vraćanje knjige (ažuriranje `datum_vracanja`)
- [ ] Mogućnost preloga roka vraćanja
- [ ] Pogled na aktivna zadužavanja po članu
- [ ] Pogled na prekoračene rokove

### Za Rezervacije:
- [ ] Sistem automatskog alociranja primerka kada je dostupan
- [ ] Obaveštenja kada je knjiga dostupna

---

## 🛠️ PREPORUKA: Prioritet Implementacije

### FAZA 1 - Esencijalno (Sada):
1. **Dodati Admin Panel za Primerak:**
   - Modal/Stranica sa formom: "Dodaj Primerak Knjige"
   - Polja: `inventarski_broj`, `signatura`, `lokacija`, `cena`
   - Button na stranici detaljnog pregleda knjige

2. **API Rute za Primerak:**
   - POST `/api/primerci` - Dodaj
   - DELETE `/api/primerci/{id}` - Obriši
   - GET `/api/primerci?knjiga_id={id}` - Prikaži sve primerke

### FAZA 2 - Prioritet (Nedelja):
1. **Sistem Zadužavanja:**
   - POST `/api/zaduzivanja` - Bibliotekara dodeljuje
   - PATCH `/api/zaduzivanja/{id}/vrni` - Clan vraća

2. **Prikaz Informacija:**
   - Prikaži sve primerak sa statusom na detaljnoj stranici
   - Prikaži ko je zadužio i kada

### FAZA 3 - Nicetoknow (Mesec):
1. **Notifikacije** - Email kada je dostupna rezervisana knjiga
2. **Statistika** - Analitika prestampa po danu/autoru
3. **Izveštaji** - CSV export zadužavanja

---

## 🧪 TRENUTNO STANJE - TEST

### Šta Radi:
✅ Struktura baze je dobar
✅ Modeli su korigovani
✅ Enumeracija statusa je ispravna

### Šta NE Radi:
❌ Nema mogućnosti dodavanja primeraka iz UI-ja
❌ Nema mogućnosti kreiranja zadužavanja
❌ Nema mogućnosti vraćanja knjige
❌ `Rezervacija` sistema nije integrisan sa `Primerci`

---

## 📝 Primer Skladišnjenja Primerka

```sql
INSERT INTO primerci_knjiga (
    knjiga_id, 
    inventarski_broj, 
    signatura, 
    status, 
    lokacija,
    nacin_nabavke,
    cena,
    datum_unosa
) VALUES (
    1, 
    'INV-001-2024',
    '004.4/UDK',
    'slobodna',
    'Polica A-1-03',
    'Kupovina',
    1299.99,
    NOW()
);
```

---

## 🎯 SLEDEĆI KORAK

Trebam da implementiram:

1. **Admin Interface za Primerke** - Modal sa formom
2. **API Rute za CRUD Primeraka** - POST/DELETE
3. **Prikaz Primeraka** - Na stranici detaljnog pregleda

Želite li da prioritizujem nešto drugo?

---

Analiza završena: **24.01.2026**
