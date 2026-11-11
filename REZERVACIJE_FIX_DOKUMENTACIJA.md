# Dokumentacija: Kompletna popravka rezervacijskog sistema

## Datum: 5. februar 2026.
## Problem: Primerci označeni kao 'rezervisana' bez aktivne rezervacije

---

## 🔍 Identifikovani problemi

1. **Bug u logici odbijanja rezervacije** - Kada admin odbije rezervaciju, primerak ostaje u statusu 'rezervisana'
2. **Nedostatak endpoint-a za otkazivanje** - Korisnici ne mogu da otkažu sopstvene rezervacije
3. **Automatsko čišćenje** - Nema automatskog čišćenja isteklih rezervacija
4. **Istorijski podaci** - Test podaci u bazi imaju nekonzistentno stanje

---

## ✅ Implementirana rešenja

### 1. Popravljena logika odbijanja rezervacije

**Fajl:** `backend/routes/api.php` (linija ~525)

**Šta je dodato:**
```php
Route::patch('/rezervacije/{id}/odbij', function ($id) {
    $rezervacija = Rezervacija::findOrFail($id);
    
    // NOVO: Ako je rezervacija već odobrena, oslobodi primerak
    if ($rezervacija->status === 'odobrena') {
        $primerak = $rezervacija->knjiga->primerci()
            ->where('status', 'rezervisana')
            ->first();
        if ($primerak) {
            $primerak->update(['status' => 'slobodna']);
        }
    }
    
    $rezervacija->status = 'odbijena';
    $rezervacija->save();
    // ... notifikacije ...
});
```

**Razlog:** Kada admin odbije odobrenu rezervaciju, primerak mora biti vraćen u status 'slobodna'.

---

### 2. Endpoint za otkazivanje rezervacije od strane korisnika

**Fajl:** `backend/routes/api.php` (linija ~557)

**Novi endpoint:**
```php
Route::delete('/rezervacije/{id}/otkazi', function ($id) {
    $user = Auth::user();
    $rezervacija = Rezervacija::findOrFail($id);
    
    // Provera autorizacije
    if ($rezervacija->user_id !== $user->id) {
        return response()->json(['success' => false, 'message' => 'Nemate dozvolu...'], 403);
    }
    
    // Provera statusa - može se otkazati samo 'na_cekanju' i 'odobrena'
    if (in_array($rezervacija->status, ['odbijena', 'preuzeta', 'otkazana'])) {
        return response()->json(['success' => false, 'message' => 'Ne može biti otkazana.'], 400);
    }
    
    // Oslobađanje primerka ako je rezervacija odobrena
    if ($rezervacija->status === 'odobrena') {
        $primerak = $rezervacija->knjiga->primerci()
            ->where('status', 'rezervisana')
            ->first();
        if ($primerak) {
            $primerak->update(['status' => 'slobodna']);
        }
    }
    
    $rezervacija->status = 'otkazana';
    $rezervacija->save();
    
    return response()->json(['success' => true, 'message' => 'Rezervacija uspešno otkazana.']);
})->middleware('auth');
```

**Kako koristiti:**
```javascript
// Frontend primer
fetch(`/api/rezervacije/${rezervacijaId}/otkazi`, {
    method: 'DELETE',
    headers: {
        'X-CSRF-TOKEN': csrfToken
    }
})
```

---

### 3. Admin cleanup endpoint za istorijske probleme

**Fajl:** `backend/routes/api.php` (linija ~1074)

**Novi endpoint:**
```php
Route::post('/admin/cleanup-rezervacije', function () {
    // Pronalazi sve primerke sa status='rezervisana'
    $rezervisaniPrimerci = PrimerakKnjige::where('status', 'rezervisana')->get();
    
    $ocisceni = [];
    $preskoceni = [];
    
    foreach ($rezervisaniPrimerci as $primerak) {
        // Proverava da li postoji aktivna rezervacija (na_cekanju ili odobrena)
        $aktivnaRezervacija = Rezervacija::where('knjiga_id', $primerak->knjiga_id)
            ->whereIn('status', ['na_cekanju', 'odobrena'])
            ->first();
        
        if (!$aktivnaRezervacija) {
            // Nema aktivne rezervacije - oslobodi primerak
            $primerak->update(['status' => 'slobodna']);
            $ocisceni[] = [...];
        } else {
            // Ima aktivnu rezervaciju - zadrži status
            $preskoceni[] = [...];
        }
    }
    
    return response()->json([
        'success' => true,
        'message' => "Očišćeno {count($ocisceni)} primeraka",
        'ocisceni' => $ocisceni,
        'preskoceni' => $preskoceni
    ]);
})->middleware('admin');
```

**Kako koristiti:**
- Admin panel: `/admin/rezervacije` → Tab "Održavanje" → Dugme "Očisti rezervisane primerke"
- API direktno: `POST /api/admin/cleanup-rezervacije`

---

### 4. Automatsko čišćenje isteklih rezervacija

**Fajl:** `backend/routes/api.php` (linija ~1110)

**Novi endpoint:**
```php
Route::post('/admin/cleanup-istekle-rezervacije', function () {
    // Pronalazi sve odobrene rezervacije sa isteklim rokom preuzimanja
    $istekle = Rezervacija::where('status', 'odobrena')
        ->where('rok_preuzimanja', '<', now())
        ->get();
    
    $ociscene = [];
    
    foreach ($istekle as $rezervacija) {
        // Oslobodi primerak
        $primerak = $rezervacija->knjiga->primerci()
            ->where('status', 'rezervisana')
            ->first();
        
        if ($primerak) {
            $primerak->update(['status' => 'slobodna']);
        }
        
        // Označi kao istekla
        $rezervacija->update(['status' => 'istekla']);
        
        // Pošalji notifikaciju
        Notifikacija::create([
            'user_id' => $rezervacija->user_id,
            'rezervacija_id' => $rezervacija->id,
            'tip' => 'rezervacija_istekla',
            'poruka' => "Vaša rezervacija za knjigu \"{$rezervacija->knjiga->naziv_knjige}\" je istekla.",
            'procitano' => false
        ]);
        
        $ociscene[] = [...];
    }
    
    return response()->json([
        'success' => true,
        'message' => "Očišćeno {count($ociscene)} isteklih rezervacija",
        'ociscene' => $ociscene
    ]);
})->middleware('admin');
```

**Kako koristiti:**
- Admin panel: `/admin/rezervacije` → Tab "Održavanje" → Dugme "Očisti istekle rezervacije"
- API direktno: `POST /api/admin/cleanup-istekle-rezervacije`
- **Preporuka:** Dodati u cron job da se izvršava automatski svaki dan:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        // Pozovi cleanup endpoint
        Http::post(config('app.url') . '/api/admin/cleanup-istekle-rezervacije');
    })->daily();
}
```

---

### 5. Novi statusi rezervacija

**Fajl:** `database/migrations/2026_02_05_112753_add_otkazana_istekla_status_to_rezervacije.php`

**Dodati statusi:**
- `'otkazana'` - Korisnik je otkazao rezervaciju
- `'istekla'` - Istekao rok preuzimanja (5 dana)

**Svi statusi:**
1. `na_cekanju` - Nova rezervacija čeka odobrenje
2. `odobrena` - Admin je odobrio, primerak rezervisan
3. `odbijena` - Admin je odbio
4. `preuzeta` - Knjiga je zadužena
5. **`otkazana`** ✨ NOVO - Korisnik otkazao
6. **`istekla`** ✨ NOVO - Istekao rok

---

### 6. Admin UI za održavanje

**Fajl:** `backend/resources/views/admin/rezervacije.blade.php`

**Šta je dodato:**
- Novi tab "Održavanje" u navigaciji
- Sekcija za čišćenje rezervisanih primeraka
- Sekcija za čišćenje isteklih rezervacija
- JavaScript funkcije `cleanupRezervacije()` i `cleanupIstekle()`
- Prikaz rezultata sa detaljima (koji primerci/rezervacije su očišćeni)

**Lokacija u interfejsu:**
```
Admin Panel → Rezervacije → Tab "Održavanje" (ikonica šarafa)
```

---

## 📊 Kako testirati

### Test 1: Odbijanje odobrene rezervacije
1. Uloguj se kao admin
2. Idi na `/admin/rezervacije`
3. Odobri neku rezervaciju - primerak će biti označen kao 'rezervisana'
4. Odmah odbij tu rezervaciju
5. ✅ Proveri da je primerak vraćen na status 'slobodna'

### Test 2: Otkazivanje rezervacije od strane korisnika
1. Uloguj se kao član
2. Rezerviši neku knjigu
3. Nakon što admin odobri, otvori `/moje-rezervacije` (ako postoji endpoint)
4. Otkaži rezervaciju
5. ✅ Proveri da je status 'otkazana' i primerak 'slobodna'

### Test 3: Cleanup istorijskih podataka
1. Uloguj se kao admin
2. Idi na `/admin/rezervacije` → Tab "Održavanje"
3. Klikni "Očisti rezervisane primerke"
4. ✅ Trebalo bi da vidiš listu očišćenih primeraka

### Test 4: Automatsko čišćenje isteklih
1. U bazi, ručno postavi neku odobrenu rezervaciju sa `rok_preuzimanja` u prošlosti:
   ```sql
   UPDATE rezervacije 
   SET rok_preuzimanja = '2026-01-01' 
   WHERE id = 1;
   ```
2. Idi na `/admin/rezervacije` → Tab "Održavanje"
3. Klikni "Očisti istekle rezervacije"
4. ✅ Rezervacija će biti označena kao 'istekla', primerak 'slobodna', korisnik dobija notifikaciju

---

## 🚀 Deployment checklist

- [x] Migracija baze podataka (`php artisan migrate`)
- [x] Provera sintakse (`php -l routes/api.php`)
- [x] Testiranje endpoint-a preko admin panela
- [ ] Dodavanje cron job-a za automatsko čišćenje (preporuka)
- [ ] Update dokumentacije za korisnike
- [ ] Obuka admin korisnika za nove funkcionalnosti

---

## 🔒 Bezbednost

### Autorizacija
- `odbij` endpoint - `middleware('admin')`
- `otkazi` endpoint - `middleware('auth')` + provera `user_id`
- `cleanup-*` endpoints - `middleware('admin')`

### Validacija
- Otkazivanje može samo korisnik koji je kreirao rezervaciju
- Ne mogu se otkazati već preuzete/odbijene/otkazane rezervacije
- Cleanup radi samo sa primerci koji nemaju aktivne rezervacije

---

## 📝 Tehnički detalji

### Eloquent Relationships korišćeni
- `Rezervacija::with('knjiga.primerci')`
- `$rezervacija->knjiga->primerci()->where('status', 'rezervisana')->first()`
- `$primerak->knjiga_id` za povezivanje

### Database Transactions
**Napomena:** Trenutno nisu implementirane transakcije. Za production preporuka:

```php
DB::beginTransaction();
try {
    // Oslobodi primerak
    $primerak->update(['status' => 'slobodna']);
    
    // Označi kao otkazana
    $rezervacija->update(['status' => 'otkazana']);
    
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

---

## 🐛 Poznati problemi i limitacije

1. **Konkurentnost:** Ako dva korisnika istovremeno rezervišu istu knjigu, možda će oba uspeti pre nego što se status ažurira. Preporuka: dodati database lock.

2. **Notifikacije za otkazivanje:** Korisnik koji otkaže ne dobija notifikaciju (možda i ne treba).

3. **Istorija statusa:** Nema audit trail-a - ne vidiš ko je i kada menjao status. Za production: dodati `rezervacije_history` tabelu.

4. **Bulk operacije:** Cleanup može biti spor ako ima puno primeraka. Za production: dodati job queue.

---

## 📚 Povezani fajlovi

- `backend/routes/api.php` - Svi endpoint-i
- `backend/resources/views/admin/rezervacije.blade.php` - Admin UI
- `backend/database/migrations/2026_02_05_112753_add_otkazana_istekla_status_to_rezervacije.php` - Migracija
- `backend/app/Models/Rezervacija.php` - Model (možda treba ažurirati fillable)
- `backend/app/Models/PrimerakKnjige.php` - Model primerka

---

## ✨ Budući improvements

1. **Automatski cron job** za čišćenje isteklih rezervacija (svaki dan u ponoć)
2. **Email notifikacije** kada rezervacija ističe (1 dan pre)
3. **Produžavanje rezervacije** - korisnik može da zatraži produženje roka
4. **Prioritetna lista** - ako knjiga nije dostupna, automatski rezerviši kada se oslobodi
5. **Statistika** - koliko rezervacija je otkazano, isteklo, itd.

---

## 👥 Autori izmena

- **Milan** - Identifikacija problema i zahtev za popravku
- **GitHub Copilot** - Implementacija svih rešenja (5. februar 2026.)

---

## 📞 Podrška

Za pitanja ili probleme kontaktirajte:
- Email: biblioteka@akademija.edu.rs
- Tel: +381 18 123 4567

---

**Verzija:** 1.0  
**Poslednja izmena:** 5. februar 2026.
