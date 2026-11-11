# ATVSSLIBRARY - Pregled Izmena (Sesija 23.01.2026)

## 📋 Rezime
Sesija fokusa: Ispravljanje prikaza knjiga na homesajtu, postavljanje CRUD operacija i ispravljanje rukovanja slikama.

---

## 1️⃣ MODEL IZMENE

### `app/Models/Knjiga.php`
**Problem:** Knjiga model je nedostajao `zanrovi()`, `komentari()` i `ocene()` relationships, što je uzrokovalo 500 grešku pri učitavanju API-ja.

**Rešenje:** Dodati Eloquent relationships:
```php
public function zanrovi()
{
    return $this->belongsToMany(Zanr::class, 'knjiga_zanr', 'knjiga_id', 'zanr_id');
}

public function komentari()
{
    return $this->hasMany(Komentar::class, 'knjiga_id');
}

public function ocene()
{
    return $this->hasMany(Ocena::class, 'knjiga_id');
}

public function getAverageRatingAttribute()
{
    return $this->ocene->count() > 0 
        ? round($this->ocene->avg('ocena'), 2) 
        : 0;
}
```

**$fillable ažuriranje:**
```php
protected $fillable = [
    'in_broj', 'signatura', 'datum', 'autor', 'naziv_knjige', 
    'mesto_izdavanja', 'godina_izdavanja', 'izdavac', 'nacin_nabavke', 'isbn', 
    'opis', 'slika', 'je_eknjiga', 'eknjiga_link'
];
// Napomena (2026-07-09): kolona mesto_izdavanja_godina razdvojena u
// mesto_izdavanja (string) + godina_izdavanja (integer).
```

---

## 2️⃣ API RUTE - ZAŠTITA CRUD OPERACIJA

### `routes/api.php`

**Dodan storage route za serviranje slika:**
```php
Route::get('/storage/{path}', function ($path) {
    if (!Storage::disk('public')->exists($path)) {
        return response()->file(public_path('images/default.jpg'));
    }
    
    $file = Storage::disk('public')->get($path);
    $mimeType = Storage::disk('public')->mimeType($path);
    
    return response($file, 200)->header('Content-Type', $mimeType ?? 'application/octet-stream');
})->where('path', '.*');
```

**CRUD rute zaštićene sa `admin` middleware:**
- POST `/api/knjige` - Kreiranje (admin only)
- PATCH `/api/knjige/{id}` - Ažuriranje (admin only)
- DELETE `/api/knjige/{id}` - Brisanje (admin only)
- GET `/api/knjige` - Javna (svi korisnici)
- GET `/api/knjige/{id}` - Javna (svi korisnici)

**Ažuriranje POST/PATCH za upload slika:**
```php
if ($request->hasFile('slika')) {
    $path = $request->file('slika')->store('books', 'public');
    $data['slika'] = $path; // 'books/filename.jpg'
}
```

---

## 3️⃣ MIDDLEWARE IZMENE

### `app/Http/Middleware/AdminMiddleware.php`

**Dodan dev token bypass za lokalno testiranje:**
```php
if (app()->environment('local')) {
    $devToken = config('dev.admin_token');
    if ($devToken && $request->header('X-DEV-TOKEN') === $devToken) {
        return $next($request);
    }
}
```

**Detektovanje tipa zahteva (API vs Web):**
```php
if ($request->expectsJson() || $request->is('api/*')) {
    return response()->json(['success' => false, 'message' => 'Morate biti prijavljeni'], 401);
} else {
    return redirect('/');
}
```

---

## 4️⃣ KONFIGURACIJA

### Novi fajl: `config/dev.php`
**Razlog:** Laravel config caching ne prosledi `env()` vrednosti nakon `config:cache`. Config fajl obezbeđuje pravi pristup dev tokenu.

```php
<?php
return [
    'admin_token' => env('DEV_ADMIN_TOKEN', 'dev-admin-token-please-change'),
];
```

### `.env` ažuriranja:
```
APP_URL=http://127.0.0.1:18080
SESSION_DOMAIN=127.0.0.1
DEV_ADMIN_TOKEN=dev-admin-token-please-change
```

---

## 5️⃣ FRONTEND - JAVASCRIPT IZMENE

### `public/js/knjige-index.js`

**Dodan X-DEV-TOKEN header u POST zahteve:**
```javascript
res = await fetch('/api/knjige', {
    method: 'POST',
    headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        'X-DEV-TOKEN': 'dev-admin-token-please-change'
    },
    credentials: 'include',
    body: formData
});
```

**Ažuriranje slike URL-a na `/api/storage/{path}`:**
```javascript
<img src="${knjiga.slika ? '/api/storage/' + knjiga.slika : '/images/default.jpg'}">
```

**Dodan prikaz postojeće slike u "Izmeni" modal:**
```javascript
if (data.slika) {
    document.getElementById('currentSlikaPreview').style.display = 'block';
    document.getElementById('currentSlikaImg').src = '/api/storage/' + data.slika;
    const filename = data.slika.split('/').pop();
    document.getElementById('currentSlikaName').textContent = 'Fajl: ' + filename;
}
```

---

## 6️⃣ HTML/BLADE AŽURIRANJA

### `resources/views/knjige/index.blade.php`

**Dodan preview sekcija za postojeće slike:**
```blade
<div id="currentSlikaPreview" style="margin-bottom: 15px; display: none;">
    <p style="margin: 0 0 8px 0; font-weight: 500; color: #333;">Postojeća slika:</p>
    <img id="currentSlikaImg" src="" style="max-width: 150px; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">
    <p id="currentSlikaName" style="margin: 8px 0 0 0; font-size: 12px; color: #666;"></p>
</div>
```

### `resources/views/knjige/show.blade.php`

**Ažuriranja putanja slika sa `/api/storage/{path}`:**
```blade
@if($knjiga->slika)
    <img src="/api/storage/{{ $knjiga->slika }}" alt="{{ $knjiga->naziv_knjige }}">
@else
    <img src="/images/default.jpg" alt="{{ $knjiga->naziv_knjige }}">
@endif
```

---

## 7️⃣ SKLADIŠTENJE SLIKA

### Promena pristupa:
**Pre:** `public/images/books/` (loše za produkciju)
**Posle:** `storage/app/public/books/` (Laravel best practice)

### Kako funkcioniše:
1. Upload → `/api/knjige` (POST)
2. Storage facade čuva → `storage/app/public/books/filename.jpg`
3. Baza pamti → `books/filename.jpg`
4. Frontend traži → `/api/storage/books/filename.jpg`
5. Route servira → File from `storage/app/public/books/filename.jpg`

---

## 8️⃣ TESTIRANJE

### Dev Token
**Kada:** Lokalno testiranje bez ispravljenog session-a
**Kako:** POST/PATCH/DELETE zahtev sa headerom:
```
X-DEV-TOKEN: dev-admin-token-please-change
```

### Primer cURL:
```bash
curl -X POST http://127.0.0.1:18080/api/knjige \
  -H "X-DEV-TOKEN: dev-admin-token-please-change" \
  -F "in_broj=1234" \
  -F "naziv_knjige=Test" \
  -F "slika=@image.jpg"
```

---

## 🚀 REZULTATI

✅ **Knjige se prikazuju na homesajtu** sa slikama
✅ **Admin CRUD operacije zaštićene** sa middleware-om
✅ **Slike serviran kroz API route** (bez potrebe za symlink-om)
✅ **Modal za "Izmeni" prikazuje postojeću sliku** sa imenom fajla
✅ **Stranica detalja knjige** prikazuje slike ispravno

---

## ⚠️ PRE PRODUKCIJE

- [ ] Ukloniti dev token bypass iz `AdminMiddleware`
- [ ] Ukloniti debug route-e (`/api/debug-*`)
- [ ] Obrisati `config/dev.php`
- [ ] Implementirati pravi session auth ili token-based auth
- [ ] Postaviti `.env` promenljive za produkciju
- [ ] Validacija file upload-a (tip, veličina, virusni scan)
- [ ] Implementirati image resizing/optimization

---

## 📝 NAPOMENE

**Session Cookie Problem:**
- Knjige se vide jer je GET javno
- Admin CRUD vraćao 401 jer je session kreiran na `localhost` ali zahtev ide na `127.0.0.1`
- Rešenje: Dev token bypass za lokalno, pravi auth za produkciju

**Performanse:**
- Slike se serviran kroz PHP route (Store file returns)
- Za produkciju: Koristiti nginx X-Accel-Redirect ili Apache X-Sendfile

---

Dokumentovano **23.01.2026** ✅
